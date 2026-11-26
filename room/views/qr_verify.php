<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../controllers/ReservationController.php';
$staffId = requireStaff();
$controller = new ReservationController();
$qrInput = trim((string)($_POST['qr_value'] ?? ''));
$record = null;
$message = '';
$messageType = 'info';
$checkInReady = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'verify') {
            $result = $controller->verifyQr($qrInput, $staffId);
            $record = $result['reservation'];
            $checkInReady = $result['success'];
            if ($result['success']) {
                $message = 'QR code and reservation are valid for today.';
                $messageType = 'success';
            } else {
                $message = 'Access denied. The QR code is invalid, inactive, or the guest arrival date does not match today.';
                $messageType = 'danger';
            }
        } elseif ($action === 'checkin') {
            $result = $controller->checkIn($qrInput, $staffId);
            $record = $result['success'] ? $controller->reservationFromQr($qrInput) : $result['reservation'];
            $message = $result['success'] ? 'Check-in completed and logged.' : 'Check-in denied. Verify that the reservation is approved and scheduled for today.';
            $messageType = $result['success'] ? 'success' : 'danger';
        } elseif ($action === 'checkout') {
            $controller->checkout(
                (int)($_POST['reservation_id'] ?? 0), $staffId, trim((string)($_POST['note'] ?? ''))
            );
            flash('success', 'Check-out completed. The reservation and room availability have been updated.');
            header('Location: qr_verify.php');
            exit();
        } else {
            throw new InvalidArgumentException('Unknown check-in action.');
        }
    } catch (Throwable $e) {
        error_log('Check-in/out error: ' . $e->getMessage());
        $message = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage() : 'The check-in or check-out action failed.';
        $messageType = 'danger';
    }
}
$checkedIn = $controller->checkedInReservations();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR check-in and checkout | RoomReservation</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://unpkg.com/html5-qrcode"></script>
</head>
<body>
<nav class="navbar navbar-light navbar-custom"><div class="container"><a class="navbar-brand font-weight-bold text-primary" href="index.php">RoomReservation</a><a href="staff.php" class="btn btn-outline-primary btn-sm">Staff dashboard</a></div></nav>
<main class="container py-4">
    <h1 class="h3 font-weight-bold">QR check-in and checkout</h1>
    <?= renderFlash() ?>
    <?php if ($message): ?><div class="alert alert-<?= h($messageType) ?>"><?= h($message) ?></div><?php endif; ?>
    <div class="row">
        <div class="col-lg-5 mb-4">
            <section class="card p-4 border-0 shadow-sm">
                <h2 class="h5 font-weight-bold">Scan or enter reservation QR</h2>
                <div id="qr-reader" class="mb-3"></div>
                <form method="post" id="qrScanForm">
                    <?= csrfField() ?><input type="hidden" name="action" value="verify">
                    <label for="qr_value">QR token</label>
                    <div class="input-group">
                        <input type="text" name="qr_value" id="qr_value" class="form-control" value="<?= h($qrInput) ?>" maxlength="64" required autocomplete="off">
                        <div class="input-group-append"><button class="btn btn-primary">Verify QR</button></div>
                    </div>
                </form>
                <small class="text-muted mt-2">A reservation QR is valid only after approval and on its scheduled check-in date.</small>
            </section>
        </div>
        <div class="col-lg-7 mb-4">
            <section class="card p-4 border-0 shadow-sm">
                <h2 class="h5 font-weight-bold">Reservation validation</h2>
                <?php if ($record): ?>
                    <dl class="row mb-3">
                        <dt class="col-sm-4">Reservation ID</dt><dd class="col-sm-8"><code><?= h($record['booking_reference']) ?></code></dd>
                        <dt class="col-sm-4">Guest</dt><dd class="col-sm-8"><?= h($record['guest_name']) ?> · <?= h($record['guest_contact']) ?></dd>
                        <dt class="col-sm-4">Room</dt><dd class="col-sm-8"><?= h($record['room_type']) ?> · <?= h($record['room_number']) ?></dd>
                        <dt class="col-sm-4">Dates</dt><dd class="col-sm-8"><?= h($record['check_in_date']) ?> – <?= h($record['check_out_date']) ?></dd>
                        <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= h($record['reservation_status']) ?></dd>
                    </dl>
                <?php else: ?>
                    <p class="text-muted">Reservation details will appear here after scanning or entering a QR code.</p>
                <?php endif; ?>
                <?php if ($checkInReady): ?>
                    <form method="post" onsubmit="return confirm('Verify the guest identity and complete check-in?');">
                        <?= csrfField() ?><input type="hidden" name="action" value="checkin"><input type="hidden" name="qr_value" value="<?= h($qrInput) ?>">
                        <button class="btn btn-success btn-block">Confirm guest check-in</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </div>
    <section class="card p-4 border-0 shadow-sm">
        <h2 class="h5 font-weight-bold">Checked-in guests ready for checkout</h2>
        <?php if (!$checkedIn): ?><p class="text-muted mb-0">No guests are currently checked in.</p><?php endif; ?>
        <?php foreach ($checkedIn as $booking): ?>
            <form method="post" class="border rounded p-3 mb-2" onsubmit="return confirm('Complete checkout and close this reservation?');">
                <?= csrfField() ?><input type="hidden" name="action" value="checkout"><input type="hidden" name="reservation_id" value="<?= (int)$booking['reservation_id'] ?>">
                <div class="form-row align-items-end">
                    <div class="col-md-4"><strong><?= h($booking['booking_reference']) ?></strong><br><?= h($booking['guest_name']) ?> · <?= h($booking['room_type']) ?> #<?= h($booking['room_number']) ?></div>
                    <div class="col-md-3"><label class="small">Final transaction amount</label><div>₱<?= number_format((float)$booking['total_price'], 2) ?></div></div>
                    <div class="form-group col-md-3"><label class="small">Checkout note</label><input name="note" class="form-control form-control-sm" maxlength="1000"></div>
                    <div class="col-md-2"><button class="btn btn-primary btn-sm btn-block">Complete checkout</button></div>
                </div>
            </form>
        <?php endforeach; ?>
    </section>
</main>
<script>
if (typeof Html5QrcodeScanner !== 'undefined') {
    var scanner = new Html5QrcodeScanner('qr-reader', { fps: 8, qrbox: 220 });
    scanner.render(function (decodedText) {
        document.getElementById('qr_value').value = decodedText;
        document.getElementById('qrScanForm').requestSubmit();
    });
}
</script>
</body>
</html>
