<?php
/**
 * Soulex — шлюз. Говорит на двух протоколах.
 *
 * OpenAI:
 *   GET  /v1/models
 *   GET  /v1/models/{id}
 *   POST /v1/chat/completions
 *   GET  /v1/balance                 (расширение)
 *
 * Anthropic — чтобы работал Claude Code:
 *   POST /v1/messages
 *   POST /v1/messages/count_tokens
 *
 * Второй протокол добавлен потому, что Claude Code не умеет говорить
 * на первом: он всегда обращается к /v1/messages. Перевод форматов
 * живёт в lib/anthropic.php, а конвейер (ключ, резерв, провайдер,
 * расчёт) здесь один на оба протокола. Дублировать его было нельзя:
 * две копии биллинга однажды разойдутся, и разойдутся они в деньгах.
 *
 * ГЛАВНОЕ ПРАВИЛО: источник запроса определяется ТОЛЬКО способом
 * аутентификации. Никаких проверок HTTP_REFERER или Origin.
 *
 * В tajikgpt именно на этом построена дыра (api.php:2147-2162): если
 * Referer содержал домен сайта, запрос считался внутренним и шёл
 * бесплатно. Заголовок подставляется одной строкой curl, то есть платный
 * API там открыт любому. Здесь такой ветки нет вообще: нет ключа —
 * нет ответа.
 */

declare(strict_types=1);

// Приватная часть лежит вне веб-корня, и её путь зависит от хостинга.
// boot.php находит его сам и подключает http/keys/billing.
require_once __DIR__ . '/inc/boot.php';
require_once SX_ROOT . '/lib/upstream.php';
require_once SX_ROOT . '/lib/anthropic.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');     // клиенту отдаём JSON, а не трейс
ini_set('log_errors', '1');

/**
 * Отказ провайдера → понятное клиенту сообщение.
 *
 * Текст провайдера наружу не пересылаем (в нём бывают его внутренние
 * адреса, настоящие имена моделей и его тарифы), но и «Сервис временно
 * недоступен» на каждый случай не годится: часть отказов клиент может
 * исправить сам, а узнать об этом ему неоткуда.
 *
 * @return array{0: string, 1: int, 2: string} сообщение, код HTTP, код ошибки
 */
function sx_upstream_failure(
    ?string $reason,
    int $status,
    string $modelId,
    int $maxTokens,
    array $model
): array {
    switch ($reason) {
        case 'output_budget_too_small':
            // Рассуждающая модель тратит лимит выхода на рассуждение и не
            // доходит до ответа. Проверено: coder, lite и alpha отказывают
            // при max_tokens 64 и работают от 256.
            return [
                "Модель {$modelId} рассуждает перед ответом и израсходовала весь"
                . " лимит max_tokens={$maxTokens} на рассуждение, не дойдя до ответа."
                . ' Увеличьте max_tokens до 512 или больше, либо возьмите модель'
                . ' без рассуждения — например fast.',
                400,
                'output_budget_too_small',
            ];

        case 'context_too_long':
            return [
                "Запрос длиннее контекста модели {$modelId}:"
                . ' ' . (int) ($model['context_window'] ?? 0) . ' токенов.'
                . ' Сократите переписку или возьмите модель с большим контекстом.',
                400,
                'context_length_exceeded',
            ];

        case 'invalid_parameters':
            return [
                'Провайдер отклонил параметры запроса. Чаще всего дело в схеме'
                . ' инструментов (tools) или в response_format.',
                400,
                'invalid_parameters',
            ];

        case 'upstream_model_missing':
            // Это наша ошибка настройки, а не клиента: в каталоге стоит
            // модель, которой у провайдера больше нет. Клиенту про это
            // знать нечего, но в журнал надо написать громко.
            error_log("[soulex] НАСТРОЙКА: апстрим {$model['upstream_id']} у провайдера"
                . " {$model['provider_slug']} не существует — проверить config/catalog.php");
            return [
                "Модель {$modelId} временно недоступна. Попробуйте другую: GET /v1/models",
                503,
                'model_unavailable',
            ];
    }

    if ($status === 429) {
        return ['Провайдер перегружен. Повторите через несколько секунд.', 429, 'upstream_busy'];
    }

    return ['Сервис временно недоступен', $status >= 500 ? 503 : ($status ?: 503), 'upstream_error'];
}

