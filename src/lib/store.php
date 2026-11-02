<?php
/**
 * TajikGPT — хранилище на файлах.
 *
 * Без MySQL, но с гарантиями, которых у flat-file обычно нет.
 *
 * -----------------------------------------------------------------------------
 * ЧЕМ ЭТО ОТЛИЧАЕТСЯ ОТ tajikgpt
 * -----------------------------------------------------------------------------
 *
 * Там (config.php:743-785) списание выглядит так:
 *
 *     $lines = file(USERS_FILE, ...);          // читаем
 *     ... $u["package_balance"] = $balance - $amount;
 *     file_put_contents(USERS_FILE, ..., LOCK_EX);   // пишем
 *
 * LOCK_EX стоит только на записи, а между чтением и записью окна нет
 * никакой защиты. Два одновременных запроса одним ключом прочитают
 * одинаковый баланс и запишут одинаковый результат — одно списание
 * пропадёт. На продаже токенов это прямой убыток.
 *
 * Здесь иначе:
 *
 *   1. ОДИН ФАЙЛ НА СУЩНОСТЬ. Не общий users.txt, а data/users/{id}.json.
 *      Блокировка получается на одного пользователя, а не на всю базу,
 *      поэтому разные клиенты друг друга не ждут.
 *
 *   2. БЛОКИРОВКА ДО ЧТЕНИЯ. flock(LOCK_EX) на отдельном lock-файле,
 *      внутри — чтение, изменение и запись. Гонка невозможна.
 *      Lock-файл отдельный намеренно: атомарная запись делается через
 *      rename(), а он подменяет inode, и блокировка на самом файле
 *      данных после подмены относилась бы к старому inode.
 *
 *   3. АТОМАРНАЯ ЗАПИСЬ. Временный файл + rename(). Прерывание питания
 *      посередине оставит старую версию, а не обрезанный JSON.
 *
 *   4. РЕЕСТР — ИСТОЧНИК ИСТИНЫ. Каждое движение токенов дописывается
 *      в ledger/{id}.jsonl ДО обновления баланса, и баланс помнит, до
 *      какого байта реестра он учтён. Если процесс умрёт между двумя
 *      записями, следующее чтение досчитает хвост реестра само.
 *      Баланс здесь — кэш, восстановимый из реестра.
 *
 * -----------------------------------------------------------------------------
 * РАСКЛАДКА
 * -----------------------------------------------------------------------------
 *
 *   data/
 *     users/{id}.json           баланс, резервы, счётчики
 *     keys/{sha256}.json        → user_id; имя файла и есть индекс, поиск O(1)
 *     ledger/{id}.jsonl         движение токенов, только дописывание
 *     usage/{id}-{YYYY-MM}.jsonl расход по запросам, только дописывание
 *     orders/{order_id}.json    заказы на пополнение
 *     rate/{key_id}.json        счётчики частоты
 *     locks/*.lock              блокировки
 *     seq.json                  выдача числовых id
 *
 * Расход инодов: примерно 5 файлов на пользователя. Десять тысяч
 * пользователей — пятьдесят тысяч инодов. На shared-хостинге лимит
 * инодов упирается раньше, чем место на диске, поэтому считать их надо
 * заранее, а не после жалобы на «превышение квоты».
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';
// sx_client_ip() нужна при создании заказа на пополнение
require_once __DIR__ . '/http.php';

// Каталог данных ВЫШЕ веб-корня: иначе JSON с балансами и хешами
// ключей скачивается по прямой ссылке.
function sx_data_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $dir = rtrim(sx_env('DATA_DIR', dirname(__DIR__) . '/data'), '/');

    foreach (['', '/users', '/keys', '/ledger', '/usage', '/orders', '/rate', '/locks', '/otp'] as $sub) {
        $p = $dir . $sub;
        if (!is_dir($p)) {
            @mkdir($p, 0700, true);
        }
    }

    // Вторая линия защиты, если каталог всё-таки окажется под веб-корнем
    $guard = $dir . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\nOptions -Indexes\n");
    }

    return $dir;
}

function sx_path(string $rel): string
{
    return sx_data_dir() . '/' . ltrim($rel, '/');
}

// =============================================================================
// Блокировки
// =============================================================================

/**
 * Выполнить $fn под исключительной блокировкой.
 *
 * Блокировка берётся ДО того, как $fn прочитает данные, и снимается
 * после записи. Именно это отсутствует в tajikgpt.
 *
 * Вложенный вызов с тем же именем не берёт блокировку второй раз:
 * flock на том же процессе прошёл бы, но логика стала бы запутанной.
 */
