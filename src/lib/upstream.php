<?php
/**
 * Soulex — обращение к провайдеру.
 *
 * Ядро взято из рабочего кода tajikgpt (api.php:309-730) — там стриминг уже
 * доведён до состояния, когда он работает на этом самом шаред-хостинге.
 * Главное отличие: провайдер и его ключ берутся из каталога, а не из двух
 * констант _E1/_E2 в config.php. Падение одного провайдера не должно
 * гасить весь каталог.
 */

declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/billing.php';   // здесь живут sx_load_model и sx_list_models

function sx_provider_key(array $model): string
{
    return sx_env((string) $model['provider_key_env']);
}

/**
 * Обычный запрос без стриминга.
 *
 * @return array{ok: bool, data?: array, error?: string, status?: int, latency_ms: int}
 */
function sx_upstream_call(array $model, array $payload): array
{
    $url  = rtrim((string) $model['provider_base_url'], '/') . '/chat/completions';
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $t0   = microtime(true);

    $response = false;
    $httpCode = 0;
    $curlErr  = '';

    // Две попытки, повтор только на быстрых сетевых сбоях. На полном
    // таймауте не повторяем: там уже прошло 60 секунд, клиент не ждёт.
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . sx_provider_key($model),
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $curlErr  = (string) curl_error($ch);
        curl_close($ch);

        if ($curlErr === '' && $response !== false) {
            break;
        }

        $retryable = [CURLE_COULDNT_CONNECT, CURLE_GOT_NOTHING, CURLE_RECV_ERROR, CURLE_SEND_ERROR];
        if ($attempt === 1 && in_array($errno, $retryable, true)) {
            usleep(300000);
            continue;
        }
        break;
    }

    $latency = (int) round((microtime(true) - $t0) * 1000);

    if ($curlErr !== '' || $response === false) {
        error_log("[soulex] провайдер {$model['provider_slug']} недоступен: {$curlErr}");
        return ['ok' => false, 'error' => 'upstream_unreachable', 'status' => 503, 'latency_ms' => $latency];
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data)) {
        error_log("[soulex] провайдер {$model['provider_slug']} вернул не-JSON, код {$httpCode}");
        return ['ok' => false, 'error' => 'upstream_bad_response', 'status' => 502, 'latency_ms' => $latency];
    }

    if (isset($data['error']) || $httpCode >= 400) {
        $msg = is_array($data['error'] ?? null)
            ? (string) ($data['error']['message'] ?? 'ошибка провайдера')
            : (string) ($data['error'] ?? 'ошибка провайдера');
        error_log("[soulex] провайдер {$model['provider_slug']} ошибка {$httpCode}: {$msg}");
        return [
            'ok'     => false,
            'error'  => 'upstream_error',
            'status' => $httpCode >= 400 && $httpCode < 600 ? $httpCode : 502,
            // Причину сводим к коду: сам текст провайдера клиенту не идёт,
            // но по коду шлюз подберёт понятное сообщение.
            'reason' => sx_upstream_reason((string) $response),
            'latency_ms' => $latency,
        ];
    }

    return ['ok' => true, 'data' => $data, 'latency_ms' => $latency];
}

/**
 * Стриминг: читаем SSE провайдера и отдаём каждую порцию в $onDelta.
 *
 * Расход считаем по финальному чанку с usage — для этого в запрос
 * добавляется stream_options.include_usage. Если провайдер его не
 * прислал, остаётся оценка по символам, но это хуже и видно в логе.
 *
 * Вызовы инструментов собираются здесь же. Раньше разбирался только
 * delta.content, а delta.tool_calls отбрасывался — то есть при стриминге
 * с инструментами клиент не получал их вообще. Для агентов это значит,
 * что они не могут ни прочитать файл, ни записать: весь их рабочий цикл
 * построен на вызовах инструментов.
 *
 * Аргументы приходят по частям и склеиваются по индексу: провайдер
 * присылает имя в первом чанке, а JSON аргументов — кусками в следующих.
 *
 * @return array{content: string, tool_calls: array, usage: ?array,
 *               finish_reason: string, error: ?string, latency_ms: int}
 */