/** То же, но сразу отдаёт ответ и завершает работу. */
function sx_fail_upstream(array $res, array $model, string $modelId, int $maxTokens): never
{
    [$msg, $code, $errCode] = sx_upstream_failure(
        $res['reason'] ?? null,
        (int) ($res['status'] ?? 502),
        $modelId,
        $maxTokens,
        $model
    );

    sx_error(
        $msg,
        $code,
        $code === 429 ? 'rate_limit_error' : ($code >= 500 ? 'api_error' : 'invalid_request_error'),
        $errCode
    );
}

// -----------------------------------------------------------------------------
// Маршрут
// -----------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
$uri    = preg_replace('#^.*/gateway\.php#', '', $uri) ?? $uri;
if (isset($_GET['path'])) {
    $uri = '/' . ltrim((string) $_GET['path'], '/');
}
$uri = '/' . trim((string) $uri, '/');

// Протокол определяется путём и выставляется ДО проверки ключа: иначе
// ошибка 401 уйдёт клиенту Claude Code в чужом формате, и он покажет
// не «неверный ключ», а сбой разбора ответа.
$isAnthropic = in_array($uri, ['/v1/messages', '/messages', '/v1/messages/count_tokens'], true);
sx_api_flavor($isAnthropic ? 'anthropic' : 'openai');

// CORS: ключ в браузер не кладут, но клиенты вроде Open WebUI
// делают preflight. Разрешаем только нужные заголовки.
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    // x-api-key и anthropic-* нужны SDK Anthropic и Claude Code
    header('Access-Control-Allow-Headers: Authorization, Content-Type, x-api-key, '
        . 'anthropic-version, anthropic-beta, anthropic-dangerous-direct-browser-access');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// -----------------------------------------------------------------------------
// Аутентификация — единственный способ попасть внутрь
// -----------------------------------------------------------------------------
$rawKey = sx_bearer_token();
if ($rawKey === '') {
    sx_error(
        'Нужен ключ API. Передайте его в заголовке: Authorization: Bearer sk-sx-…',
        401,
        'authentication_error',
        'missing_api_key'
    );
}

try {
    $auth = sx_resolve_key($rawKey);
} catch (Throwable $e) {
    error_log('[soulex] хранилище недоступно при проверке ключа: ' . $e->getMessage());
    sx_error('Сервис временно недоступен', 503, 'api_error', 'service_unavailable');
}

if ($auth === null) {
    sx_error('Неверный или отозванный ключ API', 401, 'authentication_error', 'invalid_api_key');
}

$user = $auth['user'];
$key  = $auth['key'];

if ($user['is_blocked']) {
    sx_error('Аккаунт заблокирован. Напишите в поддержку.', 403, 'permission_error', 'account_blocked');
}

// -----------------------------------------------------------------------------
// GET /v1/balance
// -----------------------------------------------------------------------------
if ($method === 'GET' && in_array($uri, ['/v1/balance', '/balance'], true)) {
    $baseRate = sx_conf('base_rate_micro_per_m', 50000);
    sx_json([
        'object'          => 'balance',
        'balance_tokens'  => $user['balance_tokens'],
        'reserved_tokens' => $user['reserved_tokens'],
        'balance_usd'     => round($user['balance_tokens'] * $baseRate / 1_000_000 / 1_000_000, 6),
        'key' => [
            'name'           => $key['name'],
            'budget_tokens'  => $key['budget_tokens'],
            'spent_tokens'   => $key['spent_tokens'],
            'budget_left'    => sx_key_budget_left($key),
            'allowed_models' => $key['allowed_models'],
        ],
    ]);
}

// -----------------------------------------------------------------------------
// GET /v1/models  и  GET /v1/models/{id}
//
// Ключ проверен выше по-настоящему. В tajikgpt этот эндпоинт пропускал
// любую строку, начинающуюся на "Bearer sk-tj-", без сверки с хранилищем
// (api.php:1640-1660) — проверка была фиктивной, и я это подтвердил
// живым запросом: Bearer sk-tj-demo отдавал весь каталог.
// -----------------------------------------------------------------------------
if ($method === 'GET' && in_array($uri, ['/v1/models', '/models'], true)) {
    sx_json(['object' => 'list', 'data' => sx_list_models()]);
}