function sx_with_lock(string $name, callable $fn)
{
    static $held = [];

    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?? 'x';

    if (isset($held[$name])) {
        return $fn();
    }

    $file = sx_path("locks/{$name}.lock");
    $fh   = @fopen($file, 'c');
    if ($fh === false) {
        throw new RuntimeException("Не удалось открыть блокировку {$name}");
    }

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException("Не удалось захватить блокировку {$name}");
    }

    $held[$name] = true;
    try {
        return $fn();
    } finally {
        unset($held[$name]);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

// =============================================================================
// Чтение и запись
// =============================================================================

function sx_read_json(string $rel, ?array $default = null): ?array
{
    $file = sx_path($rel);
    if (!is_file($file)) {
        return $default;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

/**
 * Атомарная запись: временный файл рядом, затем rename().
 *
 * rename() внутри одной файловой системы атомарен, поэтому читатель
 * видит либо прежнюю версию целиком, либо новую целиком. Прямая запись
 * в тот же файл оставила бы обрезанный JSON при обрыве.
 */
function sx_write_json(string $rel, array $data): void
{
    $file = sx_path($rel);
    $dir  = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $tmp = $file . '.' . getmypid() . '.tmp';
    $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($raw === false) {
        throw new RuntimeException("Не удалось сериализовать {$rel}");
    }

    if (@file_put_contents($tmp, $raw) === false) {
        throw new RuntimeException("Не удалось записать {$rel}");
    }
    @chmod($tmp, 0600);

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException("Не удалось подменить {$rel}");
    }
}

/**
 * Дописать строку в журнал.
 *
 * Запись с LOCK_EX и флагом O_APPEND атомарна для строк разумной длины:
 * ядро не перемешает две одновременные дописки.
 *
 * @return int смещение в байтах ПОСЛЕ дописанной строки
 */
function sx_append_jsonl(string $rel, array $record): int
{
    $file = sx_path($rel);
    $dir  = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException("Не удалось дописать {$rel}");
    }
    @chmod($file, 0600);

    clearstatcache(true, $file);
    return (int) @filesize($file);
}

/** Прочитать журнал начиная с байтового смещения. */
function sx_read_jsonl_from(string $rel, int $offset): array
{
    $file = sx_path($rel);
    if (!is_file($file)) {
        return [];
    }
    clearstatcache(true, $file);
    $size = (int) @filesize($file);
    if ($offset >= $size) {
        return [];
    }

    $fh = @fopen($file, 'rb');
    if ($fh === false) {
        return [];
    }
    @fseek($fh, $offset);

    $out = [];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $rec = json_decode($line, true);
        if (is_array($rec)) {
            $out[] = $rec;
        }
    }
    fclose($fh);

    return $out;
}