function sx_upstream_stream(array $model, array $payload, callable $onDelta): array
{
    $url = rtrim((string) $model['provider_base_url'], '/') . '/chat/completions';

    $payload['stream']         = true;
    $payload['stream_options'] = ['include_usage' => true];

    $state = [
        'buf' => '', 'full' => '', 'usage' => null, 'finish' => 'stop',
        'tools' => [], 'raw' => '',
    ];
    $t0 = microtime(true);

    $writer = function ($ch, string $chunk) use (&$state, $onDelta): int {
        // Начало тела храним отдельно: при отказе провайдер отвечает не
        // потоком SSE, а обычным JSON, и разобрать его как поток нельзя.
        // Храним с ограничением, чтобы длинный ответ не осел в памяти.
        if (strlen($state['raw']) < 4096) {
            $state['raw'] .= substr($chunk, 0, 4096 - strlen($state['raw']));
        }

        $state['buf'] .= $chunk;

        // Разбираем только завершённые строки: чанк curl может оборваться
        // посередине JSON, и парсить его целиком нельзя.
        while (($nl = strpos($state['buf'], "\n")) !== false) {
            $line = trim(substr($state['buf'], 0, $nl));
            $state['buf'] = substr($state['buf'], $nl + 1);

            if ($line === '' || strncmp($line, 'data:', 5) !== 0) {
                continue;
            }
            $raw = trim(substr($line, 5));
            if ($raw === '[DONE]') {
                continue;
            }
            $obj = json_decode($raw, true);
            if (!is_array($obj)) {
                continue;
            }
            if (!empty($obj['usage'])) {
                $state['usage'] = $obj['usage'];
            }
            if (!empty($obj['choices'][0]['finish_reason'])) {
                $state['finish'] = (string) $obj['choices'][0]['finish_reason'];
            }
            $delta = $obj['choices'][0]['delta']['content'] ?? '';
            if ($delta !== '' && is_string($delta)) {
                $state['full'] .= $delta;
                $onDelta($delta);
            }

            // Вызовы инструментов: имя приходит один раз, аргументы кусками
            $tcs = $obj['choices'][0]['delta']['tool_calls'] ?? null;
            if (is_array($tcs)) {
                foreach ($tcs as $tc) {
                    if (!is_array($tc)) {
                        continue;
                    }
                    $i = (int) ($tc['index'] ?? 0);

                    if (!isset($state['tools'][$i])) {
                        $state['tools'][$i] = [
                            'id'       => '',
                            'type'     => 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                    }

                    if (!empty($tc['id'])) {
                        $state['tools'][$i]['id'] = (string) $tc['id'];
                    }
                    if (!empty($tc['function']['name'])) {
                        $state['tools'][$i]['function']['name'] = (string) $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments']) && is_string($tc['function']['arguments'])) {
                        $state['tools'][$i]['function']['arguments'] .= $tc['function']['arguments'];
                    }
                }
            }
        }

        return strlen($chunk);
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . sx_provider_key($model),
            'Accept: text/event-stream',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_WRITEFUNCTION  => $writer,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 300,
        // Обрыв, если поток завис: меньше байта в секунду в течение 45с
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME  => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    curl_exec($ch);
    $err      = (string) curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Индексы приводим к списку: провайдер может прислать их не по порядку
    ksort($state['tools']);

    // Отказ провайдера приходит кодом 4xx/5xx и телом JSON, а не потоком.
    // Без этой проверки ошибка выглядела как успешный пустой ответ: curl
    // не сообщает об ошибке (на уровне HTTP запрос прошёл), строк data:
    // в теле нет, usage нет. Дальше шлюз считал выход по оценке и СПИСЫВАЛ
    // с клиента за ответ, которого не было. Для агентов это задевало каждый
    // запрос с неверной схемой инструмента.
    $error = null;
    if ($httpCode >= 400 && $state['full'] === '') {
        $error = 'upstream_http_' . $httpCode;
        error_log("[soulex] провайдер {$model['provider_slug']} отказал на стриме, код {$httpCode}");
    } elseif ($err !== '' && $state['full'] === '') {
        // Обрыв на середине ответа ошибкой не считаем: текст клиент уже увидел
        $error = $err;
    }

    return [
        'content'       => $state['full'],
        'tool_calls'    => array_values($state['tools']),
        'usage'         => $state['usage'],
        'finish_reason' => $state['finish'],
        'error'         => $error,
        'status'        => $httpCode,
        'reason'        => sx_upstream_reason($state['raw']),
        'latency_ms'    => (int) round((microtime(true) - $t0) * 1000),
    ];
}

/**
 * Классифицировать отказ провайдера, не пересылая его текст клиенту.
 *
 * Текст провайдера наружу не идёт: в нём бывают его внутренние адреса,
 * настоящие имена моделей и подсказки по его тарифам. Но клиенту нужно
 * знать, что исправить, поэтому отказ сводится к короткому коду, по
 * которому шлюз подбирает своё сообщение.
 */
function sx_upstream_reason(string $rawBody): ?string
{
    if ($rawBody === '') {
        return null;
    }

    // Рассуждающая модель израсходовала весь лимит выхода на рассуждение
    // и не дошла до ответа. Встречается на coder, lite и alpha при
    // max_tokens меньше примерно 256.
    if (stripos($rawBody, 'exhausted its output token limit') !== false) {
        return 'output_budget_too_small';
    }
    if (stripos($rawBody, 'Invalid model or alias') !== false) {
        return 'upstream_model_missing';
    }
    if (stripos($rawBody, 'validation failed') !== false) {
        return 'invalid_parameters';
    }
    if (stripos($rawBody, 'context length') !== false
        || stripos($rawBody, 'too many tokens') !== false) {
        return 'context_too_long';
    }

    return null;
}

/**
 * Грубая оценка числа токенов по тексту — страховка на случай, когда
 * провайдер не прислал usage. Намеренно завышена: недооценка означает
 * бесплатные запросы за наш счёт.
 */
function sx_fallback_token_count(string $text): int
{
    $byBytes = intdiv(strlen($text), 4) + 1;
    $byChars = intdiv((int) (mb_strlen($text) * 10), 25) + 1;
    return max($byBytes, $byChars, 1);
}
