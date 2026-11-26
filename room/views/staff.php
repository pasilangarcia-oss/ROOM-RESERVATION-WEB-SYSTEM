<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../controllers/ReservationController.php';
require_once __DIR__ . '/../controllers/RoomController.php';
$staffId = requireStaff();
$reservations = new ReservationController();
$rooms = new RoomController();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'decision') {
            $reservations->decide(
                (int)($_POST['reservation_id'] ?? 0), $staffId,
                (string)($_POST['decision'] ?? ''), trim((string)($_POST['note'] ?? ''))
            );
            flash('success', 'Reservation decision recorded. QR confirmation was issued for approved bookings.');
        } elseif ($action === 'save_room') {
            $rooms->saveRoom((int)($_POST['room_id'] ?? 0) ?: null);
            flash('success', 'Room details saved.');
        } elseif ($action === 'deactivate_room') {
            require_once __DIR__ . '/../models/RoomModel.php';
            (new RoomModel())->deactivateRoom((int)($_POST['room_id'] ?? 0));
            flash('success', 'Room marked inactive. Its reservation history has been retained.');
        } else {
            throw new InvalidArgumentException('Unknown staff action.');
        }
        header('Location: staff.php');
        exit();
    } catch (Throwable $e) {
        error_log('Staff action error: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage() : ($e instanceof PDOException && $e->getCode() === '23505'
                ? 'A room number or other unique value is already in use.' : 'The staff action could not be completed.');
    }
}

$pending = $reservations->pending();
$roomList = $rooms->allRooms();
$roomTypes = $rooms->roomTypes();
$amenities = $rooms->amenities();
$editingRoom = !empty($_GET['edit_room']) ? $rooms->getRoom((int)$_GET['edit_room']) : null;
$summary = $reservations->reportSummary();
$logs = $reservations->checkinLogs();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff management | RoomReservation</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-light navbar-custom"><div class="container">
    <a class="navbar-brand font-weight-bold text-primary" href="index.php">RoomReservation</a>
    <div><a href="qr_verify.php" class="btn btn-dark btn-sm">QR check-in / checkout</a> <a href="pricing.php" class="btn btn-outline-primary btn-sm">Dynamic pricing</a> <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Account</a></div>