/** Последние $limit записей журнала. */
function sx_tail_jsonl(string $rel, int $limit = 100): array
{
    $file = sx_path($rel);
    if (!is_file($file)) {
        return [];
    }

    // Читаем с конца блоками, чтобы не грузить в память весь журнал
    $fh = @fopen($file, 'rb');
    if ($fh === false) {
        return [];
    }

    $chunk = 8192;
    $pos   = (int) @filesize($file);
    $buf   = '';
    $lines = [];

    while ($pos > 0 && count($lines) <= $limit) {
        $read = min($chunk, $pos);
        $pos -= $read;
        fseek($fh, $pos);
        $buf = (string) fread($fh, $read) . $buf;
        $lines = array_values(array_filter(explode("\n", $buf), fn($l) => trim($l) !== ''));
    }
    fclose($fh);

    $lines = array_slice($lines, -$limit);

    $out = [];
    foreach ($lines as $l) {
        $rec = json_decode(trim($l), true);
        if (is_array($rec)) {
            $out[] = $rec;
        }
    }
    return $out;
}

// =============================================================================
// Числовые идентификаторы
// =============================================================================

function sx_next_id(string $kind): int
{
    return sx_with_lock('seq', function () use ($kind): int {
        $seq = sx_read_json('seq.json', []) ?? [];
        $next = (int) ($seq[$kind] ?? 0) + 1;
        $seq[$kind] = $next;
        sx_write_json('seq.json', $seq);
        return $next;
    });
}

// =============================================================================
// Пользователи
// =============================================================================

function sx_user_file(int $id): string
{
    return "users/{$id}.json";
}

function sx_user_blank(int $id, string $email): array
{
    return [
        'id'                 => $id,
        'email'              => $email,
        'name'               => null,
        'balance_tokens'     => 0,
        'reserved_tokens'    => 0,
        'lifetime_purchased' => 0,
        'lifetime_spent'     => 0,
        // Открытые резервы: request_id => {t: токены, at: время, m: модель, k: key_id}
        'resv'               => [],
        // До какого байта реестра учтён баланс. Ключ к самовосстановлению.
        'ledger_offset'      => 0,
        'role'               => 'user',
        'is_blocked'         => false,
        'lang'               => 'ru',
        'created_at'         => gmdate('c'),
        'last_login_at'      => null,
    ];
}

/**
 * Загрузить пользователя, досчитав непримененный хвост реестра.
 *
 * Если процесс умер между дописыванием в реестр и записью баланса,
 * здесь это чинится само: читаем реестр с ledger_offset и применяем.
 * Вызывать ТОЛЬКО внутри sx_with_lock("user_{id}") при изменениях.
 */
function sx_user_load(int $id, bool $reconcile = true): ?array
{
    $u = sx_read_json(sx_user_file($id));
    if ($u === null) {
        return null;
    }

    $u['resv'] = is_array($u['resv'] ?? null) ? $u['resv'] : [];

    if (!$reconcile) {
        return $u;
    }

    $offset  = (int) ($u['ledger_offset'] ?? 0);
    $pending = sx_read_jsonl_from("ledger/{$id}.jsonl", $offset);
    if ($pending === []) {
        return $u;
    }

    // Баланс отстал от реестра — догоняем
    $applied = 0;
    foreach ($pending as $rec) {
        $u['balance_tokens'] += (int) ($rec['amount'] ?? 0);
        if (($rec['type'] ?? '') === 'purchase' || ($rec['type'] ?? '') === 'bonus') {
            $u['lifetime_purchased'] += (int) ($rec['amount'] ?? 0);
        }
        if (($rec['type'] ?? '') === 'charge') {
            $u['lifetime_spent'] += abs((int) ($rec['amount'] ?? 0));
        }
        $applied++;
    }

    $u['balance_tokens'] = max(0, (int) $u['balance_tokens']);
    $u['ledger_offset']  = (int) @filesize(sx_path("ledger/{$id}.jsonl"));

    error_log("[tajikgpt] баланс пользователя {$id} досчитан по реестру: применено {$applied} записей");
    sx_write_json(sx_user_file($id), $u);

    return $u;
}

function sx_user_save(array $u): void
{
    sx_write_json(sx_user_file((int) $u['id']), $u);
}

