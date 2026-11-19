<?php

require_once __DIR__ . '/../includes/security-headers.php';

const SPA_SESSION_NAME = 'spa_session';
const SPA_SESSION_IDLE_MINUTES = 20;

// Public pages show friendly failures; technical details belong in PHP's log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function startSpaSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(SPA_SESSION_NAME);
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        http_response_code(503);
        throw new RuntimeException('A secure session could not be started. Please try again shortly.');
    }

    if (isset($_SESSION['user_id'])) {
        $now = time();
        // Existing Lab 2 sessions start their idle timer on the first visit after the upgrade.
        $lastActivity = $_SESSION['last_activity'] ?? $now;
        if (!is_int($lastActivity) || $lastActivity > $now || $now - $lastActivity >= SPA_SESSION_IDLE_MINUTES * 60) {
            $_SESSION = [];
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('The expired session could not be renewed.');
            }
            setAuthFlash('Your session expired after ' . SPA_SESSION_IDLE_MINUTES . ' minutes of inactivity. Please log in again.');
        } else {
            $_SESSION['last_activity'] = $now;
        }
    }
}

function refreshExistingSpaSession(): void
{
    if (isset($_COOKIE[SPA_SESSION_NAME])) {
        startSpaSession();
        session_write_close(); // Release the session lock before running the public AJAX query.
    }
}

function csrfToken(): string
{
    startSpaSession();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $error) {
            throw new RuntimeException('A secure form token could not be created. Please try again.', 0, $error);
        }
    }

    return $_SESSION['csrf_token'];
}

function validCsrfToken($token): bool
{
    startSpaSession();

    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function setAuthFlash(string $message): void
{
    startSpaSession();
    $_SESSION['auth_flash'] = $message;
}

function pullAuthFlash(): ?string
{
    startSpaSession();
    $message = $_SESSION['auth_flash'] ?? null;
    unset($_SESSION['auth_flash']);

    return is_string($message) ? $message : null;
}
