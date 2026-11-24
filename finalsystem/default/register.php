<?php
session_start();
require_once __DIR__ . '/config/Database.php';

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$fullName = '';
$username = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (
        $fullName === '' || mb_strlen($fullName) > 120 ||
        $username === '' || mb_strlen($username) > 50 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 240 ||
        strlen($password) < 8 ||
        $password !== $passwordConfirmation
    ) {
        $error = 'Enter a name, username, valid email, and matching password of at least 8 characters.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $result = pg_query_params(
            $conn,
            'INSERT INTO users (full_name, username, email, password_hash)
             VALUES ($1, $2, $3, $4)',
            [$fullName, $username, $email, $passwordHash]
        );

        if ($result === false) {
            $databaseError = pg_last_error($conn);
            error_log('User registration failed: ' . $databaseError);
            if (str_contains($databaseError, 'users_full_name_key')) {
                $error = 'That full name is already registered.';
            } elseif (str_contains($databaseError, 'users_username_key')) {
                $error = 'That username is already taken.';
            } elseif (str_contains($databaseError, 'users_email_key')) {
                $error = 'That email address is already registered.';
            } else {
                $error = 'Your account could not be created. Please try again later.';
            }
        } else {
            $_SESSION['registration_success'] = 'Registration successful. You can now log in.';
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register | Room Reservation</title>
    <link rel="stylesheet" href="assets/registration.css">
</head>
<body class="auth-page">
    <main class="auth-layout auth-layout-register">
        <a class="auth-brand auth-brand-register" href="index.php">Inventory</a>
        <section class="auth-card auth-register-card">
            <header class="auth-card-header">
                <h1>Account registration</h1>
                <p>Complete the form below to create an account.</p>
            </header>

            <?php if ($error !== ''): ?>
                <div class="notice notice-error" role="alert"><?= escape($error) ?></div>
            <?php endif; ?>

            <form method="post" class="auth-form auth-register-form">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <label class="auth-field">
                    <span>Full name</span>
                    <input type="text" name="full_name" placeholder="Enter your full name" maxlength="120" autocomplete="name" required value="<?= escape($fullName) ?>">
                </label>
                <label class="auth-field">
                    <span>Username</span>
                    <input type="text" name="username" placeholder="Choose a username" maxlength="50" autocomplete="username" required value="<?= escape($username) ?>">
                </label>
                <label class="auth-field">
                    <span>Email</span>
                    <input type="email" name="email" placeholder="Enter your email address" maxlength="240" autocomplete="email" required value="<?= escape($email) ?>">
                </label>
                <label class="auth-field">
                    <span>Password</span>
                    <input type="password" name="password" placeholder="Create a password (8 characters minimum)" minlength="8" autocomplete="new-password" required>
                </label>
                <label class="auth-field">
                    <span>Confirm password</span>
                    <input type="password" name="password_confirmation" placeholder="Confirm your password" minlength="8" autocomplete="new-password" required>
                </label>
                <button class="auth-submit auth-register-submit" type="submit">Create account</button>
            </form>

            <p class="auth-switch">Already registered? <a href="login.php">Sign in</a></p>
        </section>
    </main>
</body>
</html>