<?php
/**
 * Подключение приватной части.
 *
 * Код и данные лежат ВНЕ веб-корня. Раскладка на shared-хостинге такая:
 *
 *   <домашний каталог>/example.com/        веб-корень, сюда смотрит Apache
 *   <домашний каталог>/example_private/    lib, config, .env и data
 *
 * Приватный каталог не обслуживается ни одним виртуальным хостом, то
 * есть .env и файлы с балансами недоступны по прямой ссылке. Это важнее,
 * чем кажется: при хранении данных в файлах единственное, что отделяет
 * балансы от публичного скачивания, — расположение каталога.
 *
 * Перебор нескольких путей нужен, чтобы те же страницы работали
 * локально, где раскладка другая (web/ и lib/ рядом).
 */

declare(strict_types=1);

// Файл только для подключения. Прямое обращение отдаёт 404: ничего
// полезного оно не выводит, но и отвечать 200 на него незачем.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

// __DIR__ здесь — это <веб-корень>/inc
$sxRoots = [
    dirname(__DIR__, 2) . '/example_private',  // сервер: приватный каталог рядом с веб-корнем
    dirname(__DIR__, 2),                       // локально: корень проекта, рядом lib/
    dirname(__DIR__),                          // если lib/ лежит внутри веб-корня
];

$sxRoot = null;
foreach ($sxRoots as $candidate) {
    if (is_file($candidate . '/lib/billing.php')) {
        $sxRoot = $candidate;
        break;
    }
}

if ($sxRoot === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("TajikGPT: приватная часть не найдена.\nПроверены: " . implode(', ', $sxRoots) . "\n");
}

define('SX_ROOT', $sxRoot);

require_once SX_ROOT . '/lib/billing.php';
require_once SX_ROOT . '/lib/keys.php';
require_once SX_ROOT . '/lib/http.php';   // sx_client_ip() нужен на странице записи
