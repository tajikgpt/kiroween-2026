<?php
/**
 * Soulex — совместимость с Messages API от Anthropic.
 *
 * Зачем это нужно: Claude Code говорит только на протоколе Anthropic
 * (POST /v1/messages), а не на OpenAI Chat Completions. Без этого
 * эндпоинта подключить его к нам нельзя ничем, кроме сторонней прослойки,
 * а это ровно та причина, по которой люди уходят к конкурентам.
 *
 * -----------------------------------------------------------------------------
 * ЧЕСТНОСТЬ ПЕРЕД КЛИЕНТОМ
 * -----------------------------------------------------------------------------
 * Протокол Anthropic мы поддерживаем, моделями Anthropic не притворяемся.
 * Claude Code присылает имена вида claude-sonnet-4-5-20250929, потому что
 * других не знает; мы сопоставляем их со своими моделями по уровню, а в
 * ответе возвращаем ИМЯ СВОЕЙ модели. Клиент всегда видит, что именно
 * обслужило запрос. Возвращать обратно "claude-..." означало бы продавать
 * одну модель под именем другой.
 *
 * Сопоставление можно обойти: если в ANTHROPIC_MODEL указать наш ID
 * напрямую (coder, agent, smart), он и будет использован.
 *
 * -----------------------------------------------------------------------------
 * Разница форматов, которую здесь закрываем
 * -----------------------------------------------------------------------------
 *   system          отдельное поле запроса   → сообщение с role: system
 *   content         строка или блоки         → строка или части OpenAI
 *   tool_use        блок в ответе ассистента → tool_calls
 *   tool_result     блок в сообщении user    → отдельное сообщение role: tool
 *   stop_sequences  →  stop
 *   tool_choice     {type: any}              → "required"
 *   stop_reason     end_turn / max_tokens / tool_use
 */

declare(strict_types=1);

require_once __DIR__ . '/billing.php';

/** Версия протокола, которую заявляем в ответах. */
const SX_ANTHROPIC_VERSION = '2023-06-01';

// =============================================================================
// Сопоставление моделей
// =============================================================================

/**
 * Имя модели из запроса → наш публичный ID.
 *
 * Сначала пробуем как есть: если человек прописал в ANTHROPIC_MODEL наш
 * ID, никакого сопоставления быть не должно. Дальше разбираем имена
 * Anthropic по уровню модели.
 *
 * Возвращает [наш_id, сопоставлено_ли]. Второй флаг нужен, чтобы в ответе
 * показать клиенту, что его имя подменили, и он не искал причину сам.
 *
 * @return array{0: ?string, 1: bool}
 */
function sx_anthropic_resolve_model(string $requested): array
{
    $requested = trim($requested);
    if ($requested === '') {
        return [null, false];
    }

    // Наш собственный ID — берём без изменений
    if (sx_load_model($requested) !== null) {
        return [$requested, false];
    }

    $lower = strtolower($requested);

    // Уровни Anthropic → наши модели. Порядок проверки важен: haiku
    // ищется раньше, иначе "claude-3-5-haiku" попадёт в ветку по умолчанию.
    //
    // Opus ведёт на coder, а НЕ на smart, хотя smart сильнее. Причина
    // в деньгах, и она проверена на живом запросе: Claude Code берёт
    // Opus моделью по умолчанию и присылает около 30 000 входных токенов
    // в каждом обращении. На smart с коэффициентом ×80 это 2 359 200
    // токенов за один запрос — приветственных 500 000 не хватает даже
    // на первую попытку, и человек делает вывод, что сервис не работает.
    // На coder тот же запрос стоит около 270 000.
    //
    // smart никуда не делся: его можно выбрать явно, указав ID модели
    // в ANTHROPIC_MODEL или ANTHROPIC_DEFAULT_OPUS_MODEL. Решение здесь
    // только про поведение по умолчанию.
    $tiers = [
        'haiku'  => 'fast',    // дешёвая и быстрая: заголовки, сжатие истории
        'sonnet' => 'coder',   // основная рабочая
        'opus'   => 'coder',
    ];

    foreach ($tiers as $needle => $ours) {
        if (str_contains($lower, $needle)) {
            // Модель может быть выключена в каталоге — тогда сопоставление
            // молча вернёт 404, и причина будет неочевидна.
            return [sx_load_model($ours) !== null ? $ours : 'coder', true];
        }
    }

    // Незнакомое имя из семейства claude-*: отправляем в основную рабочую,
    // иначе клиент получит 404 на модель, которую не выбирал.
    if (str_starts_with($lower, 'claude')) {
        return ['coder', true];
    }

    return [null, false];
}

// =============================================================================
// Запрос: Anthropic → OpenAI
// =============================================================================

