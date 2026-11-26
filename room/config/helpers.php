<?php

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function verifyCsrf(): void
{
    $provided = $_POST['csrf_token'] ?? '';
    if (!is_string($provided) || !hash_equals(csrfToken(), $provided)) {
        http_response_code(400);
        throw new RuntimeException('Your session expired. Reload the page and try again.');
    }
}

function requireLogin(): int
{
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php?auth=required');
        exit();
    }
    return (int)$_SESSION['user_id'];
}

function requireStaff(): int
{
    $userId = requireLogin();
    if (!in_array($_SESSION['role'] ?? 'guest', ['staff', 'admin'], true)) {
        http_response_code(403);
        exit('Staff access required.');
    }
    return $userId;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function renderFlash(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $type = in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info';
    return '<div class="alert alert-' . $type . '">' . h($flash['message']) . '</div>';
}