</div></nav>
<main class="container py-4">
    <h1 class="h3 font-weight-bold mb-4">Staff dashboard</h1>
    <?= renderFlash() ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <div class="row mb-4">
        <?php foreach ([
            'Reservations' => (int)$summary['totals']['reservation_count'],
            'Pending review' => (int)$summary['totals']['pending_count'],
            'Currently checked in' => (int)$summary['totals']['checked_in_count'],
            'Completed revenue' => '₱' . number_format((float)$summary['totals']['completed_revenue'], 2),
        ] as $label => $value): ?>
            <div class="col-md-3 mb-2"><div class="card p-3 border-0 shadow-sm h-100"><small class="text-muted"><?= h($label) ?></small><strong class="h5 mb-0"><?= h($value) ?></strong></div></div>
        <?php endforeach; ?>
    </div>

    <section class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 font-weight-bold">Reservation requests</h2>
            <?php if (!$pending): ?><p class="text-muted mb-0">No pending requests.</p><?php endif; ?>
            <?php foreach ($pending as $booking): ?>
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex flex-wrap justify-content-between">
                        <div>
                            <strong><?= h($booking['booking_reference']) ?></strong> · <?= h($booking['guest_name']) ?>
                            <p class="small mb-1"><?= h($booking['room_type']) ?> (<?= h($booking['room_number']) ?>), <?= h($booking['check_in_date']) ?> – <?= h($booking['check_out_date']) ?></p>
                            <p class="small mb-0"><?= (int)$booking['guest_count'] ?> guest(s) · ₱<?= number_format((float)$booking['total_price'], 2) ?> · <?= h($booking['guest_email']) ?> / <?= h($booking['guest_contact']) ?></p>
                            <?php if ($booking['guest_note']): ?><p class="small mb-0"><strong>Request:</strong> <?= h($booking['guest_note']) ?></p><?php endif; ?>
                        </div>
                        <form method="post" class="mt-2 mt-md-0">
                            <?= csrfField() ?><input type="hidden" name="action" value="decision"><input type="hidden" name="reservation_id" value="<?= (int)$booking['reservation_id'] ?>">
                            <input name="note" class="form-control form-control-sm mb-2" maxlength="1000" placeholder="Optional staff note">
                            <button name="decision" value="Approved" class="btn btn-success btn-sm" onclick="return confirm('Approve and issue the reservation QR code?');">Approve</button>
                            <button name="decision" value="Rejected" class="btn btn-outline-danger btn-sm" onclick="return confirm('Reject this reservation request?');">Reject</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card border-0 shadow-sm mb-4" id="room-management">
        <div class="card-body">
            <h2 class="h5 font-weight-bold"><?= $editingRoom ? 'Update room ' . h($editingRoom['room_number']) : 'Add a room' ?></h2>
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?><input type="hidden" name="action" value="save_room"><input type="hidden" name="room_id" value="<?= (int)($editingRoom['room_id'] ?? 0) ?>">
                <div class="form-row">
                    <div class="form-group col-md-2"><label>Room number</label><input name="room_number" class="form-control" maxlength="30" value="<?= h($editingRoom['room_number'] ?? '') ?>" required></div>
                    <div class="form-group col-md-3"><label>Room type</label><select name="room_type_id" class="form-control" required><option value="">Select</option><?php foreach ($roomTypes as $type): ?><option value="<?= (int)$type['room_type_id'] ?>" <?= (string)($editingRoom['room_type_id'] ?? '') === (string)$type['room_type_id'] ? 'selected' : '' ?>><?= h($type['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group col-md-2"><label>Capacity</label><input type="number" min="1" name="capacity" class="form-control" value="<?= h($editingRoom['capacity'] ?? 1) ?>" required></div>
                    <div class="form-group col-md-2"><label>Base nightly price</label><input type="number" min="0" step="0.01" name="base_price" class="form-control" value="<?= h($editingRoom['base_price'] ?? '') ?>" required></div>
                    <div class="form-group col-md-3"><label>Availability</label><select name="availability_status" class="form-control"><option value="available" <?= ($editingRoom['availability_status'] ?? 'available') === 'available' ? 'selected' : '' ?>>Available</option><option value="maintenance" <?= ($editingRoom['availability_status'] ?? '') === 'maintenance' ? 'selected' : '' ?>>Maintenance</option><option value="inactive" <?= ($editingRoom['availability_status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
                </div>
                <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="2" required><?= h($editingRoom['description'] ?? '') ?></textarea></div>
                <div class="form-group"><span class="d-block">Amenities</span><?php foreach ($amenities as $amenity): $chosen = array_map('intval', explode(',', (string)($editingRoom['amenity_ids'] ?? ''))); ?>
                    <label class="form-check-inline mr-3"><input type="checkbox" name="amenity_ids[]" value="<?= (int)$amenity['amenity_id'] ?>" <?= in_array((int)$amenity['amenity_id'], $chosen, true) ? 'checked' : '' ?>> <?= h($amenity['name']) ?></label>
                <?php endforeach; ?></div>
                <div class="form-row">
                    <div class="form-group col-md-6"><label>Room image upload (JPG, PNG, WebP; up to 5 MB)</label><input type="file" name="room_image" class="form-control-file" accept="image/jpeg,image/png,image/webp"></div>
                    <div class="form-group col-md-6"><label>Or image URL (optional)</label><input type="url" name="image_url" class="form-control" placeholder="https://example.com/room.jpg"></div>
                </div>
                <button class="btn btn-primary"><?= $editingRoom ? 'Save room changes' : 'Add room' ?></button>
                <?php if ($editingRoom): ?><a href="staff.php#room-management" class="btn btn-outline-secondary">Cancel edit</a><?php endif; ?>
            </form>
        </div>
    </section>

    <section class="card border-0 shadow-sm mb-4"><div class="card-body">
        <h2 class="h5 font-weight-bold">Room inventory</h2>
        <div class="table-responsive"><table class="table table-sm table-striped">
            <thead><tr><th>Room</th><th>Type</th><th>Capacity</th><th>Base rate</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody><?php foreach ($roomList as $room): ?><tr>
                <td><?= h($room['room_number']) ?></td><td><?= h($room['room_type']) ?></td><td><?= (int)$room['capacity'] ?></td><td>₱<?= number_format((float)$room['base_price'], 2) ?></td><td><?= h($room['availability_status']) ?></td>
                <td><a href="staff.php?edit_room=<?= (int)$room['room_id'] ?>#room-management" class="btn btn-outline-primary btn-sm">Edit</a>
                    <?php if ($room['availability_status'] !== 'inactive'): ?><form method="post" class="d-inline" onsubmit="return confirm('Mark this room inactive? Reservation history is retained.');"><?= csrfField() ?><input type="hidden" name="action" value="deactivate_room"><input type="hidden" name="room_id" value="<?= (int)$room['room_id'] ?>"><button class="btn btn-outline-danger btn-sm">Deactivate</button></form><?php endif; ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    </div></section>

    <section class="card border-0 shadow-sm"><div class="card-body">
        <h2 class="h5 font-weight-bold">Recent check-in verification logs</h2>
        <div class="table-responsive"><table class="table table-sm">
            <thead><tr><th>Time</th><th>Reservation</th><th>Result</th><th>Details</th><th>Staff</th></tr></thead>
            <tbody><?php foreach ($logs as $log): ?><tr><td><?= h($log['occurred_at']) ?></td><td><?= h($log['booking_reference'] ?? 'Unknown') ?></td><td><?= h($log['result']) ?></td><td><?= h($log['message']) ?></td><td><?= h($log['staff_name'] ?? '—') ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
    </div></section>
    <section class="card border-0 shadow-sm mt-4"><div class="card-body">
        <h2 class="h5 font-weight-bold">Reservation transaction summary</h2>
        <div class="table-responsive"><table class="table table-sm">
            <thead><tr><th>Transaction type</th><th>Events</th><th>Recorded amount</th></tr></thead>
            <tbody><?php foreach ($summary['transactions'] as $transaction): ?><tr>
                <td><?= h($transaction['transaction_type']) ?></td>
                <td><?= (int)$transaction['event_count'] ?></td>
                <td>₱<?= number_format((float)$transaction['total_amount'], 2) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    </div></section>
</main>
</body>
</html>