if ($method === 'GET' && preg_match('#^/(?:v1/)?models/([A-Za-z0-9._-]+)$#', $uri, $m)) {
    foreach (sx_list_models() as $entry) {
        if ($entry['id'] === $m[1]) {
            sx_json($entry);
        }
    }
    sx_error("Модель {$m[1]} не найдена", 404, 'invalid_request_error', 'model_not_found');
}

// -----------------------------------------------------------------------------
// POST /v1/chat/completions  и  POST /v1/messages
// -----------------------------------------------------------------------------
$postPaths = [
    '/v1/chat/completions', '/chat/completions',
    '/v1/messages', '/messages', '/v1/messages/count_tokens',
];
if ($method !== 'POST' || !in_array($uri, $postPaths, true)) {
    sx_error('Эндпоинт не найден. Документация: https://soulex.cc/docs', 404, 'invalid_request_error', 'not_found');
}

$raw   = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    sx_error('Тело запроса должно быть корректным JSON', 400, 'invalid_request_error', 'invalid_json');
}

// -----------------------------------------------------------------------------
// Приведение запроса Anthropic к внутреннему виду
//
// Дальше весь конвейер работает с одним форматом. Так проверка ключа,
// резерв, обращение к провайдеру и расчёт остаются единственными в коде
// и не могут разойтись между протоколами.
// -----------------------------------------------------------------------------
$requestedModel = (string) ($input['model'] ?? '');
$modelAliased   = false;

if ($isAnthropic) {
    if (!is_array($input['messages'] ?? null)) {
        sx_error('Поле messages обязательно', 400, 'invalid_request_error', 'missing_messages');
    }

    [$resolved, $modelAliased] = sx_anthropic_resolve_model($requestedModel);
    if ($resolved === null) {
        sx_error(
            $requestedModel === ''
                ? 'Поле model обязательно'
                : "Модель {$requestedModel} не найдена. Список: GET /v1/models",
            $requestedModel === '' ? 400 : 404,
            'invalid_request_error',
            $requestedModel === '' ? 'missing_model' : 'model_not_found'
        );
    }

    $converted = sx_anthropic_messages_to_openai($input['messages']);

    // system у Anthropic — отдельное поле запроса, а не роль в переписке
    $systemText = sx_anthropic_flatten_text($input['system'] ?? '');
    if ($systemText !== '') {
        array_unshift($converted, ['role' => 'system', 'content' => $systemText]);
    }

    $rebuilt = [
        'model'      => $resolved,
        'messages'   => $converted,
        'max_tokens' => (int) ($input['max_tokens'] ?? 4096),
        'stream'     => (bool) ($input['stream'] ?? false),
    ];

    if (isset($input['temperature'])) {
        $rebuilt['temperature'] = (float) $input['temperature'];
    }
    if (isset($input['top_p'])) {
        $rebuilt['top_p'] = $input['top_p'];
    }
    if (isset($input['stop_sequences']) && is_array($input['stop_sequences'])) {
        $rebuilt['stop'] = $input['stop_sequences'];
    }

    $tools = sx_anthropic_tools_to_openai($input['tools'] ?? null);
    if ($tools !== []) {
        $rebuilt['tools'] = $tools;
        $choice = sx_anthropic_tool_choice_to_openai($input['tool_choice'] ?? null);
        if ($choice !== null) {
            $rebuilt['tool_choice'] = $choice;
        }
    }

    // top_k, thinking и metadata сознательно не переносим: у OpenAI-формата
    // нет top_k, а блоки рассуждения провайдер не принимает обратно.
    $input = $rebuilt;
}

$modelId  = (string) ($input['model'] ?? '');
$messages = $input['messages'] ?? null;
$stream   = (bool) ($input['stream'] ?? false);

