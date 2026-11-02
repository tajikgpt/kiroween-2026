<?php
/**
 * TajikGPT — вход и сессии.
 *
 * Вход по одноразовому коду на почту. Пароля нет намеренно: нет пароля —
 * нет его утечки, нет восстановления, нет повторного использования чужого
 * пароля с другого сайта. Так же сделано в tajikgpt и это себя оправдало.
 *
 * Код хранится ТОЛЬКО хешем. Если файл с кодами утечёт, войти по нему
 * нельзя. Срок жизни 10 минут, попыток 5 — иначе шестизначный код
 * подбирается за минуты.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/keys.php';
// sx_client_ip() объявлена здесь. Без явного подключения файл работал
// только из веба, где boot.php подтягивал http.php заодно, и падал при
// вызове из консоли.
require_once __DIR__ . '/http.php';

const SX_OTP_TTL      = 600;   // сколько живёт код, секунд
const SX_OTP_TRIES     = 5;    // попыток ввода на один код
const SX_SESSION_NAME  = 'sx_sess';

/** Приветственные токены новому аккаунту: хватит на пробные запросы. */
// 100 000 оказалось бесполезно: на модели coder (×9) это 11 000 реальных
// токенов — один короткий обмен, а запрос от агента съедает 10–40 тысяч.
// Человек не мог проверить сервис тем инструментом, для которого его берёт.
// 500 000 = $0.025 по базовой ставке, наша закупка около $0.012 на аккаунт.
const SX_WELCOME_TOKENS = 500000;

// =============================================================================
// Сессия
// =============================================================================

function sx_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(SX_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 2592000,            // 30 дней
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'httponly' => true,               // недоступна из JavaScript
        'samesite' => 'Lax',              // переход по ссылке со стороны работает, POST извне — нет
    ]);
    session_start();
}

function sx_login(int $userId): void
{
    sx_session_start();
    // Новый идентификатор после входа — защита от фиксации сессии:
    // чужой заранее подсунутый cookie становится недействительным.
    session_regenerate_id(true);
    $_SESSION['uid']    = $userId;
    $_SESSION['authed'] = time();
}

