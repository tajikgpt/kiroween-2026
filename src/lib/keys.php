<?php
/**
 * TajikGPT — ключи API на файловом хранилище.
 *
 * Имя файла — это и есть индекс: data/keys/{sha256}.json. Поиск ключа
 * получается одним обращением к файловой системе, без перебора.
 *
 * В tajikgpt (api.php:139-180) ключ искали так: читали весь users.txt,
 * разбирали каждую строку как JSON и строили массив по api_key — и так
 * на КАЖДЫЙ запрос. На тысяче пользователей это тысяча json_decode
 * перед каждым ответом.
 *
 * Открытый ключ не хранится. В файле только sha256 в имени и первые
 * символы для показа в кабинете. В tajikgpt ключи лежали открытым
 * текстом в users.txt: один слитый бэкап — и все ключи клиентов у чужих.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

const SX_KEY_PREFIX = 'sk-sx-';

/** Новый ключ. Открытое значение показывается клиенту один раз. */
function sx_generate_key(): string
{
    return SX_KEY_PREFIX . bin2hex(random_bytes(24));
}

function sx_key_hash(string $key): string
{
    return hash('sha256', $key);
}

/** Первые символы для кабинета: sk-sx-a1b2c3d4… */
function sx_key_prefix(string $key): string
{
    return substr($key, 0, 14);
}

/**
 * Выдать ключ пользователю.
 *
 * @return string открытое значение; больше его взять будет негде
 */
function sx_issue_key(
    int $userId,
    string $name = 'default',
    ?int $budgetTokens = null,
    ?array $allowedModels = null
): string {
    $key  = sx_generate_key();
    $hash = sx_key_hash($key);

    sx_write_json("keys/{$hash}.json", [
        'id'             => sx_next_id('key'),
        'user_id'        => $userId,
        'name'           => mb_substr($name, 0, 100),
        'prefix'         => sx_key_prefix($key),
        'budget_tokens'  => $budgetTokens,
        'spent_tokens'   => 0,
        'allowed_models' => $allowedModels,
        'last_used_at'   => null,
        'revoked_at'     => null,
        'created_at'     => gmdate('c'),
    ]);

    // Список ключей пользователя — для кабинета. Хеши здесь нужны,
    // чтобы показать список и дать отозвать; сам ключ по хешу не восстановить.
    sx_with_lock("userkeys_{$userId}", function () use ($userId, $hash): void {
        $rel  = "keys/_user-{$userId}.json";
        $list = sx_read_json($rel, []) ?? [];
        $list[] = $hash;
        sx_write_json($rel, $list);
    });

    return $key;
}

/**
 * Проверить ключ и вернуть связку ключ + пользователь.
 *
 * @return array{key: array, user: array}|null
 */
function sx_resolve_key(string $rawKey): ?array
{
    $rawKey = trim($rawKey);
    if ($rawKey === '' || !str_starts_with($rawKey, SX_KEY_PREFIX)) {
        return null;
    }

    // Длину проверяем до обращения к диску: мусорные строки не должны
    // создавать файловых операций.
    if (strlen($rawKey) !== strlen(SX_KEY_PREFIX) + 48) {
        return null;
    }

    $hash = sx_key_hash($rawKey);
    $k    = sx_read_json("keys/{$hash}.json");

    if ($k === null || ($k['revoked_at'] ?? null) !== null) {
        return null;
    }

    $u = sx_user_load((int) $k['user_id']);
    if ($u === null) {
        return null;
    }

    return [
        'key' => [
            'id'             => (int) $k['id'],
            'hash'           => $hash,
            'name'           => (string) ($k['name'] ?? 'default'),
            'budget_tokens'  => isset($k['budget_tokens']) && $k['budget_tokens'] !== null
                ? (int) $k['budget_tokens'] : null,
            'spent_tokens'   => (int) ($k['spent_tokens'] ?? 0),
            'allowed_models' => $k['allowed_models'] ?? null,
        ],
        'user' => [
            'id'              => (int) $u['id'],
            'email'           => (string) $u['email'],
            'name'            => $u['name'] ?? null,
            'balance_tokens'  => (int) $u['balance_tokens'],
            'reserved_tokens' => (int) $u['reserved_tokens'],
            'is_blocked'      => (bool) $u['is_blocked'],
            'role'            => (string) ($u['role'] ?? 'user'),
        ],
    ];
}