/**
 * Значение → строка JSON, где пустота даёт {} , а не [] .
 *
 * В PHP пустой массив и пустой объект — одно и то же значение, и
 * json_encode([]) выдаёт "[]". Для аргументов инструмента это неверно:
 * по схеме там объект, и клиент, получив массив, либо падает на
 * разборе, либо передаёт инструменту не те данные. Ошибка проявляется
 * только на вызовах без аргументов, поэтому её легко не заметить.
 */
function sx_anthropic_json_object(mixed $value): string
{
    if (!is_array($value) || $value === []) {
        return '{}';
    }
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
}

/**
 * Значение → объект для вывода в JSON.
 *
 * Та же причина, что у sx_anthropic_json_object(), но для случая, когда
 * значение попадает в массив ответа, а не в строку.
 */
function sx_anthropic_as_object(mixed $value): mixed
{
    if (!is_array($value) || $value === []) {
        return new stdClass();
    }
    return $value;
}

/** Блоки текста или строка → одна строка. */
function sx_anthropic_flatten_text(mixed $content): string
{
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return '';
    }

    $out = [];
    foreach ($content as $block) {
        if (is_string($block)) {
            $out[] = $block;
            continue;
        }
        if (!is_array($block)) {
            continue;
        }
        if (($block['type'] ?? '') === 'text') {
            $out[] = (string) ($block['text'] ?? '');
        }
    }
    return implode("\n", $out);
}

/**
 * Сообщения Anthropic → сообщения OpenAI.
 *
 * Самая тонкая часть — инструменты. У Anthropic вызов инструмента лежит
 * блоком внутри сообщения ассистента, а результат — блоком внутри
 * следующего сообщения user. В OpenAI это два РАЗНЫХ сообщения, причём
 * role: tool обязано идти сразу после сообщения с tool_calls. Если
 * порядок нарушить, провайдер ответит ошибкой о висящем tool_call_id.
 */
function sx_anthropic_messages_to_openai(array $messages): array
{
    $out = [];

    foreach ($messages as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $role    = (string) ($msg['role'] ?? 'user');
        $content = $msg['content'] ?? '';

        // Простой случай: контент строкой
        if (is_string($content)) {
            if ($content !== '') {
                $out[] = ['role' => $role, 'content' => $content];
            }
            continue;
        }

        if (!is_array($content)) {
            continue;
        }

        $parts     = [];   // текст и изображения
        $toolCalls = [];   // вызовы инструментов от ассистента
        $toolMsgs  = [];   // результаты инструментов от пользователя

        foreach ($content as $block) {
            if (is_string($block)) {
                $parts[] = ['type' => 'text', 'text' => $block];
                continue;
            }
            if (!is_array($block)) {
                continue;
            }

            switch ((string) ($block['type'] ?? '')) {
                case 'text':
                    $parts[] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
                    break;

                case 'image':
                    // Anthropic передаёт картинку как base64 с media_type,
                    // OpenAI — как data-URL в image_url.
                    $src = is_array($block['source'] ?? null) ? $block['source'] : [];
                    if (($src['type'] ?? '') === 'base64') {
                        $parts[] = [
                            'type'      => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . (string) ($src['media_type'] ?? 'image/png')
                                    . ';base64,' . (string) ($src['data'] ?? ''),
                            ],
                        ];
                    } elseif (($src['type'] ?? '') === 'url') {
                        $parts[] = [
                            'type'      => 'image_url',
                            'image_url' => ['url' => (string) ($src['url'] ?? '')],
                        ];
                    }
                    break;

                case 'tool_use':
                    $toolCalls[] = [
                        'id'       => (string) ($block['id'] ?? ('toolu_' . bin2hex(random_bytes(8)))),
                        'type'     => 'function',
                        'function' => [
                            'name'      => (string) ($block['name'] ?? ''),
                            // OpenAI ждёт аргументы СТРОКОЙ с JSON,
                            // у Anthropic это готовый объект.
                            'arguments' => sx_anthropic_json_object($block['input'] ?? null),
                        ],
                    ];
                    break;

                case 'tool_result':
                    $toolMsgs[] = [
                        'role'         => 'tool',
                        'tool_call_id' => (string) ($block['tool_use_id'] ?? ''),
                        'content'      => sx_anthropic_flatten_text($block['content'] ?? ''),
                    ];
                    break;

                // Блоки рассуждения назад не отправляем: провайдер их
                // не принимает, а подписи проверить всё равно нечем.
                case 'thinking':
                case 'redacted_thinking':
                default:
                    break;
            }
        }

        // Результаты инструментов идут ПЕРЕД остатком сообщения: они
        // продолжают предыдущий ход ассистента.
        foreach ($toolMsgs as $tm) {
            $out[] = $tm;
        }

        if ($parts !== [] || $toolCalls !== []) {
            $entry = ['role' => $role];

            if ($parts !== []) {
                // Если только текст — отдаём строкой: так принимают все
                // провайдеры, включая те, что не умеют массив частей.
                $onlyText = true;
                foreach ($parts as $p) {
                    if (($p['type'] ?? '') !== 'text') {
                        $onlyText = false;
                        break;
                    }
                }
                $entry['content'] = $onlyText
                    ? implode("\n", array_map(static fn(array $p): string => (string) $p['text'], $parts))
                    : $parts;
            } else {
                $entry['content'] = null;
            }

            if ($toolCalls !== []) {
                $entry['tool_calls'] = $toolCalls;
            }

            $out[] = $entry;
        }
    }

    return $out;
}