function sx_logout(): void
{
    sx_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

/** Текущий пользователь или null. Данные читаются из хранилища, не из сессии. */
function sx_current_user(): ?array
{
    sx_session_start();
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($uid <= 0) {
        return null;
    }

    // В сессии держим только id. Баланс и блокировку читаем каждый раз:
    // иначе заблокированный админом аккаунт продолжал бы работать
    // до истечения cookie.
    $u = sx_user_load($uid);
    if ($u === null || !empty($u['is_blocked'])) {
        sx_logout();
        return null;
    }
    return $u;
}

/** Требовать вход. Иначе — на страницу входа с возвратом назад. */
function sx_require_login(): array
{
    $u = sx_current_user();
    if ($u === null) {
        $back = $_SERVER['REQUEST_URI'] ?? '/app';
        header('Location: /login?next=' . urlencode($back));
        exit;
    }
    return $u;
}

// =============================================================================
// Защита от подделки запросов
// =============================================================================

function sx_csrf(): string
{
    sx_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function sx_csrf_ok(?string $token): bool
{
    sx_session_start();
    $want = (string) ($_SESSION['csrf'] ?? '');
    // hash_equals — сравнение за постоянное время, чтобы по скорости
    // ответа нельзя было угадывать токен посимвольно.
    return $want !== '' && is_string($token) && hash_equals($want, $token);
}

// =============================================================================
// Одноразовые коды
// =============================================================================

function sx_otp_key(string $email): string
{
    return 'otp/' . hash('sha256', strtolower(trim($email))) . '.json';
}

/**
 * Выдать код. Возвращает сам код — его надо отправить на почту
 * и больше нигде не хранить в открытом виде.
 */
function sx_otp_issue(string $email): string
{
    $email = strtolower(trim($email));
    $code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    sx_write_json(sx_otp_key($email), [
        'email'      => $email,
        'code_hash'  => hash('sha256', $code),
        'expires_at' => time() + SX_OTP_TTL,
        'tries'      => 0,
        'created_at' => time(),
        'ip'         => sx_client_ip(),
    ]);

    return $code;
}

/**
 * Проверить код.
 *
 * @return array{ok: bool, error?: string}
 */
function sx_otp_verify(string $email, string $code): array
{
    $email = strtolower(trim($email));
    $rel   = sx_otp_key($email);

    return sx_with_lock('otp_' . hash('sha256', $email), function () use ($rel, $code): array {
        $d = sx_read_json($rel);
        if ($d === null) {
            return ['ok' => false, 'error' => 'Код не запрашивался или уже использован.'];
        }
        if (time() > (int) $d['expires_at']) {
            return ['ok' => false, 'error' => 'Код истёк. Запросите новый.'];
        }
        if ((int) $d['tries'] >= SX_OTP_TRIES) {
            return ['ok' => false, 'error' => 'Слишком много попыток. Запросите новый код.'];
        }

        // Счётчик попыток увеличиваем ДО проверки: иначе прерванный
        // запрос не учитывался бы и ограничение можно было обойти.
        $d['tries'] = (int) $d['tries'] + 1;
        sx_write_json($rel, $d);

        if (!hash_equals((string) $d['code_hash'], hash('sha256', trim($code)))) {
            $left = SX_OTP_TRIES - (int) $d['tries'];
            return ['ok' => false, 'error' => "Неверный код. Осталось попыток: {$left}."];
        }

        // Код одноразовый — удаляем сразу после успеха
        @unlink(sx_path($rel));
        return ['ok' => true];
    });
}

// =============================================================================
// Отправка письма
// =============================================================================

/**
 * Отправить код на почту.
 *
 * На этом хостинге SMTP не настроен, поэтому используется mail() —
 * на cPanel она работает для писем с адреса своего домена.
 *
 * Если отправка не удалась, код пишется в data/otp_fallback.log, чтобы
 * вход можно было завершить вручную, а не оставлять человека с пустым
 * экраном. Файл лежит вне веб-корня.
 */
function sx_send_code(string $email, string $code): bool
{
    $site = 'TajikGPT';
    $from = 'no-reply@tajikgpt.com';

    $subject = '=?UTF-8?B?' . base64_encode("{$code} — код входа в {$site}") . '?=';

    $body = "Код для входа в {$site}:\n\n"
          . "    {$code}\n\n"
          . "Код действует 10 минут. Если вы не запрашивали вход — просто\n"
          . "проигнорируйте это письмо.\n\n"
          . "https://tajikgpt.com/\n";

    $headers = implode("\r\n", [
        "From: {$site} <{$from}>",
        "Reply-To: {$from}",
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: TajikGPT',
    ]);

    $sent = @mail($email, $subject, $body, $headers, "-f{$from}");

    if (!$sent) {
        error_log("[tajikgpt] письмо с кодом не ушло: {$email}");
        @file_put_contents(
            sx_path('otp_fallback.log'),
            gmdate('c') . " {$email} {$code}\n",
            FILE_APPEND | LOCK_EX
        );
    }

    return (bool) $sent;
}

// =============================================================================
// Вход или регистрация
// =============================================================================

/**
 * Найти пользователя по почте, а если его нет — создать,
 * выдать приветственные токены и первый ключ.
 *
 * @return array{user: array, is_new: bool, key?: string}
 */
function sx_login_or_register(string $email): array
{
    $email = strtolower(trim($email));

    $existing = sx_user_by_email($email);
    if ($existing !== null) {
        $existing['last_login_at'] = gmdate('c');
        $existing['last_login_ip'] = sx_client_ip();
        sx_user_save($existing);
        return ['user' => $existing, 'is_new' => false];
    }

    $u = sx_user_create($email);
    $u['last_login_at'] = gmdate('c');
    $u['last_login_ip'] = sx_client_ip();
    sx_user_save($u);

    $uid = (int) $u['id'];

    // Приветственные токены: без них новый аккаунт не может сделать ни
    // одного запроса, даже к бесплатной модели — та требует ненулевого
    // баланса. Человек должен иметь возможность проверить сервис до оплаты.
    sx_credit_tokens($uid, SX_WELCOME_TOKENS, 'bonus', "welcome_{$uid}", 'приветственные токены');

    // Первый ключ выдаём сразу: иначе после входа человек видит пустой
    // кабинет и не понимает, с чего начать.
    $key = sx_issue_key($uid, 'default');

    return ['user' => sx_user_load($uid), 'is_new' => true, 'key' => $key];
}
