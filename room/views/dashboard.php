<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../models/AuthModel.php';
require_once __DIR__ . '/../controllers/ReservationController.php';
$userId = requireLogin();
$auth = new AuthModel();
$reservationController = new ReservationController();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        if (($_POST['action'] ?? '') === 'cancel') {
            $qrValue = is_string($_POST['qr_value'] ?? null) && $_POST['qr_value'] !== ''
                ? (string)$_POST['qr_value'] : null;
            $reservationController->cancel((int)($_POST['reservation_id'] ?? 0), $userId, $qrValue);
            flash('success', 'Reservation cancelled.');
        } elseif (($_POST['action'] ?? '') === 'profile') {
            $name = trim((string)($_POST['full_name'] ?? ''));
            $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
            $contact = trim((string)($_POST['contact_number'] ?? ''));
            $password = (string)($_POST['new_password'] ?? '');
            if (strlen($name) < 2 || strlen($name) > 120 || !$email || strlen($contact) < 5 || strlen($contact) > 40) {
                throw new InvalidArgumentException('Enter a valid name, email address, and contact number.');
            }
            if ($password !== '') {
                $current = (string)($_POST['current_password'] ?? '');
                $hash = $auth->passwordHash($userId);
                if (!$hash || !password_verify($current, $hash) || strlen($password) < 10) {
                    throw new InvalidArgumentException('Enter your current password and a new password of at least 10 characters.');
                }
            }
            $auth->updateProfile($userId, [
                'full_name' => $name, 'email' => $email, 'contact_number' => $contact,
                'address' => trim((string)($_POST['address'] ?? '')) ?: null,
                'emergency_contact_name' => trim((string)($_POST['emergency_contact_name'] ?? '')) ?: null,
                'emergency_contact_number' => trim((string)($_POST['emergency_contact_number'] ?? '')) ?: null,
                'password' => $password,
            ]);
            $_SESSION['full_name'] = $name;
            $_SESSION['email'] = $email;
            $_SESSION['contact'] = $contact;
            flash('success', 'Your profile was updated.');
        } else {
            throw new InvalidArgumentException('Unknown account action.');
        }
        header('Location: dashboard.php');
        exit();
    } catch (Throwable $e) {
        error_log('Dashboard update error: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage() : ($e instanceof PDOException && $e->getCode() === '23505'
                ? 'That email address is already in use.' : 'The requested change could not be saved.');
    }
}