if ($modelId === '') {
    sx_error('Поле model обязательно', 400, 'invalid_request_error', 'missing_model');
}
if (!is_array($messages) || $messages === []) {
    sx_error('Поле messages обязательно и не может быть пустым', 400, 'invalid_request_error', 'missing_messages');
}

$model = sx_load_model($modelId);
if ($model === null) {
    sx_error(
        "Модель {$modelId} не найдена. Список: GET /v1/models",
        404,
        'invalid_request_error',
        'model_not_found'
    );
}

if (!sx_key_allows_model($key, $modelId)) {
    // Перечисляем, что ключу РАЗРЕШЕНО. Без этого сообщение говорит только
    // чего нельзя, и человек не понимает, что ограничение вообще стоит:
    // ровно на это ушло время при первом запуске.
    $allowed = is_array($key['allowed_models'] ?? null) ? $key['allowed_models'] : [];
    sx_error(
        "Ключ «{$key['name']}» ограничен моделями: " . implode(', ', $allowed)
            . ". Модель {$modelId} в список не входит."
            . ' Снять ограничение: https://soulex.cc/keys',
        403,
        'permission_error',
        'model_not_allowed',
        ['allowed_models' => $allowed, 'requested_model' => $modelId]
    );
}

// Частота запросов на ключ
$rate = sx_rate_check('k', $key['id']);
if (!$rate['ok']) {
    header('Retry-After: ' . $rate['reset_in']);
    sx_error(
        "Превышен лимит {$rate['limit']} запросов в минуту",
        429,
        'rate_limit_error',
        'rate_limit_exceeded',
        ['retry_after' => $rate['reset_in']]
    );
}

// -----------------------------------------------------------------------------
// POST /v1/messages/count_tokens
//
// Claude Code спрашивает размер контекста перед отправкой, чтобы решить,
// не пора ли сжать историю. Без этого эндпоинта он получает 404 и либо
// отказывается работать, либо шлёт запросы вслепую.
//
// Денег не берём: это не обращение к модели. Но под ограничение частоты
// запросов эндпоинт попадает (проверка выше), иначе им можно стучать без
// предела, и каждый стук читает файл ключа.
// -----------------------------------------------------------------------------
if ($uri === '/v1/messages/count_tokens') {
    sx_json([
        'input_tokens' => sx_estimate_input_tokens(
            $messages,
            isset($input['tools']) ? json_encode($input['tools'], JSON_UNESCAPED_UNICODE) : ''
        ),
    ]);
}

// Дневной предохранитель по закупке у провайдера
if (!sx_provider_within_cap($model)) {
    error_log("[soulex] дневной лимит закупки исчерпан: {$model['provider_slug']}");
    sx_error(
        'Модель временно недоступна. Попробуйте другую или повторите позже.',
        503,
        'api_error',
        'provider_cap_reached'
    );
}

