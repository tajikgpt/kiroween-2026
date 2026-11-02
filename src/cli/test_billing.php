<?php
/**
 * TajikGPT — проверка биллинга.
 *
 * Запуск:  php cli/test_billing.php
 *
 * Работает на временном каталоге данных, настоящие файлы не трогает.
 * Если хоть один тест упал — на продакшен выкладывать нельзя.
 */

declare(strict_types=1);

// Временный каталог данных до загрузки хранилища
$tmp = sys_get_temp_dir() . '/tajikgpt_test_' . getmypid();
putenv("DATA_DIR={$tmp}");
$_ENV['DATA_DIR'] = $tmp;

require_once __DIR__ . '/../lib/billing.php';
require_once __DIR__ . '/../lib/keys.php';

register_shutdown_function(function () use ($tmp) {
    if (is_dir($tmp)) {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

$passed = 0;
$failed = 0;

function check(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        printf("  ok    %-56s %s\n", $name, var_export($actual, true));
    } else {
        $failed++;
        printf("  FAIL  %-56s получено %s, ожидалось %s\n",
            $name, var_export($actual, true), var_export($expected, true));
    }
}

function check_true(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        printf("  ok    %-56s %s\n", $name, $detail);
    } else {
        $failed++;
        printf("  FAIL  %-56s %s\n", $name, $detail);
    }
}

$coder = sx_load_model('coder');
$fast  = sx_load_model('fast');
$free  = sx_load_model('free');
$max   = sx_load_model('max');

echo "\n=== Каталог загружается ===\n";
check_true('coder найдена',  $coder !== null);
check_true('fast найдена',   $fast !== null);
check_true('free найдена',   $free !== null);
check_true('max найдена',    $max !== null);
check('coder → апстрим',     $coder['upstream_id'], 'vendor/flash-coder');
check('coder коэффициент',   $coder['coef_milli'], 9000);
check('неизвестная модель',  sx_load_model('nope'), null);
// Модель отключённого провайдера не должна попадать в каталог:
// иначе клиент выберет её и получит отказ вместо ответа.
check_true('модель отключённого провайдера недоступна', sx_load_model('__none__') === null);

echo "\n=== Деление с округлением вверх ===\n";
check('ceil 0/1000',    sx_div_ceil(0, 1000),    0);
check('ceil 1/1000',    sx_div_ceil(1, 1000),    1);
check('ceil 1000/1000', sx_div_ceil(1000, 1000), 1);
check('ceil 1001/1000', sx_div_ceil(1001, 1000), 2);
check('делитель ноль',  sx_div_ceil(5, 0),       0);

echo "\n=== Списание ===\n";
check('coder 3000+700', sx_charge_tokens($coder, 3000, 700), 33300);
check('fast 3000+700',  sx_charge_tokens($fast, 3000, 700),  11100);
check('coder 1+0',      sx_charge_tokens($coder, 1, 0),      9);
check('нулевой запрос', sx_charge_tokens($coder, 0, 0),      0);
check('free бесплатна', sx_charge_tokens($free, 100000, 50000), 0);
// max: вход ×53, выход ×420 → 3000×53 + 700×420 = 159000 + 294000
check('max раздельный', sx_charge_tokens($max, 3000, 700), 453000);

echo "\n=== Себестоимость ===\n";
// Значения считаны по формуле sx_cost_micro() для цен из config/catalog.php:
//   coder  3000 × $0.12/1M + 700 × $0.60/1M = 360 + 420 = 780 мкд
//   fast   3000 × $0.04/1M + 700 × $0.20/1M = 120 + 140 = 260 мкд
// При подстановке своих закупочных цен эти два числа надо пересчитать.
check('coder cost',       sx_cost_micro($coder, 3000, 700),       780);
check('fast cost',        sx_cost_micro($fast, 3000, 700),        260);
check('coder cost + кэш', sx_cost_micro($coder, 3000, 700, 1000), 680);
check_true('кэш снижает закупку',
    sx_cost_micro($coder, 3000, 700, 1000) < sx_cost_micro($coder, 3000, 700),
    'скидку клиенту не передаём — это маржа');
// Кэшированных заявлено больше, чем всего входных. Берём min(): иначе
// отрицательный «свежий» вход вычел бы стоимость и запрос вышел дешевле
// бесплатного. 100 × $0.02/1M = 2 мкд.
check('кэш > входа не ломает', sx_cost_micro($coder, 100, 0, 9999), 2);

echo "\n=== Маржа ===\n";
$baseRate = sx_conf('base_rate_micro_per_m');
check('базовая ставка', $baseRate, 50000);

foreach ([['coder', $coder], ['fast', $fast], ['max', $max]] as [$n, $m]) {
    $charged = sx_charge_tokens($m, 3000, 700);
    $revenue = intdiv($charged * $baseRate, 1_000_000);
    $cost    = sx_cost_micro($m, 3000, 700);
    $pct     = round(($revenue - $cost) * 100 / $revenue, 1);
    printf("  %-10s списано %7d т  выручка %6d мкд  закупка %6d мкд  маржа %5.1f%%\n",
        $n, $charged, $revenue, $cost, $pct);
    check_true("{$n}: маржа не ниже 40%", $pct >= 40.0, "{$pct}%");
}

echo "\n=== Оценка входа ===\n";
$lat = sx_estimate_input_tokens([['role' => 'user', 'content' => str_repeat('hello world ', 100)]]);
$cyr = sx_estimate_input_tokens([['role' => 'user', 'content' => str_repeat('привет мир ', 100)]]);
printf("  латиница → %d т, кириллица → %d т\n", $lat, $cyr);
check_true('кириллица не ниже латиницы', $cyr >= $lat, 'иначе резерва не хватит на русских промптах');
check_true('оценка завышена', $lat >= 200, "{$lat} ≥ 200");

$img = sx_estimate_input_tokens([[
    'role' => 'user',
    'content' => [
        ['type' => 'text', 'text' => 'что на фото'],
        ['type' => 'image_url', 'image_url' => ['url' => 'https://e.com/a.png']],
    ],
]]);
check_true('картинка учтена', $img >= 1000, "{$img} ≥ 1000");

echo "\n=== Пакеты ===\n";
foreach (sx_catalog()['packages'] as $slug => $p) {
    $tokens = sx_package_tokens($slug);
    $real   = intdiv($tokens * 1000, $coder['coef_milli']);
    $in     = intdiv($real * 81, 100);
    $cost   = sx_cost_micro($coder, $in, $real - $in);
    $pct    = round(($p['price_micro'] - $cost) * 100 / $p['price_micro'], 1);
    printf("  %-9s \$%-7.2f → %15s т   закупка \$%-7.2f   маржа %5.1f%%\n",
        $p['title'], $p['price_micro'] / 1e6, number_format($tokens, 0, '.', ' '), $cost / 1e6, $pct);
    check_true("{$p['title']}: маржа не ниже 35%", $pct >= 35.0, "{$pct}%");
}

// =============================================================================
echo "\n=== Хранилище: пользователь и ключ ===\n";

$u = sx_user_create('test@tajikgpt.com', 'Тест');
check('пользователь создан', (int) $u['id'], 1);
check('баланс пустой',       (int) $u['balance_tokens'], 0);

// Повторное создание не дублирует
$u2 = sx_user_create('test@tajikgpt.com');
check('повторное создание возвращает того же', (int) $u2['id'], 1);

$key = sx_issue_key(1, 'main');
check_true('ключ имеет префикс', str_starts_with($key, 'sk-sx-'), $key);
check('длина ключа', strlen($key), 54);

$auth = sx_resolve_key($key);
check_true('ключ находится',  $auth !== null);
check('ключ → пользователь',  $auth['user']['id'], 1);

check('мусорный ключ',        sx_resolve_key('sk-sx-мусор'), null);
check('чужой префикс',        sx_resolve_key('sk-tj-' . str_repeat('a', 48)), null);
check('пустой ключ',          sx_resolve_key(''), null);

echo "\n=== Открытый ключ не хранится ===\n";
$found = [];
foreach (glob(sx_data_dir() . '/keys/*.json') ?: [] as $f) {
    if (str_contains((string) file_get_contents($f), substr($key, 6))) {
        $found[] = basename($f);
    }
}
check_true('ключа нет в файлах в открытом виде', $found === [],
    $found === [] ? 'в файле только sha256' : implode(',', $found));

echo "\n=== Пополнение идемпотентно ===\n";
check_true('первое начисление', sx_credit_tokens(1, 100_000_000, 'purchase', 'SX_ORDER_1', 'пакет Старт'));
check_true('повторное отклонено', !sx_credit_tokens(1, 100_000_000, 'purchase', 'SX_ORDER_1'),
    'повторный вебхук кассы не начислит второй раз');
check('баланс после пополнения', (int) sx_user_load(1)['balance_tokens'], 100_000_000);

echo "\n=== Резерв и расчёт ===\n";
$before = (int) sx_user_load(1)['balance_tokens'];
$rid    = sx_reserve(1, $auth['key']['hash'], $coder, 3000, 4096);

$afterReserve = sx_user_load(1);
$reserved     = (int) $afterReserve['reserved_tokens'];
printf("  зарезервировано %d т, доступно %d т\n", $reserved, $afterReserve['balance_tokens']);
check_true('резерв списан с баланса', (int) $afterReserve['balance_tokens'] === $before - $reserved);
check_true('резерв покрывает факт', $reserved >= sx_charge_tokens($coder, 3000, 700));

$charged = sx_settle($rid, $coder, 3000, 700, 0, 120, 1);
check('списано по факту', $charged, 33300);

$afterSettle = sx_user_load(1);
check('резерв снят',      (int) $afterSettle['reserved_tokens'], 0);
check('баланс корректен', (int) $afterSettle['balance_tokens'], $before - 33300);
check('учтён расход',     (int) $afterSettle['lifetime_spent'], 33300);

echo "\n=== Повторный расчёт ничего не меняет ===\n";
$b = (int) sx_user_load(1)['balance_tokens'];
check('повторный settle = 0', sx_settle($rid, $coder, 3000, 700, 0, 120, 1), 0);
check('баланс не изменился', (int) sx_user_load(1)['balance_tokens'], $b);

echo "\n=== Возврат при сбое провайдера ===\n";
$b    = (int) sx_user_load(1)['balance_tokens'];
$rid2 = sx_reserve(1, $auth['key']['hash'], $coder, 3000, 4096);
check_true('резерв взят', (int) sx_user_load(1)['balance_tokens'] < $b);
sx_release($rid2, $coder, 'upstream_unreachable', 1);
check('баланс восстановлен', (int) sx_user_load(1)['balance_tokens'], $b);
check('резервов не осталось', (int) sx_user_load(1)['reserved_tokens'], 0);

echo "\n=== Нехватка баланса ===\n";
$small = sx_user_create('small@tajikgpt.com');
sx_credit_tokens((int) $small['id'], 100, 'purchase', 'SX_SMALL');
$caught = false;
try {
    sx_reserve((int) $small['id'], null, $coder, 3000, 4096);
} catch (SxInsufficientTokens $e) {
    $caught = true;
    printf("  нужно %d т, доступно %d т\n", $e->required, $e->available);
}
check_true('запрос отклонён', $caught, 'иначе уйдём в минус на своей закупке');
check('баланс не тронут', (int) sx_user_load((int) $small['id'])['balance_tokens'], 100);

echo "\n=== Бесплатная модель требует баланса ===\n";
$zero = sx_user_create('zero@tajikgpt.com');
$caught = false;
try {
    sx_reserve((int) $zero['id'], null, $free, 100, 100);
} catch (SxInsufficientTokens $e) {
    $caught = true;
}
check_true('нулевой баланс отклонён', $caught, 'иначе регистрация = бесконечный бесплатный API');

sx_credit_tokens((int) $zero['id'], 5000, 'bonus', 'SX_WELCOME');
$ridFree = sx_reserve((int) $zero['id'], null, $free, 100, 100);
$bFree   = (int) sx_user_load((int) $zero['id'])['balance_tokens'];
check('резерв бесплатной = 0', $bFree, 5000);
check('списание бесплатной = 0', sx_settle($ridFree, $free, 500, 300, 0, 50, (int) $zero['id']), 0);
check('баланс не тронут', (int) sx_user_load((int) $zero['id'])['balance_tokens'], 5000);

echo "\n=== Уборка зависших резервов ===\n";
$rid3 = sx_reserve(1, $auth['key']['hash'], $coder, 3000, 4096);
$held = (int) sx_user_load(1)['reserved_tokens'];
check_true('резерв держится', $held > 0, "{$held} т");

// Подкручиваем время резерва в прошлое
$uu = sx_user_load(1, false);
$uu['resv'][$rid3]['at'] = time() - 700;   // ttl = 600
sx_user_save($uu);

$freed = sx_reap_stale_reservations(1);
printf("  возвращено %d т\n", $freed);
check('зависший возвращён', $freed, $held);
check('резервов не осталось', (int) sx_user_load(1)['reserved_tokens'], 0);

echo "\n=== Инвариант: баланс + резервы == сумма реестра ===\n";
/** Сумма всех записей реестра пользователя. */
function ledger_sum(int $uid): int
{
    $s = 0;
    foreach (sx_read_jsonl_from("ledger/{$uid}.jsonl", 0) as $r) {
        $s += (int) ($r['amount'] ?? 0);
    }
    return $s;
}

// Проверяем инвариант в трёх состояниях: спокойном, с открытым
// резервом и после расчёта. Если он держится — реестр и баланс
// никогда не разойдутся.
$inv = function (string $when) {
    $u   = sx_user_load(1, false);
    $lhs = (int) $u['balance_tokens'] + (int) $u['reserved_tokens'];
    $rhs = ledger_sum(1);
    printf("  %-22s баланс %d + резерв %d = %d, реестр = %d\n",
        $when, $u['balance_tokens'], $u['reserved_tokens'], $lhs, $rhs);
    check_true("инвариант: {$when}", $lhs === $rhs);
};

$inv('спокойное состояние');

$ridInv = sx_reserve(1, $auth['key']['hash'], $coder, 3000, 4096);
$inv('с открытым резервом');

sx_settle($ridInv, $coder, 2500, 600, 0, 90, 1);
$inv('после расчёта');

echo "\n=== Реестр как источник истины ===\n";
// Имитируем падение между дописью в реестр и записью баланса:
// откатываем ledger_offset, баланс должен досчитаться сам.
$uu = sx_user_load(1, false);
$realBalance = (int) $uu['balance_tokens'];
$uu['ledger_offset'] = 0;
$uu['balance_tokens'] = 0;
$uu['lifetime_purchased'] = 0;
$uu['lifetime_spent'] = 0;
sx_user_save($uu);

$restored = sx_user_load(1);
printf("  баланс до сбоя %d т, восстановлено %d т\n", $realBalance, $restored['balance_tokens']);
check('баланс восстановлен из реестра', (int) $restored['balance_tokens'], $realBalance);

echo "\n=== Частота запросов ===\n";
$lim = 5;
$blocked = 0;
for ($i = 1; $i <= 8; $i++) {
    $r = sx_rate_bump('t', 99, $lim);
    if (!$r['ok']) {
        $blocked++;
    }
}
check('лишние запросы отклонены', $blocked, 3);

echo "\n=== Отзыв ключа ===\n";
check_true('отозван', sx_revoke_key($auth['key']['hash'], 1));
check('отозванный не работает', sx_resolve_key($key), null);
check_true('повторный отзыв = false', !sx_revoke_key($auth['key']['hash'], 1));

echo "\n" . str_repeat('-', 76) . "\n";
printf("Пройдено: %d   Провалено: %d\n\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
