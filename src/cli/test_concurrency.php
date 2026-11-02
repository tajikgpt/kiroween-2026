<?php
/**
 * Soulex — проверка параллельных списаний.
 *
 * Это главный тест файлового хранилища. Он отвечает на вопрос, из-за
 * которого обычно и отказываются от txt: теряются ли списания, когда
 * несколько запросов приходят одновременно одним ключом.
 *
 * Запуск:  php cli/test_concurrency.php
 *
 * Тест запускает настоящие параллельные процессы PHP, а не имитирует
 * их в одном. Для сравнения тем же способом проверяется подход из
 * tajikgpt (config.php:743-785), где блокировка стоит только на записи.
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Режим рабочего процесса
// -----------------------------------------------------------------------------
if (($argv[1] ?? '') === 'worker') {
    $dataDir = (string) $argv[2];
    $userId  = (int) $argv[3];
    $keyHash = (string) $argv[4];
    $rounds  = (int) $argv[5];

    putenv("DATA_DIR={$dataDir}");
    $_ENV['DATA_DIR'] = $dataDir;

    require_once __DIR__ . '/../lib/billing.php';

    $model   = sx_load_model('coder');
    $charged = 0;
    $denied  = 0;

    for ($i = 0; $i < $rounds; $i++) {
        try {
            $rid = sx_reserve($userId, $keyHash, $model, 3000, 4096);
        } catch (SxInsufficientTokens $e) {
            $denied++;
            continue;
        }
        // Фиксированный расход, чтобы итог можно было посчитать заранее
        $charged += sx_settle($rid, $model, 3000, 700, 0, 10, $userId);
    }

    echo json_encode(['charged' => $charged, 'denied' => $denied]);
    exit(0);
}

// -----------------------------------------------------------------------------
// Режим рабочего процесса для наивного подхода (как в tajikgpt)
// -----------------------------------------------------------------------------
if (($argv[1] ?? '') === 'naive-worker') {
    $file   = (string) $argv[2];
    $amount = (int) $argv[3];
    $rounds = (int) $argv[4];

    for ($i = 0; $i < $rounds; $i++) {
        // Ровно схема из config.php:743-785 — читаем без блокировки,
        // пишем с LOCK_EX. Между чтением и записью окно не защищено.
        $lines     = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $new_lines = [];
        foreach ($lines as $line) {
            $u = json_decode($line, true);
            if ($u && ($u['user_id'] ?? '') === 'u1') {
                if ((int) $u['balance'] >= $amount) {
                    $u['balance'] = (int) $u['balance'] - $amount;
                }
            }
            $new_lines[] = json_encode($u);
        }
        file_put_contents($file, implode("\n", $new_lines) . "\n", LOCK_EX);
    }
    exit(0);
}

// =============================================================================
// Режим запуска
// =============================================================================

$tmp = sys_get_temp_dir() . '/soulex_conc_' . getmypid();
putenv("DATA_DIR={$tmp}");
$_ENV['DATA_DIR'] = $tmp;

require_once __DIR__ . '/../lib/billing.php';
require_once __DIR__ . '/../lib/keys.php';

register_shutdown_function(function () use ($tmp) {
    if (is_dir($tmp)) {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

$php     = PHP_BINARY;
$self    = __FILE__;
$WORKERS = 12;
$ROUNDS  = 10;

$passed = 0;
$failed = 0;

function check_true(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        printf("  ok    %-46s %s\n", $name, $detail);
    } else {
        $failed++;
        printf("  FAIL  %-46s %s\n", $name, $detail);
    }
}

/** Запустить команды параллельно и собрать вывод. */
function run_parallel(array $cmds): array
{
    $procs = [];
    foreach ($cmds as $i => $cmd) {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($p === false) {
            throw new RuntimeException('не удалось запустить процесс');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $procs[$i] = ['p' => $p, 'pipes' => $pipes, 'out' => '', 'err' => ''];
    }

    // Читаем, пока процессы живы: иначе заполненный буфер вывода
    // заблокирует рабочий процесс насмерть.
    $running = true;
    while ($running) {
        $running = false;
        foreach ($procs as &$pr) {
            $pr['out'] .= (string) stream_get_contents($pr['pipes'][1]);
            $pr['err'] .= (string) stream_get_contents($pr['pipes'][2]);
            $st = proc_get_status($pr['p']);
            if ($st['running']) {
                $running = true;
            }
        }
        unset($pr);
        if ($running) {
            usleep(20000);
        }
    }

    $results = [];
    foreach ($procs as $i => $pr) {
        $pr['out'] .= (string) stream_get_contents($pr['pipes'][1]);
        $pr['err'] .= (string) stream_get_contents($pr['pipes'][2]);
        fclose($pr['pipes'][1]);
        fclose($pr['pipes'][2]);
        proc_close($pr['p']);
        $results[$i] = ['out' => trim($pr['out']), 'err' => trim($pr['err'])];
    }
    return $results;
}

// -----------------------------------------------------------------------------
echo "\n";
echo "==============================================================\n";
echo " ПАРАЛЛЕЛЬНЫЕ СПИСАНИЯ\n";
echo "==============================================================\n";
printf(" процессов: %d, запросов в каждом: %d, всего: %d\n\n", $WORKERS, $ROUNDS, $WORKERS * $ROUNDS);

$model       = sx_load_model('coder');
$perRequest  = sx_charge_tokens($model, 3000, 700);
$total       = $WORKERS * $ROUNDS;

// Баланса заведомо хватает на все запросы — проверяем точность учёта,
// а не поведение при нехватке
$initial = $perRequest * $total * 2;

$u = sx_user_create('conc@soulex.cc');
sx_credit_tokens((int) $u['id'], $initial, 'purchase', 'SX_CONC');
$key  = sx_issue_key((int) $u['id'], 'conc');
$auth = sx_resolve_key($key);

printf(" списание за запрос: %d т\n", $perRequest);
printf(" начальный баланс:   %s т\n", number_format($initial, 0, '.', ' '));
printf(" ожидаемое списание: %s т\n\n", number_format($perRequest * $total, 0, '.', ' '));

$cmds = [];
for ($i = 0; $i < $WORKERS; $i++) {
    $cmds[] = sprintf(
        '%s %s worker %s %d %s %d',
        escapeshellarg($php), escapeshellarg($self),
        escapeshellarg($tmp), (int) $u['id'], escapeshellarg($auth['key']['hash']), $ROUNDS
    );
}

$t0      = microtime(true);
$results = run_parallel($cmds);
$elapsed = microtime(true) - $t0;

$reportedCharged = 0;
$errors          = [];
foreach ($results as $i => $r) {
    // Предупреждение об отсутствующем .env в тестах ожидаемо: боевые
    // ключи здесь не нужны, запросов к провайдеру тест не делает.
    $err = trim(preg_replace('/^\[soulex\] \.env не найден.*$/m', '', $r['err']) ?? '');
    if ($err !== '') {
        $errors[] = "процесс {$i}: " . substr($err, 0, 200);
    }
    $d = json_decode($r['out'], true);
    if (is_array($d)) {
        $reportedCharged += (int) ($d['charged'] ?? 0);
    } else {
        $errors[] = "процесс {$i}: нет ответа ({$r['out']})";
    }
}

printf(" выполнено за %.2f с (%.0f запросов/с)\n\n", $elapsed, $total / max(0.001, $elapsed));

check_true('все процессы отработали без ошибок', $errors === [],
    $errors === [] ? '' : implode('; ', array_slice($errors, 0, 2)));

$final      = sx_user_load((int) $u['id']);
$actualDrop = $initial - (int) $final['balance_tokens'];
$expected   = $perRequest * $total;

printf(" процессы сообщили о списании: %s т\n", number_format($reportedCharged, 0, '.', ' '));
printf(" фактически ушло с баланса:    %s т\n", number_format($actualDrop, 0, '.', ' '));
printf(" должно было уйти:             %s т\n\n", number_format($expected, 0, '.', ' '));

check_true('списано ровно столько, сколько израсходовано',
    $actualDrop === $expected,
    $actualDrop === $expected ? 'ни одного потерянного списания' : 'расхождение ' . ($expected - $actualDrop) . ' т');

check_true('отчёт процессов совпал с балансом', $reportedCharged === $actualDrop);
check_true('резервов не осталось', (int) $final['reserved_tokens'] === 0,
    $final['reserved_tokens'] . ' т');

// Инвариант после нагрузки
$sum = 0;
foreach (sx_read_jsonl_from('ledger/' . $u['id'] . '.jsonl', 0) as $r) {
    $sum += (int) ($r['amount'] ?? 0);
}
printf(" баланс %s + резерв %d = %s, реестр = %s\n",
    number_format((int) $final['balance_tokens'], 0, '.', ' '),
    (int) $final['reserved_tokens'],
    number_format((int) $final['balance_tokens'] + (int) $final['reserved_tokens'], 0, '.', ' '),
    number_format($sum, 0, '.', ' '));
check_true('инвариант держится после нагрузки',
    (int) $final['balance_tokens'] + (int) $final['reserved_tokens'] === $sum);

// Каждый запрос оставил запись о расходе
$usage = sx_usage_recent((int) $u['id'], 1000);
printf(" записей о расходе: %d\n", count($usage));
check_true('запись есть на каждый запрос', count($usage) === $total,
    count($usage) . ' из ' . $total);

// -----------------------------------------------------------------------------
echo "\n";
echo "==============================================================\n";
echo " ТО ЖЕ САМОЕ СХЕМОЙ ИЗ tajikgpt (config.php:743-785)\n";
echo "==============================================================\n";
echo " Блокировка только на записи, чтение не защищено.\n\n";

$naiveFile = $tmp . '/naive_users.txt';
$naiveInit = 10_000_000;
$naiveAmt  = 1000;
file_put_contents($naiveFile, json_encode(['user_id' => 'u1', 'balance' => $naiveInit]) . "\n");

$cmds = [];
for ($i = 0; $i < $WORKERS; $i++) {
    $cmds[] = sprintf('%s %s naive-worker %s %d %d',
        escapeshellarg($php), escapeshellarg($self),
        escapeshellarg($naiveFile), $naiveAmt, $ROUNDS);
}
run_parallel($cmds);

$naiveRaw      = (string) file_get_contents($naiveFile);
$naiveAfter    = json_decode(trim(explode("\n", $naiveRaw)[0] ?? ''), true);
$naiveExpected = $naiveAmt * $total;

printf(" должно было уйти: %s т\n", number_format($naiveExpected, 0, '.', ' '));

if (!is_array($naiveAfter) || !isset($naiveAfter['balance'])) {
    // Это худший исход, чем потерянное списание: один процесс прочитал
    // файл в момент, когда другой его перезаписывал, получил из
    // json_decode() null, и записал обратно строку "null". Запись о
    // пользователе уничтожена вместе с балансом.
    printf(" состояние файла:  ЗАПИСЬ УНИЧТОЖЕНА\n");
    printf(" содержимое:       %s\n\n", substr(trim($naiveRaw), 0, 60));
    printf("  Схема из tajikgpt сломалась сильнее, чем ожидалось: это не\n");
    printf("  потеря одного списания, а потеря самого пользователя.\n");
    printf("  Процесс прочитал файл в момент чужой перезаписи, получил из\n");
    printf("  json_decode() null и записал обратно \"null\".\n\n");
    $passed++;
} else {
    $naiveDrop = $naiveInit - (int) $naiveAfter['balance'];
    $lost      = $naiveExpected - $naiveDrop;
    printf(" фактически ушло:  %s т\n", number_format($naiveDrop, 0, '.', ' '));

    if ($lost > 0) {
        printf(" ПОТЕРЯНО:         %s т (%.1f%% расхода бесплатно)\n\n",
            number_format($lost, 0, '.', ' '), $lost * 100 / $naiveExpected);
        printf("  Подтверждено: наивная схема теряет списания.\n");
        printf("  На продаже токенов это касса, из которой утекает.\n\n");
        $passed++;
    } else {
        printf("\n  На этой машине гонка не воспроизвелась. Это не значит,\n");
        printf("  что схема безопасна: результат зависит от скорости диска\n");
        printf("  и планировщика. На боевой нагрузке потери будут.\n\n");
    }
}

echo str_repeat('-', 62) . "\n";
printf("Пройдено: %d   Провалено: %d\n\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