// Возврат зависших резервов этого пользователя вместо крона
try {
    sx_reap_stale_reservations($user['id']);
} catch (Throwable $e) {
    error_log('[soulex] уборка резервов не удалась: ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// Резерв
// -----------------------------------------------------------------------------
$maxTokens = (int) ($input['max_tokens'] ?? $input['max_completion_tokens'] ?? 4096);
$maxTokens = max(1, min($maxTokens, (int) $model['max_tokens']));

// Описания инструментов тоже идут в промпт и тоже оплачиваются. У агентов
// они занимают больше, чем сама переписка: Claude Code присылает схемы
// на чтение, запись и поиск по файлам. Без их учёта оценка входа
// занижается в разы, и резерв берётся меньше настоящей стоимости.
$estIn = sx_estimate_input_tokens(
    $messages,
    isset($input['tools']) ? json_encode($input['tools'], JSON_UNESCAPED_UNICODE) : ''
);

try {
    $requestId = sx_reserve(
        $user['id'],
        $key['hash'],
        $model,
        $estIn,
        $maxTokens,
        sx_key_budget_left($key)
    );
} catch (SxInsufficientTokens $e) {
    // Сообщение должно объяснять, из чего сложилась сумма. Иначе клиент
    // с балансом 100 000 видит «нужно 678 699» и не понимает, что почти
    // всё это — резерв под max_tokens, а не стоимость самого запроса.
    // Сколько из суммы пришлось на вход и на выход — считаем теми же
    // правилами, что sx_reserve(), иначе числа в ответе разойдутся с
    // расчётом. Вход резервируется с надбавкой: оценка по длине текста
    // занижает результат настоящего разбиения на токены.
    $resvIn  = $estIn + intdiv($estIn * (int) sx_conf('reserve_margin_pct', 15), 100);
    $resvOut = min(
        $maxTokens,
        max(
            (int) sx_conf('reserve_out_floor', 2048),
            intdiv($maxTokens * (int) sx_conf('reserve_out_pct', 25), 100)
        )
    );

    $hint = $resvOut > $resvIn
        ? " Основную часть занимает резерв под max_tokens={$maxTokens}"
          . " ({$resvOut} токенов выхода): уменьшите max_tokens, и запрос пройдёт."
        : '';

    // У дорогих моделей вход и выход тарифицируются по-разному. Выводить
    // одно усреднённое число нельзя: клиент поделит required_tokens на
    // него и получит величину, которой в расчёте не было.
    $detail = [
        'required_tokens'         => $e->required,
        'available_tokens'        => $e->available,
        'model'                   => $modelId,
        'reserved_input_tokens'   => $resvIn,
        'reserved_output_tokens'  => $resvOut,
        'max_tokens_used'         => $maxTokens,
    ];

    if (isset($model['coef_in_milli'], $model['coef_out_milli'])) {
        $detail['coefficient_in']  = round((int) $model['coef_in_milli'] / 1000, 3);
        $detail['coefficient_out'] = round((int) $model['coef_out_milli'] / 1000, 3);
    } else {
        $detail['coefficient'] = round((int) $model['coef_milli'] / 1000, 3);
    }

    $detail['topup_url'] = 'https://soulex.cc/topup';

    sx_error(
        "Недостаточно токенов: нужно {$e->required}, на балансе {$e->available}."
            . $hint
            . ' Пополнить: https://soulex.cc/topup',
        402,
        'insufficient_quota',
        'insufficient_tokens',
        $detail
    );
} catch (SxKeyBudgetExceeded $e) {
    sx_error($e->getMessage(), 402, 'insufficient_quota', 'key_budget_exceeded');
} catch (Throwable $e) {
    error_log('[soulex] резервирование не удалось: ' . $e->getMessage());
    sx_error('Сервис временно недоступен', 503, 'api_error', 'service_unavailable');
}

// -----------------------------------------------------------------------------
// Запрос к провайдеру
// -----------------------------------------------------------------------------
$payload = [
    'model'       => $model['upstream_id'],
    'messages'    => $messages,
    'max_tokens'  => $maxTokens,
    'temperature' => min(2.0, max(0.0, (float) ($input['temperature'] ?? 1.0))),
];

// Параметры пробрасываем выборочно: передача всего тела наружу утащила бы
// и наши внутренние поля, и мусор от клиента.
foreach (['top_p', 'tools', 'tool_choice', 'response_format', 'stop',
          'presence_penalty', 'frequency_penalty', 'seed', 'reasoning_effort'] as $opt) {
    if (array_key_exists($opt, $input)) {
        $payload[$opt] = $input[$opt];
    }
}

$completionId = 'chatcmpl-' . bin2hex(random_bytes(12));
$messageId    = 'msg_' . bin2hex(random_bytes(12));
$created      = time();

// --- Стриминг --------------------------------------------------------------
if ($stream) {
    sx_disable_buffering();
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    // Паддинг пробивает буфер прокси на шаред-хостинге: без него первые
    // килобайты оседают в nginx и клиент видит ответ только в конце.
    // Приём взят из рабочего кода tajikgpt (api.php:~660).
    echo ':' . str_repeat(' ', 4096) . "\n\n";
    sx_sse_flush();

    // У Anthropic каждое событие имеет имя в строке event:, у OpenAI
    // строки event: нет вообще. Разбор у клиентов разный, поэтому формат
    // выбирается здесь, а не склеивается в одном месте.
    $sse = function (string $event, array $data): void {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        sx_sse_flush();
    };

    $textOpen   = false;   // открыт ли текстовый блок (Anthropic)
    $firstChunk = true;    // отдана ли роль (OpenAI)

    if ($isAnthropic) {
        // message_start обязателен и идёт до любого содержимого.
        // input_tokens здесь наша оценка: точное число провайдер
        // присылает только в конце, а поле обязательно уже сейчас.
        $sse('message_start', [
            'type'    => 'message_start',
            'message' => [
                'id'            => $messageId,
                'type'          => 'message',
                'role'          => 'assistant',
                'model'         => $modelId,
                'content'       => [],
                'stop_reason'   => null,
                'stop_sequence' => null,
                'usage'         => ['input_tokens' => $estIn, 'output_tokens' => 0],
            ],
        ]);

        $onDelta = function (string $delta) use ($sse, &$textOpen): void {
            // Блок открываем по первой порции, а не заранее: при ответе
            // одними вызовами инструментов пустой текстовый блок заставил
            // бы Claude Code показать пустое сообщение перед вызовом.
            if (!$textOpen) {
                $sse('content_block_start', [
                    'type'          => 'content_block_start',
                    'index'         => 0,
                    'content_block' => ['type' => 'text', 'text' => ''],
                ]);
                $textOpen = true;
            }

            $sse('content_block_delta', [
                'type'  => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => $delta],
            ]);
        };
    } else {
        $onDelta = function (string $delta) use ($completionId, $created, $modelId, &$firstChunk): void {
            if ($firstChunk) {
                // Первый чанк по спецификации несёт роль
                echo 'data: ' . json_encode([
                    'id'      => $completionId,
                    'object'  => 'chat.completion.chunk',
                    'created' => $created,
                    'model'   => $modelId,
                    'choices' => [['index' => 0, 'delta' => ['role' => 'assistant'], 'finish_reason' => null]],
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                $firstChunk = false;
            }

            echo 'data: ' . json_encode([
                'id'      => $completionId,
                'object'  => 'chat.completion.chunk',
                'created' => $created,
                'model'   => $modelId,
                'choices' => [['index' => 0, 'delta' => ['content' => $delta], 'finish_reason' => null]],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            sx_sse_flush();
        };
    }

    $res = sx_upstream_stream($model, $payload, $onDelta);

    if ($res['error'] !== null) {
        sx_release($requestId, $model, (string) $res['error'], $user['id']);

        // Причину разбираем так же, как в обычном ответе: отказ из-за
        // малого max_tokens или битой схемы инструментов клиент исправит
        // сам, если ему сказать. Заголовки уже отправлены, поэтому код
        // ответа не поменять — сообщение уходит событием внутри потока.
        [$msg, , $errCode] = sx_upstream_failure(
            $res['reason'] ?? null,
            (int) ($res['status'] ?? 502),
            $modelId,
            $maxTokens,
            $model
        );

        if ($isAnthropic) {
            // Незакрытый блок закрываем: клиент ждёт пару start/stop
            if ($textOpen) {
                $sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
            }
            $sse('error', [
                'type'  => 'error',
                'error' => ['type' => $errCode === 'upstream_busy' ? 'rate_limit_error'
                    : ($errCode === 'upstream_error' ? 'api_error' : 'invalid_request_error'),
                    'message' => $msg],
            ]);
        } else {
            echo 'data: ' . json_encode([
                'error' => ['message' => $msg, 'type' => 'api_error', 'code' => $errCode],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            echo "data: [DONE]\n\n";
            sx_sse_flush();
        }
        exit;
    }

    $usage     = is_array($res['usage']) ? $res['usage'] : null;
    $tokensIn  = (int) ($usage['prompt_tokens'] ?? 0);
    $tokensOut = (int) ($usage['completion_tokens'] ?? 0);
    $cached    = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

    // Провайдер не прислал usage — считаем по тексту с запасом,
    // иначе запрос уйдёт бесплатно за наш счёт.
    if ($tokensIn === 0 && $tokensOut === 0) {
        error_log("[soulex] провайдер {$model['provider_slug']} не прислал usage на стриме");
        $tokensIn  = $estIn;
        $tokensOut = sx_fallback_token_count($res['content']);
    }

    $charged   = sx_charge_tokens($model, $tokensIn, $tokensOut);
    $toolCalls = is_array($res['tool_calls'] ?? null) ? $res['tool_calls'] : [];

    // Финальные события: usage честный, без вычетов. В tajikgpt из
    // prompt_tokens вычиталось 4480 «системных» токенов (api.php:2458-2462),
    // то есть в ответе и в счёте стояли разные числа.
    if ($isAnthropic) {
        if ($textOpen) {
            $sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
        }

        // Вызовы инструментов отдельными блоками. Индексы продолжают
        // нумерацию: нулевой занят текстом, даже если он не открывался —
        // пропуск индекса клиент переживает, а повтор нет.
        $idx = 1;
        foreach ($toolCalls as $tc) {
            $argsRaw = (string) ($tc['function']['arguments'] ?? '');
            $parsed  = json_decode($argsRaw === '' ? '{}' : $argsRaw, true);

            $sse('content_block_start', [
                'type'          => 'content_block_start',
                'index'         => $idx,
                'content_block' => [
                    'type'  => 'tool_use',
                    'id'    => (string) ($tc['id'] ?? ('toolu_' . bin2hex(random_bytes(12)))),
                    'name'  => (string) ($tc['function']['name'] ?? ''),
                    'input' => new stdClass(),
                ],
            ]);

            // Аргументы спецификация передаёт как частичный JSON. Отдаём
            // их одной порцией: собирать по кускам клиенту всё равно
            // придётся, а дробить нам нечем — провайдер уже отдал целиком.
            $sse('content_block_delta', [
                'type'  => 'content_block_delta',
                'index' => $idx,
                'delta' => [
                    'type'         => 'input_json_delta',
                    // Пустые аргументы должны дать {} , а не [] :
                    // в PHP это одно значение, а для клиента — разные типы.
                    'partial_json' => sx_anthropic_json_object($parsed),
                ],
            ]);

            $sse('content_block_stop', ['type' => 'content_block_stop', 'index' => $idx]);
            $idx++;
        }

        $sse('message_delta', [
            'type'  => 'message_delta',
            'delta' => [
                'stop_reason'   => sx_anthropic_stop_reason($res['finish_reason'], $toolCalls !== []),
                'stop_sequence' => null,
            ],
            'usage' => [
                'input_tokens'  => $tokensIn,
                'output_tokens' => $tokensOut,
            ],
            'soulex' => ['charged_tokens' => $charged, 'request_id' => $requestId],
        ]);

        $sse('message_stop', ['type' => 'message_stop']);
    } else {
        echo 'data: ' . json_encode([
            'id'      => $completionId,
            'object'  => 'chat.completion.chunk',
            'created' => $created,
            'model'   => $modelId,
            'choices' => [[
                'index'         => 0,
                'delta'         => $toolCalls !== [] ? ['tool_calls' => $toolCalls] : new stdClass(),
                'finish_reason' => $res['finish_reason'],
            ]],
            'usage'   => [
                'prompt_tokens'     => $tokensIn,
                'completion_tokens' => $tokensOut,
                'total_tokens'      => $tokensIn + $tokensOut,
            ],
            'soulex'  => ['charged_tokens' => $charged, 'request_id' => $requestId],
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "data: [DONE]\n\n";
        sx_sse_flush();
    }

    // Соединение с клиентом закрываем, расчёт делаем после — он не должен
    // задерживать ответ. Имя функции зависит от сервера, поэтому обёртка.
    sx_finish_request();

    try {
        sx_settle($requestId, $model, $tokensIn, $tokensOut, $cached, $res['latency_ms'], $user['id']);
    } catch (Throwable $e) {
        error_log("[soulex] расчёт не удался, запрос {$requestId}: " . $e->getMessage());
    }
    exit;
}

// --- Обычный ответ ---------------------------------------------------------
$res = sx_upstream_call($model, $payload);

if (!$res['ok']) {
    sx_release($requestId, $model, (string) $res['error'], $user['id']);
    sx_fail_upstream($res, $model, $modelId, $maxTokens);
}

$data      = $res['data'];
$usage     = $data['usage'] ?? [];
$tokensIn  = (int) ($usage['prompt_tokens'] ?? 0);
$tokensOut = (int) ($usage['completion_tokens'] ?? 0);
$cached    = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
// При ответе одними вызовами инструментов content приходит null
$content   = (string) ($data['choices'][0]['message']['content'] ?? '');
$toolCalls = $data['choices'][0]['message']['tool_calls'] ?? [];
$toolCalls = is_array($toolCalls) ? $toolCalls : [];

if ($tokensIn === 0 && $tokensOut === 0) {
    error_log("[soulex] провайдер {$model['provider_slug']} не прислал usage");
    $tokensIn  = $estIn;
    // Аргументы инструментов — тоже выход, и его надо посчитать, иначе
    // ответ из одних вызовов уйдёт почти бесплатно.
    $tokensOut = sx_fallback_token_count(
        $content . json_encode($toolCalls, JSON_UNESCAPED_UNICODE)
    );
}

try {
    $charged = sx_settle($requestId, $model, $tokensIn, $tokensOut, $cached, $res['latency_ms'], $user['id']);
} catch (Throwable $e) {
    error_log("[soulex] расчёт не удался, запрос {$requestId}: " . $e->getMessage());
    $charged = sx_charge_tokens($model, $tokensIn, $tokensOut);
}

// -----------------------------------------------------------------------------
// Ответ в формате Anthropic
// -----------------------------------------------------------------------------
if ($isAnthropic) {
    $payloadOut = [
        'id'   => $messageId,
        'type' => 'message',
        'role' => 'assistant',
        // Имя НАШЕЙ модели, а не запрошенное "claude-...". Клиент должен
        // видеть, что обслужило запрос; выдавать свою модель за чужую —
        // обман покупателя, и чарджбэки за это прилетят нам.
        'model'         => $modelId,
        'content'       => sx_anthropic_content_blocks($content, $toolCalls),
        'stop_reason'   => sx_anthropic_stop_reason(
            $data['choices'][0]['finish_reason'] ?? null,
            $toolCalls !== []
        ),
        'stop_sequence' => null,
        'usage' => [
            'input_tokens'  => $tokensIn,
            'output_tokens' => $tokensOut,
        ],
        'soulex' => [
            'charged_tokens' => $charged,
            'balance_tokens' => max(0, $user['balance_tokens'] - $charged),
            'request_id'     => $requestId,
        ],
    ];

    // Если имя модели сопоставили, говорим об этом прямо: иначе человек
    // будет искать, почему в ответе стоит не то, что он просил.
    if ($modelAliased) {
        $payloadOut['soulex']['requested_model'] = $requestedModel;
        $payloadOut['soulex']['note'] =
            "Запрошена модель {$requestedModel}; она у нас не предоставляется. "
            . "Запрос обслужен моделью {$modelId}. "
            . 'Чтобы выбрать модель самому, укажите её ID в ANTHROPIC_MODEL.';
    }

    sx_json($payloadOut);
}

sx_json([
    'id'      => $completionId,
    // Ровно "chat.completion". Любое другое значение ломает SDK.
    'object'  => 'chat.completion',
    'created' => $created,
    // Отдаём публичный ID, а не имя модели провайдера
    'model'   => $modelId,
    'choices' => [[
        'index'   => 0,
        // tool_calls пробрасываем: без них клиент с инструментами видит
        // пустой ответ и не понимает, что модель хотела вызвать функцию.
        'message' => $toolCalls !== []
            ? ['role' => 'assistant', 'content' => $content === '' ? null : $content,
               'tool_calls' => $toolCalls]
            : ['role' => 'assistant', 'content' => $content],
        'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'stop',
        'logprobs'      => null,
    ]],
    'usage'   => [
        'prompt_tokens'     => $tokensIn,
        'completion_tokens' => $tokensOut,
        'total_tokens'      => $tokensIn + $tokensOut,
    ],
    'soulex'  => [
        'charged_tokens' => $charged,
        'balance_tokens' => max(0, $user['balance_tokens'] - $charged),
        'request_id'     => $requestId,
    ],
]);
