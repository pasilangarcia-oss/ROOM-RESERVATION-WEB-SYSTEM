<?php
require_once __DIR__ . '/../models/AuthModel.php';
require_once __DIR__ . '/../config/helpers.php';

class AuthController
{
    private AuthModel $auth;

    public function __construct(?AuthModel $auth = null)
    {
        $this->auth = $auth ?? new AuthModel();
    }

    public function handleAuth(): string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['auth_action'])) {
            return '';
        }
        try {
            verifyCsrf();
            $action = (string)$_POST['auth_action'];
            if ($action === 'login') {
                $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
                $user = $email ? $this->auth->findByEmail($email) : null;
                if (!$user || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                    throw new RuntimeException('Invalid email or password.');
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['contact'] = $user['contact_number'];
                $_SESSION['role'] = $user['role'];
                $this->redirectAfterAuth();
            }
            if ($action === 'register') {
                $name = trim((string)($_POST['full_name'] ?? ''));
                $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
                $contact = trim((string)($_POST['contact_number'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                if (strlen($name) < 2 || strlen($name) > 120 || !$email
                    || strlen($contact) < 5 || strlen($contact) > 40 || strlen($password) < 10) {
                    throw new RuntimeException('Enter a valid name, email, contact number, and password (at least 10 characters).');
                }
                $userId = $this->auth->register($name, $email, $password, $contact);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['full_name'] = $name;
                $_SESSION['email'] = $email;
                $_SESSION['contact'] = $contact;
                $_SESSION['role'] = 'guest';
                $this->redirectAfterAuth();
            }
            throw new RuntimeException('Unknown account action.');
        } catch (Throwable $e) {
            error_log('Authentication error: ' . $e->getMessage());
            return '<div class="alert alert-danger">' . h($this->publicError($e)) . '</div>';
        }
    }

    private function redirectAfterAuth(): void
    {
        $roomId = filter_var($_POST['redirect_room'] ?? '', FILTER_VALIDATE_INT);
        header('Location: ' . ($roomId ? 'book_room.php?room_id=' . $roomId : 'dashboard.php'));
        exit();
    }

    private function publicError(Throwable $e): string
    {
        if ($e instanceof PDOException && $e->getCode() === '23505') {
            return 'That email address is already registered. Please log in.';
        }
        return $e instanceof RuntimeException ? $e->getMessage() : 'Unable to process your account request. Please try again.';
    }
}
