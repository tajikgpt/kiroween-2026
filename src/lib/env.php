<?php
/**
 * Загрузка переменных окружения.
 *
 * Файл .env должен лежать ВЫШЕ веб-корня. На типичном shared-хостинге
 * веб-корень — это public_html, значит .env кладётся рядом с ним, а не
 * внутрь. Иначе его можно будет скачать по прямой ссылке вместе со
 * всеми ключами.
 */

declare(strict_types=1);

if (basename($_SERVER['PHP_SELF'] ?? '') === 'env.php') {
    http_response_code(404);
    exit('Not Found');
}

function sx_load_env(?string $path = null): bool
{
    static $loaded = false;
    if ($loaded) {
        return true;
    }

    // Порядок поиска: явный путь, затем SX_ENV_FILE из окружения,
    // затем над веб-корнем и рядом с кодом. SX_ENV_FILE нужен, когда
    // хостинг не даёт положить файл ни в один из обычных каталогов.
    $explicit = getenv('SX_ENV_FILE');
    $candidates = $path !== null ? [$path] : array_values(array_filter([
        $explicit !== false && $explicit !== '' ? $explicit : null,
        dirname(__DIR__, 2) . '/.env',
        dirname(__DIR__) . '/.env',
    ]));

    foreach ($candidates as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // Снимаем парные кавычки, если есть
            if (strlen($val) >= 2) {
                $f = $val[0];
                $l = substr($val, -1);
                if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
                    $val = substr($val, 1, -1);
                }
            }
            // Уже выставленное окружение приоритетнее файла
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $val;
                putenv($key . '=' . $val);
            }
        }
        $loaded = true;
        return true;
    }

    error_log('[tajikgpt] .env не найден; проверены: ' . implode(', ', $candidates));
    return false;
}

/**
 * Значение переменной окружения.
 *
 * @param string|null $default null означает «обязательная»: при отсутствии
 *                             выбрасываем исключение, а не работаем дальше
 *                             с пустым ключом и непонятной ошибкой от провайдера.
 */
function sx_env(string $key, ?string $default = null): string
{
    sx_load_env();
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        if ($default === null) {
            throw new RuntimeException("Переменная окружения {$key} не задана");
        }
        return $default;
    }
    return (string) $v;
}

sx_load_env();
