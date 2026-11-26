<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../controllers/RoomController.php';

$controller = new RoomController();
$room = $controller->getRoom((int)($_GET['room_id'] ?? 0));
if (!$room) {
    http_response_code(404);
}
$checkIn = (string)($_GET['check_in'] ?? date('Y-m-d'));
$checkOut = (string)($_GET['check_out'] ?? date('Y-m-d', strtotime('+1 day')));
$startDate = DateTime::createFromFormat('!Y-m-d', $checkIn);
$endDate = DateTime::createFromFormat('!Y-m-d', $checkOut);
$validDates = $startDate && $endDate && $startDate->format('Y-m-d') === $checkIn
    && $endDate->format('Y-m-d') === $checkOut && $checkIn >= date('Y-m-d') && $checkOut > $checkIn;
$available = $room && $validDates && $room['availability_status'] === 'available'
    && $controller->datesAvailable((int)$room['room_id'], $checkIn, $checkOut);
$rate = $room && $validDates ? $controller->priceForDate((int)$room['room_id'], $checkIn) : ($room['price_per_night'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $room ? h($room['room_type'] . ' ' . $room['room_number']) : 'Room not found' ?> | RoomReservation</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-light navbar-custom"><div class="container"><a class="navbar-brand font-weight-bold text-primary" href="index.php">RoomReservation</a><a href="index.php" class="btn btn-outline-primary btn-sm">All rooms</a></div></nav>
<main class="container py-5">
    <?= renderFlash() ?>
    <?php if (!$room): ?>
        <div class="alert alert-warning">That room could not be found.</div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <?php if (!empty($room['images'])): ?>
                <div class="row no-gutters">
                    <?php foreach ($room['images'] as $image): ?>
                        <div class="col-md-6"><img src="<?= h($image['image_url']) ?>" class="img-fluid w-100 room-detail-image" alt="<?= h($image['alt_text'] ?: $room['room_type']) ?>"></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="card-body p-4">
                <span class="badge badge-<?= $available ? 'success' : 'secondary' ?>"><?= $available ? 'Available for these dates' : 'Not available for these dates' ?></span>
                <h1 class="h2 font-weight-bold mt-2"><?= h($room['room_type']) ?> · <?= h($room['room_number']) ?></h1>
                <p><?= nl2br(h($room['description'])) ?></p>
                <?php if ($room['room_type_description']): ?><p class="text-muted"><?= h($room['room_type_description']) ?></p><?php endif; ?>
                <p><strong>Capacity:</strong> <?= (int)$room['capacity'] ?> guests</p>
                <p><strong>Amenities:</strong> <?= h($room['amenities'] ?: 'Not specified') ?></p>
                <form method="get" class="form-inline mb-3">
                    <input type="hidden" name="room_id" value="<?= (int)$room['room_id'] ?>">
                    <label class="mr-2" for="check_in">Check-in</label><input id="check_in" type="date" name="check_in" class="form-control mr-3 mb-2" min="<?= h(date('Y-m-d')) ?>" value="<?= h($checkIn) ?>" required>
                    <label class="mr-2" for="check_out">Check-out</label><input id="check_out" type="date" name="check_out" class="form-control mr-3 mb-2" value="<?= h($checkOut) ?>" required>
                    <button class="btn btn-outline-primary mb-2">Check availability</button>
                </form>
                <p class="price-tag">₱<?= number_format((float)$rate, 2) ?><small class="d-block text-muted">per night for selected check-in date</small></p>
                <?php if ($available): ?>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a class="btn btn-success" href="book_room.php?room_id=<?= (int)$room['room_id'] ?>&check_in=<?= h($checkIn) ?>&check_out=<?= h($checkOut) ?>">Reserve this room</a>
                    <?php else: ?>
                        <a class="btn btn-primary" href="index.php?book_room=<?= (int)$room['room_id'] ?>">Login or register to reserve</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
