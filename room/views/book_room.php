<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../controllers/ReservationController.php';
require_once __DIR__ . '/../controllers/RoomController.php';
$requestedRoomId = (int)($_POST['room_id'] ?? $_GET['room_id'] ?? 0);
if (empty($_SESSION['user_id'])) {
    header('Location: index.php?book_room=' . $requestedRoomId);
    exit();
}
$userId = requireLogin();
$rooms = new RoomController();
$reservations = new ReservationController();
$roomId = $requestedRoomId;
$room = $rooms->getRoom($roomId);
$error = '';
$reference = '';
if (!$room || $room['availability_status'] !== 'available') {
    http_response_code(404);
    $room = null;
}

$checkInInput = $_POST['check_in_date'] ?? $_GET['check_in'] ?? date('Y-m-d');
$checkOutInput = $_POST['check_out_date'] ?? $_GET['check_out'] ?? date('Y-m-d', strtotime('+1 day'));
$checkIn = is_string($checkInInput) ? $checkInInput : '';
$checkOut = is_string($checkOutInput) ? $checkOutInput : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reservation'])) {
    try {
        verifyCsrf();
        $reference = $reservations->create($_POST, $userId);
        flash('success', 'Reservation request ' . $reference . ' was submitted for staff approval.');
        header('Location: dashboard.php');
        exit();
    } catch (Throwable $e) {
        error_log('Reservation request error: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage() : 'Your reservation could not be submitted. Please retry.';
    }
}

$startDate = DateTime::createFromFormat('!Y-m-d', $checkIn);
$endDate = DateTime::createFromFormat('!Y-m-d', $checkOut);
$validDates = $startDate && $endDate && $startDate->format('Y-m-d') === $checkIn
    && $endDate->format('Y-m-d') === $checkOut && $checkIn >= date('Y-m-d') && $checkOut > $checkIn
    && $startDate->diff($endDate)->days <= 365;
$available = $room && $validDates && $rooms->datesAvailable($roomId, $checkIn, $checkOut);
$estimate = 0.0;
if ($available) {
    $start = new DateTimeImmutable($checkIn);
    $end = new DateTimeImmutable($checkOut);
    for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
        $estimate += $rooms->priceForDate($roomId, $day->format('Y-m-d'));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request reservation | RoomReservation</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<main class="container py-5">
    <a href="index.php" class="btn btn-outline-secondary btn-sm mb-3">&larr; Browse rooms</a>
    <?= renderFlash() ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <?php if (!$room): ?>
        <div class="alert alert-warning">That room is not available for reservation.</div>
    <?php else: ?>
        <div class="card p-4 shadow-sm border-0">
            <h1 class="h4 font-weight-bold">Request a reservation</h1>
            <p class="text-muted"><?= h($room['room_type']) ?> · Room <?= h($room['room_number']) ?> · Capacity <?= (int)$room['capacity'] ?></p>
            <form method="get" class="form-row align-items-end mb-4" id="datesForm">
                <input type="hidden" name="room_id" value="<?= $roomId ?>">
                <div class="form-group col-md-4"><label for="check_in">Check-in</label><input id="check_in" type="date" name="check_in" class="form-control" min="<?= h(date('Y-m-d')) ?>" value="<?= h($checkIn) ?>" required></div>
                <div class="form-group col-md-4"><label for="check_out">Check-out</label><input id="check_out" type="date" name="check_out" class="form-control" value="<?= h($checkOut) ?>" required></div>
                <div class="form-group col-md-4"><button class="btn btn-outline-primary btn-block">Update stay dates</button></div>
            </form>
            <?php if (!$validDates): ?>
                <div class="alert alert-warning">Check-out must be after check-in, and check-in cannot be in the past.</div>
            <?php elseif (!$available): ?>
                <div class="alert alert-warning">This room already has a reservation request for those dates. Choose different dates or another room.</div>
            <?php else: ?>
                <div class="alert alert-info">
                    Estimated total for <?= (new DateTimeImmutable($checkIn))->diff(new DateTimeImmutable($checkOut))->days ?> night(s):
                    <strong>₱<?= number_format($estimate, 2) ?></strong>. Final price is stored with the reservation request.
                </div>
                <form method="post" id="reservationForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="submit_reservation" value="1">
                    <input type="hidden" name="room_id" value="<?= $roomId ?>">
                    <input type="hidden" name="check_in_date" value="<?= h($checkIn) ?>">
                    <input type="hidden" name="check_out_date" value="<?= h($checkOut) ?>">
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Guest name</label><input class="form-control" value="<?= h($_SESSION['full_name'] ?? '') ?>" readonly></div>
                        <div class="form-group col-md-6"><label>Account email</label><input class="form-control" value="<?= h($_SESSION['email'] ?? '') ?>" readonly></div>
                    </div>
                    <div class="form-group col-md-6 pl-0"><label for="guest_count">Number of guests</label><input id="guest_count" type="number" class="form-control" name="guest_count" min="1" max="<?= (int)$room['capacity'] ?>" value="<?= h($_POST['guest_count'] ?? 1) ?>" required></div>
                    <div class="form-group"><label for="guest_note">Special requests (optional)</label><textarea id="guest_note" name="guest_note" class="form-control" rows="3" maxlength="1000"><?= h($_POST['guest_note'] ?? '') ?></textarea></div>
                    <button type="submit" class="btn btn-success btn-lg btn-block">Submit request for staff approval</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
<script>
document.getElementById('reservationForm')?.addEventListener('submit', function (event) {
    if (!confirm('Submit this reservation request for staff approval?')) event.preventDefault();
});
document.getElementById('datesForm')?.addEventListener('submit', function (event) {
    if (document.getElementById('check_out').value <= document.getElementById('check_in').value) {
        event.preventDefault();
        alert('Check-out must be after check-in.');
    }
});
</script>
</body>
</html>