/** Поиск по email: индексный файл, чтобы не перебирать каталог. */
function sx_user_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    $idx   = sx_read_json('users/_by_email.json', []) ?? [];
    $id    = $idx[$email] ?? null;
    return $id !== null ? sx_user_load((int) $id) : null;
}

function sx_user_create(string $email, ?string $name = null): array
{
    $email = strtolower(trim($email));

    return sx_with_lock('users_index', function () use ($email, $name): array {
        $idx = sx_read_json('users/_by_email.json', []) ?? [];
        if (isset($idx[$email])) {
            return sx_user_load((int) $idx[$email]);
        }

        $id = sx_next_id('user');
        $u  = sx_user_blank($id, $email);
        $u['name'] = $name;

        sx_user_save($u);

        $idx[$email] = $id;
        sx_write_json('users/_by_email.json', $idx);

        return $u;
    });
}

/**
 * Записать движение токенов и обновить баланс.
 *
 * Порядок важен: сначала дописка в реестр, потом баланс. Если процесс
 * умрёт между ними, sx_user_load() досчитает хвост. Обратный порядок
 * привёл бы к списанию без записи в реестре — нечем крыть спор с клиентом,
 * а для пополнений ещё и к двойному начислению, потому что проверка
 * повторов идёт по реестру.
 *
 * Вызывать внутри sx_with_lock("user_{id}").
 */
function sx_ledger_apply(array &$u, string $type, int $amount, ?string $ref, string $desc = ''): void
{
    $id = (int) $u['id'];

    $offset = sx_append_jsonl("ledger/{$id}.jsonl", [
        'ts'     => gmdate('c'),
        'type'   => $type,
        'amount' => $amount,
        'ref'    => $ref,
        'desc'   => $desc,
        'after'  => (int) $u['balance_tokens'] + $amount,
    ]);

    $u['balance_tokens'] = max(0, (int) $u['balance_tokens'] + $amount);
    $u['ledger_offset']  = $offset;

    if ($type === 'purchase' || $type === 'bonus') {
        $u['lifetime_purchased'] = (int) $u['lifetime_purchased'] + $amount;
    } elseif ($type === 'charge') {
        $u['lifetime_spent'] = (int) $u['lifetime_spent'] + abs($amount);
    }
}

/** Есть ли в реестре запись с такой ссылкой — защита от повторного начисления. */
function sx_ledger_has_ref(int $userId, string $ref, string $type): bool
{
    foreach (sx_read_jsonl_from("ledger/{$userId}.jsonl", 0) as $rec) {
        if (($rec['ref'] ?? null) === $ref && ($rec['type'] ?? '') === $type) {
            return true;
        }
    }
    return false;
}

// =============================================================================
// Расход
// =============================================================================

function sx_usage_write(int $userId, array $record): void
{
    $record['ts'] = gmdate('c');
    sx_append_jsonl("usage/{$userId}-" . gmdate('Y-m') . '.jsonl', $record);
}

function sx_usage_recent(int $userId, int $limit = 100): array
{
    $months = [gmdate('Y-m'), gmdate('Y-m', strtotime('-1 month'))];
    $out    = [];
    foreach ($months as $m) {
        foreach (sx_tail_jsonl("usage/{$userId}-{$m}.jsonl", $limit) as $r) {
            $out[] = $r;
        }
    }
    usort($out, fn($a, $b) => strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? '')));
    return array_slice($out, 0, $limit);
}

// =============================================================================
// Частота запросов
// =============================================================================

function sx_rate_bump(string $scope, int $id, int $limit): array
{
    $minute = gmdate('YmdHi');
    $rel    = "rate/{$scope}-{$id}.json";

    return sx_with_lock("rate_{$scope}_{$id}", function () use ($rel, $minute, $limit): array {
        $d = sx_read_json($rel, []) ?? [];
        if (($d['m'] ?? '') !== $minute) {
            $d = ['m' => $minute, 'n' => 0];
        }
        $d['n'] = (int) $d['n'] + 1;
        sx_write_json($rel, $d);

        return [
            'ok'       => $d['n'] <= $limit,
            'hits'     => (int) $d['n'],
            'limit'    => $limit,
            'reset_in' => 60 - (int) gmdate('s'),
        ];
    });
}

