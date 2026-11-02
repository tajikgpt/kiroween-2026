<?php
/**
 * TajikGPT — биллинг токенов на файловом хранилище.
 *
 * Правила:
 *
 *   1. Только целая арифметика. Ни одного float в расчёте списаний.
 *   2. Округление вверх, но не больше чем на один токен.
 *   3. Любое изменение баланса идёт под блокировкой пользователя,
 *      взятой ДО чтения, и пишет запись в реестр.
 *   4. Резерв обязателен до обращения к провайдеру.
 *
 * Резервы лежат ВНУТРИ файла пользователя (поле resv). Поэтому резерв
 * и расчёт — это одна операция над одним файлом под одной блокировкой,
 * и они не могут разойтись между собой. Отдельные файлы на каждый резерв
 * потребовали бы двухфазной записи и съедали иноды.
 *
 * Жизнь запроса:
 *   sx_reap_stale_reservations()  вернуть зависшее с прошлых раз
 *   sx_reserve()                  заморозить максимум
 *   … запрос к провайдеру …
 *   sx_settle()                   списать факт, вернуть разницу
 *   либо sx_release()             вернуть всё, если провайдер упал
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

class SxInsufficientTokens extends RuntimeException
{
    public function __construct(
        public readonly int $required,
        public readonly int $available
    ) {
        parent::__construct('Недостаточно токенов на балансе');
    }
}

class SxKeyBudgetExceeded extends RuntimeException
{
}

// =============================================================================
// Каталог
// =============================================================================

function sx_catalog(): array
{
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/../config/catalog.php';
    }
    return $c;
}

function sx_conf(string $key, int $default = 0): int
{
    return (int) (sx_catalog()['settings'][$key] ?? $default);
}

/**
 * Модель вместе с провайдером по публичному ID.
 * Возвращает плоский массив — тот же вид, что ожидают остальные функции.
 */
function sx_load_model(string $publicId): ?array
{
    $cat = sx_catalog();
    $m   = $cat['models'][$publicId] ?? null;
    if ($m === null || !($m['active'] ?? false)) {
        return null;
    }

    $p = $cat['providers'][$m['provider']] ?? null;
    if ($p === null || !($p['active'] ?? false)) {
        return null;
    }

    return [
        'public_id'         => $publicId,
        'title'             => $m['title'],
        'description'       => $m['description'] ?? null,
        'upstream_id'       => $m['upstream'],
        'coef_milli'        => (int) $m['coef_milli'],
        'coef_in_milli'     => isset($m['coef_in_milli'])  ? (int) $m['coef_in_milli']  : null,
        'coef_out_milli'    => isset($m['coef_out_milli']) ? (int) $m['coef_out_milli'] : null,
        'cost_in_per_m'     => (int) ($m['cost_in_per_m'] ?? 0),
        'cost_out_per_m'    => (int) ($m['cost_out_per_m'] ?? 0),
        'cost_cached_per_m' => (int) ($m['cost_cached_per_m'] ?? 0),
        'context_window'    => (int) ($m['context'] ?? 128000),
        'max_tokens'        => (int) ($m['max_tokens'] ?? 4096),
        'capabilities'      => $m['caps'] ?? [],
        'fallback_id'       => $m['fallback'] ?? null,
        'featured'          => (bool) ($m['featured'] ?? false),
        'provider_slug'     => $m['provider'],
        'provider_base_url' => $p['base_url'],
        'provider_key_env'  => $p['key_env'],
        'daily_cap_micro'   => (int) ($p['daily_cap_micro'] ?? 0),
    ];
}

