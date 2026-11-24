<?php
session_start();
require_once __DIR__ . '/config/database.php';

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$identifier = '';
$success = $_SESSION['registration_success'] ?? '';
unset($_SESSION['registration_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($identifier === '' || $password === '') {
        $error = 'Enter your username or email and password.';
    } else {
        $result = pg_query_params(
            $conn,
            'SELECT user_id, full_name, username, password_hash
             FROM users
             WHERE username = $1 OR email = $1
             LIMIT 1',
            [$identifier]
        );

        if ($result === false) {
            error_log('User login lookup failed: ' . pg_last_error($conn));
            http_response_code(500);
            $error = 'Login is temporarily unavailable. Please try again later.';
        } else {
            $user = pg_fetch_assoc($result);
            if ($user === false || !password_verify($password, $user['password_hash'])) {
                $error = 'The username, email, or password is incorrect.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: products/index.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in | Inventory</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
    <main class="auth-layout auth-layout-login">
        <section class="auth-intro">
            <a class="auth-brand" href="index.php">Inventory</a>
            <h1>Manage your inventory with ease.</h1>
            <p>Sign in to keep your products organized and your business moving.</p>
        </section>

        <section class="auth-content">
            <div class="auth-card">
                <h2>Log in</h2>
                <p class="auth-card-subtitle">Welcome back. Please enter your details.</p>

                <?php if ($success !== ''): ?>
                    <div class="notice notice-success" role="status"><?= escape($success) ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="notice notice-error" role="alert"><?= escape($error) ?></div>
                <?php endif; ?>

                <form method="post" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                    <label class="auth-field">
                        <span>Email or username</span>
                        <input type="text" name="identifier" placeholder="Email or username" autocomplete="username" required value="<?= escape($identifier) ?>">
                    </label>
                    <label class="auth-field">
                        <span>Password</span>
                        <input type="password" name="password" placeholder="Password" autocomplete="current-password" required>
                    </label>
                    <button class="auth-submit" type="submit">Log in</button>
                </form>

                <div class="auth-divider"><span>or</span></div>
                <a class="auth-create-button" href="registration.php">Create new account</a>
            </div>
        </section>
    </main>
</body>
</html>