// =============================================================================
// Расход по ключу
// =============================================================================

/**
 * Учесть расход по ключу.
 *
 * Адресуем по хешу, а не по числовому id: файл называется хешем, поэтому
 * по id пришлось бы перебирать каталог. Числовой id нужен только для
 * показа в кабинете, и именно хеш кладётся в резерв запроса.
 *
 * Функция здесь, а не в keys.php, потому что её вызывает sx_settle()
 * из billing.php — иначе получилась бы взаимная зависимость файлов.
 */
function sx_key_add_spent(string $hash, int $tokens): void
{
    if ($tokens <= 0) {
        return;
    }
    sx_with_lock("key_{$hash}", function () use ($hash, $tokens): void {
        $rel = "keys/{$hash}.json";
        $k   = sx_read_json($rel);
        if ($k === null) {
            return;
        }
        $k['spent_tokens'] = (int) ($k['spent_tokens'] ?? 0) + $tokens;
        $k['last_used_at'] = gmdate('c');
        sx_write_json($rel, $k);
    });
}

// =============================================================================
// Дневной расход по провайдеру
// =============================================================================

function sx_provider_spend_add(string $slug, int $costMicro): void
{
    sx_with_lock("prov_{$slug}", function () use ($slug, $costMicro): void {
        $rel   = "rate/prov-{$slug}.json";
        $today = gmdate('Y-m-d');
        $d     = sx_read_json($rel, []) ?? [];
        if (($d['d'] ?? '') !== $today) {
            $d = ['d' => $today, 'spent' => 0];
        }
        $d['spent'] = (int) $d['spent'] + $costMicro;
        sx_write_json($rel, $d);
    });
}

function sx_provider_spend_today(string $slug): int
{
    $d = sx_read_json("rate/prov-{$slug}.json", []) ?? [];
    return ($d['d'] ?? '') === gmdate('Y-m-d') ? (int) $d['spent'] : 0;
}

// =============================================================================
// Заказы на пополнение
// =============================================================================

/**
 * Создать заказ. Идентификатор с префиксом SX_ — по нему касса отличает
 * наши заказы от чужих в общем потоке уведомлений.
 */
function sx_order_create(int $userId, string $packageSlug, int $priceMicro, int $tokens): array
{
    $orderId = 'SX_' . strtoupper(bin2hex(random_bytes(6)));

    $order = [
        'order_id'     => $orderId,
        'user_id'      => $userId,
        'package'      => $packageSlug,
        'price_micro'  => $priceMicro,
        'tokens'       => $tokens,
        'status'       => 'pending',
        'gateway'      => 'lava',
        'gateway_ref'  => null,
        'amount_rub'   => null,
        'created_at'   => gmdate('c'),
        'paid_at'      => null,
        'ip'           => sx_client_ip(),
    ];

    sx_write_json("orders/{$orderId}.json", $order);
    return $order;
}

function sx_order_load(string $orderId): ?array
{
    if (!preg_match('/^SX_[A-Z0-9]{4,32}$/', $orderId)) {
        return null;
    }
    return sx_read_json("orders/{$orderId}.json");
}

function sx_order_save(array $order): void
{
    sx_write_json("orders/{$order['order_id']}.json", $order);
}

/** Заказы пользователя, свежие сверху. */
function sx_orders_of(int $userId, int $limit = 20): array
{
    $out = [];
    foreach (glob(sx_path('orders/SX_*.json')) ?: [] as $f) {
        $o = json_decode((string) @file_get_contents($f), true);
        if (is_array($o) && (int) ($o['user_id'] ?? 0) === $userId) {
            $out[] = $o;
        }
    }
    usort($out, fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    return array_slice($out, 0, $limit);
}