/** Описания инструментов: Anthropic → OpenAI. */
function sx_anthropic_tools_to_openai(mixed $tools): array
{
    if (!is_array($tools)) {
        return [];
    }

    $out = [];
    foreach ($tools as $t) {
        if (!is_array($t)) {
            continue;
        }
        // Серверные инструменты Anthropic (web_search_*, computer_* и
        // прочие) выполняются на их стороне. У нас их нет, повторить
        // нечем — пропускаем, иначе провайдер отвергнет весь запрос
        // из-за отсутствия input_schema.
        if (!isset($t['input_schema']) && isset($t['type'])) {
            continue;
        }

        $name = (string) ($t['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $fn = ['name' => $name];
        if (isset($t['description'])) {
            $fn['description'] = (string) $t['description'];
        }
        $fn['parameters'] = is_array($t['input_schema'] ?? null)
            ? $t['input_schema']
            : ['type' => 'object', 'properties' => new stdClass()];

        $out[] = ['type' => 'function', 'function' => $fn];
    }

    return $out;
}

/** Выбор инструмента: Anthropic → OpenAI. */
function sx_anthropic_tool_choice_to_openai(mixed $choice): mixed
{
    if (is_string($choice)) {
        return $choice;
    }
    if (!is_array($choice)) {
        return null;
    }

    return match ((string) ($choice['type'] ?? '')) {
        'auto' => 'auto',
        'none' => 'none',
        // "any" значит «обязательно какой-нибудь инструмент»
        'any'  => 'required',
        'tool' => ['type' => 'function', 'function' => ['name' => (string) ($choice['name'] ?? '')]],
        default => null,
    };
}

// =============================================================================
// Ответ: OpenAI → Anthropic
// =============================================================================

/** Причина остановки: словарь OpenAI → словарь Anthropic. */
function sx_anthropic_stop_reason(?string $finish, bool $hasToolUse = false): string
{
    if ($hasToolUse) {
        return 'tool_use';
    }
    return match ($finish) {
        'length'          => 'max_tokens',
        'tool_calls'      => 'tool_use',
        'content_filter'  => 'refusal',
        'stop', null, ''  => 'end_turn',
        default           => 'end_turn',
    };
}

/**
 * Блоки содержимого ответа: текст и вызовы инструментов.
 *
 * Пустой текстовый блок не добавляем: при ответе одними инструментами
 * Claude Code получал бы блок с пустой строкой и показывал пустое
 * сообщение перед вызовом.
 */
function sx_anthropic_content_blocks(string $text, array $toolCalls): array
{
    $blocks = [];

    if ($text !== '') {
        $blocks[] = ['type' => 'text', 'text' => $text];
    }

    foreach ($toolCalls as $tc) {
        if (!is_array($tc)) {
            continue;
        }
        $argsRaw = (string) ($tc['function']['arguments'] ?? '');
        $args    = json_decode($argsRaw === '' ? '{}' : $argsRaw, true);

        $blocks[] = [
            'type'  => 'tool_use',
            'id'    => (string) ($tc['id'] ?? ('toolu_' . bin2hex(random_bytes(12)))),
            'name'  => (string) ($tc['function']['name'] ?? ''),
            // Битый JSON от провайдера и вызов без аргументов дают один
            // результат — пустой объект. Массив здесь недопустим: по
            // схеме input это объект, и SDK на массиве падает.
            'input' => sx_anthropic_as_object($args),
        ];
    }

    // Совсем пустой content недопустим по спецификации
    if ($blocks === []) {
        $blocks[] = ['type' => 'text', 'text' => ''];
    }

    return $blocks;
}

/** Ошибка в формате Anthropic. */
function sx_anthropic_error_type(int $status): string
{
    return match (true) {
        $status === 400 => 'invalid_request_error',
        $status === 401 => 'authentication_error',
        $status === 403 => 'permission_error',
        $status === 404 => 'not_found_error',
        // Нехватки средств в словаре Anthropic нет; ближайшее по смыслу,
        // что разбирают клиенты, — ошибка запроса. Текст объясняет суть.
        $status === 402 => 'invalid_request_error',
        $status === 413 => 'request_too_large',
        $status === 429 => 'rate_limit_error',
        $status === 529 => 'overloaded_error',
        $status >= 500  => 'api_error',
        default         => 'invalid_request_error',
    };
}