$profile = $auth->getProfile($userId);
$bookings = $reservationController->forUser($userId, [
    'search' => trim((string)($_GET['search'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My dashboard | RoomReservation</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-light navbar-custom">
    <div class="container">
        <a class="navbar-brand font-weight-bold text-primary" href="index.php">RoomReservation</a>
        <div>
            <a href="index.php" class="btn btn-outline-primary btn-sm">Browse rooms</a>
            <?php if (in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)): ?><a href="staff.php" class="btn btn-dark btn-sm">Staff tools</a><?php endif; ?>
            <a href="../logout.php" class="btn btn-danger btn-sm">Log out</a>
        </div>
    </div>
</nav>
<main class="container py-4">
    <h1 class="h3 font-weight-bold mb-4">Welcome, <?= h($_SESSION['full_name'] ?? 'Guest') ?></h1>
    <?= renderFlash() ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <section class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 font-weight-bold">Profile and account settings</h2>
            <form method="post">
                <?= csrfField() ?><input type="hidden" name="action" value="profile">
                <div class="form-row">
                    <div class="form-group col-md-4"><label>Full name</label><input name="full_name" class="form-control" value="<?= h($profile['full_name'] ?? '') ?>" maxlength="120" required></div>
                    <div class="form-group col-md-4"><label>Email</label><input type="email" name="email" class="form-control" value="<?= h($profile['email'] ?? '') ?>" maxlength="190" required></div>
                    <div class="form-group col-md-4"><label>Contact number</label><input name="contact_number" class="form-control" value="<?= h($profile['contact_number'] ?? '') ?>" maxlength="40" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4"><label>Address</label><input name="address" class="form-control" value="<?= h($profile['address'] ?? '') ?>" maxlength="255"></div>
                    <div class="form-group col-md-4"><label>Emergency contact name</label><input name="emergency_contact_name" class="form-control" value="<?= h($profile['emergency_contact_name'] ?? '') ?>" maxlength="120"></div>
                    <div class="form-group col-md-4"><label>Emergency contact number</label><input name="emergency_contact_number" class="form-control" value="<?= h($profile['emergency_contact_number'] ?? '') ?>" maxlength="40"></div>
                </div>
                <details class="mb-3">
                    <summary>Change password</summary>
                    <div class="form-row mt-3">
                        <div class="form-group col-md-6"><label>Current password</label><input type="password" name="current_password" class="form-control" autocomplete="current-password"></div>
                        <div class="form-group col-md-6"><label>New password (at least 10 characters)</label><input type="password" name="new_password" class="form-control" minlength="10" autocomplete="new-password"></div>
                    </div>
                </details>
                <button class="btn btn-primary">Save profile</button>
            </form>
        </div>
    </section>

    <section>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <h2 class="h4 font-weight-bold mb-2">My reservations</h2>
            <form method="get" class="form-inline">
                <input type="search" name="search" class="form-control form-control-sm mr-2 mb-2" placeholder="Search reference or room" value="<?= h($_GET['search'] ?? '') ?>">
                <select name="status" class="form-control form-control-sm mr-2 mb-2">
                    <option value="">All statuses</option>
                    <?php foreach (['Pending', 'Approved', 'Rejected', 'Checked-In', 'Completed', 'Cancelled'] as $status): ?>
                        <option value="<?= h($status) ?>" <?= ($_GET['status'] ?? '') === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline-primary btn-sm mb-2">Filter</button>
            </form>
        </div>
        <?php if (!$bookings): ?>
            <div class="card p-5 text-center border-0 shadow-sm"><p class="text-muted">No reservations match your search yet.</p><a href="index.php" class="btn btn-primary mx-auto">Browse available rooms</a></div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($bookings as $booking): ?>
                    <div class="col-lg-6 mb-4">
                        <article class="card p-3 shadow-sm border-0 h-100">
                            <div class="row">
                                <div class="col-sm-7">
                                    <span class="badge badge-primary mb-2"><?= h($booking['reservation_status']) ?></span>
                                    <h3 class="h5 font-weight-bold"><?= h($booking['room_type']) ?> · <?= h($booking['room_number']) ?></h3>
                                    <p class="small mb-1"><strong>Reservation ID:</strong> <code><?= h($booking['booking_reference']) ?></code></p>
                                    <p class="small mb-1"><strong>Stay:</strong> <?= h($booking['check_in_date']) ?> – <?= h($booking['check_out_date']) ?></p>
                                    <p class="small mb-1"><strong>Guests:</strong> <?= (int)$booking['guest_count'] ?> · <strong>Total:</strong> ₱<?= number_format((float)$booking['total_price'], 2) ?></p>
                                    <?php if (in_array($booking['reservation_status'], ['Pending', 'Approved'], true)): ?>
                                        <form method="post" class="mt-3" onsubmit="return confirm('Cancel this reservation?');">
                                            <?= csrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="reservation_id" value="<?= (int)$booking['reservation_id'] ?>">
                                            <?php if ($booking['reservation_status'] === 'Approved'): ?><input type="hidden" name="qr_value" value="<?= h($booking['qr_code_value'] ?? '') ?>"><?php endif; ?>
                                            <button class="btn btn-outline-danger btn-sm">Request cancellation</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php if ($booking['qr_code_value'] && $booking['qr_is_active']): ?>
                                    <div class="col-sm-5 text-center qr-card">
                                        <div class="reservation-qr mx-auto" data-qr="<?= h($booking['qr_code_value']) ?>"></div>
                                        <small class="d-block mt-2">Present this QR code on check-in date</small>
                                    </div>
                                <?php elseif ($booking['reservation_status'] === 'Pending'): ?>
                                    <div class="col-sm-5 d-flex align-items-center"><p class="small text-muted">The QR confirmation will appear here after staff approval.</p></div>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.reservation-qr').forEach(function (element) {
    new QRCode(element, { text: element.dataset.qr, width: 140, height: 140 });
});
</script>
</body>
</html>