/** Публичный каталог для GET /v1/models. */
function sx_list_models(): array
{
    $cat      = sx_catalog();
    $baseRate = sx_conf('base_rate_micro_per_m', 50000);

    $rows = [];
    foreach ($cat['models'] as $id => $m) {
        if (!($m['active'] ?? false)) {
            continue;
        }
        $p = $cat['providers'][$m['provider']] ?? null;
        if ($p === null || !($p['active'] ?? false)) {
            continue;
        }

        $coefIn  = (int) ($m['coef_in_milli']  ?? $m['coef_milli']);
        $coefOut = (int) ($m['coef_out_milli'] ?? $m['coef_milli']);

        $rows[] = [
            'id'       => $id,
            'object'   => 'model',
            'created'  => 1762000000,
            'owned_by' => 'tajikgpt',
            'sort'     => (int) ($m['sort'] ?? 999),

            // Расширение вне спецификации OpenAI. Стандартные SDK его
            // игнорируют, витрина цен и кабинет читают отсюда.
            'tajikgpt'   => [
                'title'           => $m['title'],
                'description'     => $m['description'] ?? null,
                'coefficient'     => round((int) $m['coef_milli'] / 1000, 3),
                'price_per_m'     => round($coefIn  * $baseRate / 1000 / 1_000_000, 6),
                'price_out_per_m' => round($coefOut * $baseRate / 1000 / 1_000_000, 6),
                'context_window'  => (int) ($m['context'] ?? 128000),
                'max_tokens'      => (int) ($m['max_tokens'] ?? 4096),
                'capabilities'    => $m['caps'] ?? [],
                'featured'        => (bool) ($m['featured'] ?? false),
            ],
        ];
    }

    usort($rows, fn($a, $b) => $a['sort'] <=> $b['sort']);
    foreach ($rows as &$r) {
        unset($r['sort']);
    }

    return $rows;
}

// =============================================================================
// Расчёты
// =============================================================================

/** Делит с округлением вверх, целочисленно. */
function sx_div_ceil(int $num, int $den): int
{
    if ($den === 0) {
        return 0;
    }
    return intdiv($num + $den - 1, $den);
}

/**
 * Сколько списать с клиента, в токенах баланса.
 *
 * Раздельные коэффициенты нужны там, где выход у провайдера в разы
 * дороже входа: общий коэффициент на агентной нагрузке с длинными
 * ответами даёт убыток.
 */
function sx_charge_tokens(array $model, int $tokensIn, int $tokensOut): int
{
    $coefIn  = $model['coef_in_milli']  ?? null;
    $coefOut = $model['coef_out_milli'] ?? null;

    if ($coefIn !== null && $coefOut !== null) {
        return sx_div_ceil($tokensIn * (int) $coefIn, 1000)
             + sx_div_ceil($tokensOut * (int) $coefOut, 1000);
    }

    $coef = (int) $model['coef_milli'];
    if ($coef === 0) {
        return 0;
    }
    return sx_div_ceil(($tokensIn + $tokensOut) * $coef, 1000);
}

/**
 * Сколько запрос стоил НАМ, в микродолларах.
 *
 * $tokensCached входит в $tokensIn, поэтому вычитается из полной ставки.
 * Клиенту кэш-скидку не передаём — это заложенный источник маржи.
 */
function sx_cost_micro(array $model, int $tokensIn, int $tokensOut, int $tokensCached = 0): int
{
    $cached = max(0, min($tokensCached, $tokensIn));
    $fresh  = $tokensIn - $cached;

    return intdiv($fresh      * (int) $model['cost_in_per_m'],     1_000_000)
         + intdiv($cached     * (int) $model['cost_cached_per_m'], 1_000_000)
         + intdiv($tokensOut  * (int) $model['cost_out_per_m'],    1_000_000);
}

/**
 * Оценка входных токенов — только для размера резерва.
 *
 * Намеренно завышена: недооценка означает, что резерва не хватит на
 * фактическое списание. Кириллица в UTF-8 занимает 2 байта на символ
 * и токенизируется плотнее латиницы, поэтому берём максимум из двух оценок.
 */