// sx_key_add_spent() живёт в store.php: это операция над файлом ключа,
// и её вызывает sx_settle() из billing.php. Держать её здесь означало бы
// взаимную зависимость billing.php ↔ keys.php.

/**
 * Изменить ограничения существующего ключа.
 *
 * Нужна потому, что ошибиться при создании легко, а пересоздавать ключ
 * из-за опечатки в списке моделей — значит менять его во всех местах,
 * где он уже прописан. Само значение ключа при этом не меняется.
 *
 * @param array|null $allowedModels null — доступны все модели
 * @param int|null   $budgetTokens  null — ограничен только балансом
 */
function sx_key_update(string $hash, int $userId, ?array $allowedModels, ?int $budgetTokens, ?string $name = null): bool
{
    return sx_with_lock("key_{$hash}", function () use ($hash, $userId, $allowedModels, $budgetTokens, $name): bool {
        $rel = "keys/{$hash}.json";
        $k   = sx_read_json($rel);

        // Проверка владельца обязательна: хеш ключа виден в разметке
        // страницы, и без неё чужой ключ правился бы по подобранному хешу.
        if ($k === null || (int) $k['user_id'] !== $userId || ($k['revoked_at'] ?? null) !== null) {
            return false;
        }

        $k['allowed_models'] = ($allowedModels === null || $allowedModels === []) ? null : $allowedModels;
        $k['budget_tokens']  = $budgetTokens;
        if ($name !== null && trim($name) !== '') {
            $k['name'] = mb_substr(trim($name), 0, 60);
        }

        sx_write_json($rel, $k);
        return true;
    });
}

function sx_revoke_key(string $hash, int $userId): bool
{
    return sx_with_lock("key_{$hash}", function () use ($hash, $userId): bool {
        $rel = "keys/{$hash}.json";
        $k   = sx_read_json($rel);
        if ($k === null || (int) $k['user_id'] !== $userId || ($k['revoked_at'] ?? null) !== null) {
            return false;
        }
        $k['revoked_at'] = gmdate('c');
        sx_write_json($rel, $k);
        return true;
    });
}

/** Ключи пользователя для кабинета. Открытых значений здесь нет. */
function sx_user_keys(int $userId): array
{
    $hashes = sx_read_json("keys/_user-{$userId}.json", []) ?? [];
    $out    = [];

    foreach ($hashes as $hash) {
        $k = sx_read_json("keys/{$hash}.json");
        if ($k === null) {
            continue;
        }
        $out[] = [
            'id'             => (int) $k['id'],
            'hash'           => $hash,
            'name'           => $k['name'] ?? 'default',
            'prefix'         => $k['prefix'] ?? '',
            'budget_tokens'  => $k['budget_tokens'] ?? null,
            'spent_tokens'   => (int) ($k['spent_tokens'] ?? 0),
            'allowed_models' => $k['allowed_models'] ?? null,
            'last_used_at'   => $k['last_used_at'] ?? null,
            'revoked_at'     => $k['revoked_at'] ?? null,
            'created_at'     => $k['created_at'] ?? null,
        ];
    }

    return $out;
}

/** Разрешена ли модель этому ключу. */
function sx_key_allows_model(array $key, string $publicId): bool
{
    $allowed = $key['allowed_models'] ?? null;
    if ($allowed === null || $allowed === []) {
        return true;
    }
    return in_array($publicId, $allowed, true);
}

/** Остаток бюджета ключа, null если не ограничен. */
function sx_key_budget_left(array $key): ?int
{
    if ($key['budget_tokens'] === null) {
        return null;
    }
    return max(0, (int) $key['budget_tokens'] - (int) $key['spent_tokens']);
}

// =============================================================================
// Частота запросов
// =============================================================================

/**
 * Окно в одну минуту.
 *
 * Счётчик лежит в своём файле под своей блокировкой, поэтому инкременты
 * не теряются. В tajikgpt (api.php:181-287) счётчики писались в
 * data/rate_{uid}.json без блокировки на чтении — при параллельных
 * запросах часть инкрементов пропадала, и лимит можно было обойти.
 */
function sx_rate_check(string $scope, int $id, ?int $limit = null): array
{
    $limit = $limit ?? sx_conf('rate_per_minute', 120);
    return sx_rate_bump($scope, $id, $limit);
}
