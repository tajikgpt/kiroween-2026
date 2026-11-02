<?php
/**
 * Soulex — HTTP-ответы в формате OpenAI.
 *
 * Совместимость обязательна до буквы: продукт в том, что клиент
 * направляет на нас свой Claude Code, Cursor или openai-python и ничего
 * не переписывает. В tajikgpt отдавалось object: "tj.response"
 * (api.php:2505) — на этом стандартные SDK ломаются.
 */

declare(strict_types=1);

function sx_json(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Формат ответов: 'openai' или 'anthropic'.
 *
 * Выставляется один раз при разборе маршрута. Нужен затем, чтобы
 * sx_error() сама отдавала ошибку в нужной форме: мест вызова у неё
 * больше десятка, и ветвить каждое вручную означало бы однажды
 * пропустить одно и отдать клиенту Claude Code чужой формат.
 */
function sx_api_flavor(?string $set = null): string
{
    static $flavor = 'openai';
    if ($set !== null) {
        $flavor = $set;
    }
    return $flavor;
}

/**
 * Ошибка в формате, который разбирает клиент.
 *
 * OpenAI:    {"error": {"message", "type", "param", "code"}}
 * Anthropic: {"type": "error", "error": {"type", "message"}}
 *
 * Словари типов у них разные, поэтому для Anthropic тип выводится из
 * кода ответа, а не переносится как есть. Наши дополнительные поля
 * (разбор суммы при нехватке токенов) складываются рядом: клиент их
 * игнорирует, а человеку в логе они нужны.
 */
function sx_error(
    string $message,
    int $status = 400,
    string $type = 'invalid_request_error',
    ?string $code = null,
    array $extra = []
): never {
    if (sx_api_flavor() === 'anthropic') {
        require_once __DIR__ . '/anthropic.php';
        sx_json([
            'type'  => 'error',
            'error' => array_merge([
                'type'    => sx_anthropic_error_type($status),
                'message' => $message,
            ], $extra),
        ], $status);
    }

    sx_json([
        'error' => array_merge([
            'message' => $message,
            'type'    => $type,
            'param'   => null,
            'code'    => $code,
        ], $extra),
    ], $status);
}

/**
 * Ключ API из заголовков.
 *
 * Поддерживаем два способа, потому что их используют разные клиенты:
 *
 *   Authorization: Bearer sk-sx-…   OpenAI SDK, curl, Cursor,
 *                                   Claude Code с ANTHROPIC_AUTH_TOKEN
 *   x-api-key: sk-sx-…              SDK Anthropic,
 *                                   Claude Code с ANTHROPIC_API_KEY
 *
 * Без второго варианта Claude Code получает 401 и человек не понимает,
 * почему ключ «неверный»: он его только что скопировал.
 */
function sx_bearer_token(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        // Под CGI/FastCGI Apache прячет заголовок сюда
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    if (function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $candidates[] = (string) $value;
            }
        }
    }

    foreach ($candidates as $raw) {
        $raw = trim((string) $raw);
        if ($raw !== '' && stripos($raw, 'bearer ') === 0) {
            return trim(substr($raw, 7));
        }
    }

    // x-api-key идёт без приставки Bearer
    $direct = [
        $_SERVER['HTTP_X_API_KEY'] ?? '',
        $_SERVER['REDIRECT_HTTP_X_API_KEY'] ?? '',
    ];

    if (function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $name => $value) {
            if (strcasecmp((string) $name, 'x-api-key') === 0) {
                $direct[] = (string) $value;
            }
        }
    }

    foreach ($direct as $raw) {
        $raw = trim((string) $raw);
        if ($raw !== '') {
            return $raw;
        }
    }

    return '';
}

/**
 * Настоящий IP клиента.
 *
 * Заголовку CF-Connecting-IP доверяем только если запрос пришёл из сети
 * Cloudflare — иначе клиент подставит туда что угодно и обойдёт учёт по IP.
 * Логика взята из tajikgpt (api.php:36-85), там она сделана правильно.
 */
function sx_client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    static $cfRanges = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
        '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
        '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];

    $fromCloudflare = false;
    foreach ($cfRanges as $cidr) {
        if (sx_ip_in_cidr($remote, $cidr)) {
            $fromCloudflare = true;
            break;
        }
    }

    if ($fromCloudflare && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return $remote;
}

function sx_ip_in_cidr(string $ip, string $cidr): bool
{
    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int) $bits;

    $ipLong     = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) {
        return false;
    }

    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

/** Отключить буферизацию, чтобы flush доходил до клиента на шаред-хостинге. */
function sx_disable_buffering(): void
{
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ob_implicit_flush(true);
}

function sx_sse_flush(): void
{
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

/**
 * Закрыть соединение с клиентом, продолжив работу в фоне.
 *
 * Нужно, чтобы расчёт списания не задерживал ответ. Имя функции зависит
 * от сервера: на этом хостинге стоит LiteSpeed, и fastcgi_finish_request()
 * там нет — есть litespeed_finish_request(). На PHP-FPM наоборот.
 *
 * Если ни одной нет, просто сбрасываем буфер: клиент получит ответ,
 * а соединение закроется после завершения скрипта.
 */
function sx_finish_request(): void
{
    if (function_exists('litespeed_finish_request')) {
        @litespeed_finish_request();
        return;
    }
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }
    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}