function sx_estimate_input_tokens(array $messages, string $extra = ''): int
{
    $text = $extra;
    foreach ($messages as $m) {
        $c = $m['content'] ?? '';
        if (is_string($c)) {
            $text .= $c;
        } elseif (is_array($c)) {
            foreach ($c as $part) {
                if (($part['type'] ?? '') === 'text') {
                    $text .= $part['text'] ?? '';
                } else {
                    // изображение считаем блоком фиксированного размера
                    $text .= str_repeat('x', 4000);
                }
            }
        }
        $text .= str_repeat('x', 16);   // служебные поля сообщения
    }

    $byBytes = intdiv(strlen($text), 4) + 1;
    $byChars = intdiv((int) (mb_strlen($text) * 10), 25) + 1;   // ÷2.5

    return max($byBytes, $byChars, 1);
}

// =============================================================================
// Резервирование
// =============================================================================

function sx_new_request_id(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Вернуть зависшие резервы вместо крона.
 *
 * На шаред-хостинге крон редкий, а резерв, оставшийся после оборванного
 * соединения, иначе заморозил бы токены клиента навсегда. Клиент,
 * вернувшийся за новым запросом, сам разморозит свои прошлые.
 */
function sx_reap_stale_reservations(int $userId): int
{
    $ttl = sx_conf('reservation_ttl', 600);

    return sx_with_lock("user_{$userId}", function () use ($userId, $ttl): int {
        $u = sx_user_load($userId);
        if ($u === null || $u['resv'] === []) {
            return 0;
        }

        $cutoff  = time() - $ttl;
        $freed   = 0;
        $dropped = 0;

        foreach ($u['resv'] as $rid => $r) {
            if ((int) ($r['at'] ?? 0) < $cutoff) {
                $freed += (int) ($r['t'] ?? 0);
                unset($u['resv'][$rid]);
                $dropped++;
            }
        }

        if ($dropped === 0) {
            return 0;
        }

        // В реестр это НЕ пишется. Резерв — временное состояние, а не
        // движение денег: эти токены никогда не были списаны. Запись о
        // возврате раздувала бы реестр, и восстановленный из него баланс
        // оказался бы выше настоящего.
        //
        // Отсюда инвариант, который проверяется в тестах:
        //     баланс + резервы == сумма реестра
        $u['reserved_tokens'] = max(0, (int) $u['reserved_tokens'] - $freed);
        $u['balance_tokens']  = (int) $u['balance_tokens'] + $freed;

        sx_user_save($u);

        error_log("[tajikgpt] пользователю {$userId} возвращено {$freed} т из {$dropped} зависших резервов");

        return $freed;
    });
}

/**
 * Заморозить максимально возможную стоимость запроса.
 *
 * Чтение, проверка и запись происходят под одной блокировкой, взятой
 * до чтения. Два параллельных запроса одним ключом выполнятся по очереди,
 * и ни одно списание не пропадёт.
 *
 * @throws SxInsufficientTokens|SxKeyBudgetExceeded
 */
function sx_reserve(
    int $userId,
    ?string $keyHash,
    array $model,
    int $estInputTokens,
    int $maxOutputTokens,
    ?int $keyBudgetLeft = null
): string {
    $margin = sx_conf('reserve_margin_pct', 15);
    $estIn  = $estInputTokens + intdiv($estInputTokens * $margin, 100);

    // Под выход резервируем долю от max_tokens, а не весь лимит.
    // Полный max_tokens означал, что клиенту нужен баланс под теоретический
    // максимум: агент с max_tokens 64000 на модели ×9 требовал 678 000
    // токенов резерва при реальной стоимости ответа около тысячи.
    $outPct   = sx_conf('reserve_out_pct', 25);
    $outFloor = sx_conf('reserve_out_floor', 2048);
    $estOut   = min($maxOutputTokens, max($outFloor, intdiv($maxOutputTokens * $outPct, 100)));

    $reserve = sx_charge_tokens($model, $estIn, $estOut);

    $requestId = sx_new_request_id();

    return sx_with_lock("user_{$userId}", function () use (
        $userId, $keyHash, $model, $reserve, $requestId, $keyBudgetLeft
    ): string {
        $u = sx_user_load($userId);
        if ($u === null) {
            throw new RuntimeException("Пользователь {$userId} не найден");
        }
        if ($u['is_blocked']) {
            throw new RuntimeException('Аккаунт заблокирован');
        }

        $balance = (int) $u['balance_tokens'];

        // Бесплатная модель: списывать нечего, но требуем ненулевой баланс.
        // Иначе регистрация превращается в бесконечный бесплатный API.
        if ($reserve === 0) {
            $minBal = sx_conf('free_min_balance', 1000);
            if ($balance < $minBal) {
                throw new SxInsufficientTokens($minBal, $balance);
            }
        } else {
            if ($balance < $reserve) {
                throw new SxInsufficientTokens($reserve, $balance);
            }
            if ($keyBudgetLeft !== null && $keyBudgetLeft < $reserve) {
                throw new SxKeyBudgetExceeded(
                    "Бюджет ключа исчерпан: осталось {$keyBudgetLeft}, нужно {$reserve}"
                );
            }

            $u['balance_tokens']  = $balance - $reserve;
            $u['reserved_tokens'] = (int) $u['reserved_tokens'] + $reserve;
        }

        $u['resv'][$requestId] = [
            't'  => $reserve,
            'at' => time(),
            'm'  => $model['public_id'],
            // Хеш ключа, а не его числовой id: файл ключа называется
            // хешем, и расчёт должен уметь обновить его без перебора.
            'kh' => $keyHash,
        ];

        sx_user_save($u);
        return $requestId;
    });
}

// =============================================================================
// Расчёт по факту
// =============================================================================

/**
 * Списать фактическую стоимость, вернуть неиспользованный резерв.
 *
 * @return int сколько списано
 */
function sx_settle(
    string $requestId,
    array $model,
    int $tokensIn,
    int $tokensOut,
    int $tokensCached = 0,
    ?int $latencyMs = null,
    ?int $userId = null
): int {
    $charged = sx_charge_tokens($model, $tokensIn, $tokensOut);
    $cost    = sx_cost_micro($model, $tokensIn, $tokensOut, $tokensCached);

    if ($userId === null) {
        return 0;
    }

    $actual = sx_with_lock("user_{$userId}", function () use (
        $userId, $requestId, $model, $charged, $tokensIn, $tokensOut, $tokensCached, $latencyMs
    ): int {
        $u = sx_user_load($userId);
        if ($u === null) {
            return 0;
        }

        $resv = $u['resv'][$requestId] ?? null;
        if ($resv === null) {
            // Резерв уже закрыт — повторный вызов. Расчёт идемпотентен.
            return 0;
        }

        $reserved = (int) ($resv['t'] ?? 0);
        $keyHash  = $resv['kh'] ?? null;

        // Сначала возвращаем резерв на баланс ЦЕЛИКОМ и закрываем его.
        // Списание делает только sx_ledger_apply ниже — иначе факт
        // вычитался бы дважды: здесь и в реестре.
        $u['reserved_tokens'] = max(0, (int) $u['reserved_tokens'] - $reserved);
        $u['balance_tokens']  = (int) $u['balance_tokens'] + $reserved;
        unset($u['resv'][$requestId]);

        // Провайдер может вернуть больше токенов, чем мы заложили в резерв
        // (например проигнорировал max_tokens). Доплату берём с баланса,
        // но в минус не уходим: недобор пишем в лог и считаем своим
        // убытком, а не долгом клиента.
        $actual    = min($charged, (int) $u['balance_tokens']);
        $shortfall = $charged - $actual;

        if ($actual > 0) {
            sx_ledger_apply($u, 'charge', -$actual, $requestId, (string) $model['public_id']);
        }

        sx_user_save($u);

        sx_usage_write($userId, [
            'rid'      => $requestId,
            'model'    => $model['public_id'],
            'provider' => $model['provider_slug'],
            'upstream' => $model['upstream_id'],
            'in'       => $tokensIn,
            'out'      => $tokensOut,
            'cached'   => $tokensCached,
            'charged'  => $actual,
            'cost'     => sx_cost_micro($model, $tokensIn, $tokensOut, $tokensCached),
            'ms'       => $latencyMs,
            'key'      => $keyHash !== null ? substr((string) $keyHash, 0, 12) : null,
            'status'   => 'ok',
        ]);

        if ($keyHash !== null) {
            sx_key_add_spent((string) $keyHash, $actual);
        }

        if ($shortfall > 0) {
            error_log(sprintf(
                '[tajikgpt] недобор списания: запрос %s, модель %s, нужно %d, списано %d',
                $requestId, $model['public_id'], $charged, $actual
            ));
        }

        return $actual;
    });

    sx_provider_spend_add((string) $model['provider_slug'], $cost);

    return $actual;
}

/**
 * Полный возврат резерва: провайдер не ответил или вернул ошибку.
 * Клиент не платит за чужой сбой.
 */
function sx_release(
    string $requestId,
    array $model,
    string $errorCode = 'upstream_error',
    ?int $userId = null
): void {
    if ($userId === null) {
        return;
    }

    sx_with_lock("user_{$userId}", function () use ($userId, $requestId, $model, $errorCode): void {
        $u = sx_user_load($userId);
        if ($u === null) {
            return;
        }

        $resv = $u['resv'][$requestId] ?? null;
        if ($resv === null) {
            return;
        }

        $reserved = (int) ($resv['t'] ?? 0);
        $keyHash  = $resv['kh'] ?? null;

        $u['reserved_tokens'] = max(0, (int) $u['reserved_tokens'] - $reserved);
        $u['balance_tokens']  = (int) $u['balance_tokens'] + $reserved;
        unset($u['resv'][$requestId]);

        sx_user_save($u);

        sx_usage_write($userId, [
            'rid'      => $requestId,
            'model'    => $model['public_id'],
            'provider' => $model['provider_slug'],
            'in' => 0, 'out' => 0, 'charged' => 0, 'cost' => 0,
            'key'      => $keyHash !== null ? substr((string) $keyHash, 0, 12) : null,
            'status'   => 'failed',
            'error'    => $errorCode,
        ]);
    });
}

/** Не превышен ли дневной лимит закупки у провайдера. */
function sx_provider_within_cap(array $model): bool
{
    $cap = (int) ($model['daily_cap_micro'] ?? 0);
    if ($cap <= 0) {
        return true;
    }
    return sx_provider_spend_today((string) $model['provider_slug']) < $cap;
}

// =============================================================================
// Пополнение
// =============================================================================

/**
 * Зачислить купленные токены.
 *
 * Идемпотентно по $reference: повторный вебхук кассы не начислит второй
 * раз. Проверка идёт по реестру, который пишется до баланса, поэтому
 * обрыв между двумя записями не приводит к двойному начислению.
 */
function sx_credit_tokens(
    int $userId,
    int $tokens,
    string $type,
    string $reference,
    string $description = ''
): bool {
    if ($tokens <= 0) {
        return false;
    }

    return sx_with_lock("user_{$userId}", function () use (
        $userId, $tokens, $type, $reference, $description
    ): bool {
        if (sx_ledger_has_ref($userId, $reference, $type)) {
            return false;   // уже начисляли
        }

        $u = sx_user_load($userId);
        if ($u === null) {
            return false;
        }

        sx_ledger_apply($u, $type, $tokens, $reference, $description);
        sx_user_save($u);

        return true;
    });
}

/** Сколько токенов даёт пакет с учётом бонуса. */
function sx_package_tokens(string $slug): ?int
{
    $p = sx_catalog()['packages'][$slug] ?? null;
    if ($p === null) {
        return null;
    }
    $base = (int) $p['base_tokens'];
    return $base + intdiv($base * (int) ($p['bonus'] ?? 0), 100);
}
