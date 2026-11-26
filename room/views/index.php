<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/RoomController.php';

$authMessage = '';
$roomData = [];
$databaseError = '';
$searchError = '';
try {
    $authMessage = (new AuthController())->handleAuth();
    $roomData = (new RoomController())->getRooms(isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
} catch (InvalidArgumentException $e) {
    $searchError = $e->getMessage();
} catch (Throwable $e) {
    error_log('Room browsing error: ' . $e->getMessage());
    $databaseError = 'Rooms could not be loaded. Confirm PostgreSQL is running, its connection settings are correct, and the schema has been imported.';
}
$rooms = $roomData['exact'] ?? [];
$recommended = $roomData['recommended'] ?? [];
$types = $roomData['room_types'] ?? [];
$amenities = $roomData['amenities'] ?? [];
$filters = $roomData['filters'] ?? [];
$isLoggedIn = isset($_SESSION['user_id']);
$selectedAmenities = $filters['amenity_ids'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RoomReservation | Find your stay</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navbar-custom">
    <div class="container">
        <a class="navbar-brand font-weight-bold text-primary" href="index.php">RoomReservation</a>
        <div class="ml-auto">
            <?php if ($isLoggedIn): ?>
                <a href="dashboard.php" class="btn btn-outline-primary btn-sm">My dashboard</a>
                <?php if (in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)): ?>
                    <a href="staff.php" class="btn btn-dark btn-sm">Staff tools</a>
                <?php endif; ?>
                <a href="../logout.php" class="btn btn-danger btn-sm">Log out</a>
            <?php else: ?>
                <button class="btn btn-primary btn-sm" onclick="showAuth('')">Login / Register</button>
            <?php endif; ?>
        </div>
    </div>
</nav>

<header class="hero-banner text-center">
    <div class="container">
        <h1 class="font-weight-bold">A room that fits your plans</h1>
        <p class="mb-0">Browse freely, compare live rates, and get a recommendation for your stay.</p>
    </div>
</header>

<main class="container pb-5">
    <?= $authMessage ?>
    <?= renderFlash() ?>
    <?php if ($searchError): ?><div class="alert alert-warning"><?= h($searchError) ?></div><?php endif; ?>
    <?php if ($databaseError): ?>
        <div class="alert alert-danger"><?= h($databaseError) ?> See <code>database/schema.sql</code> for setup.</div>
    <?php endif; ?>

    <section class="assistant-box mb-5">
        <h2 class="h5 font-weight-bold mb-3">Assisted room preference</h2>
        <form method="get" action="index.php" class="row" id="preferenceForm">
            <input type="hidden" name="assisted_search" value="1">
            <div class="col-md-2 form-group">
                <label for="budget">Max nightly budget</label>
                <input id="budget" type="number" min="0" step="0.01" name="budget" class="form-control" value="<?= h($filters['budget'] ?? '') ?>">
            </div>
            <div class="col-md-2 form-group">
                <label for="guests">Guests</label>
                <input id="guests" type="number" min="1" name="guests" class="form-control" value="<?= h($filters['guests'] ?? '') ?>">
            </div>
            <div class="col-md-2 form-group">
                <label for="room_type_id">Room type</label>
                <select id="room_type_id" name="room_type_id" class="form-control">
                    <option value="">Any type</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= (int)$type['room_type_id'] ?>" <?= (string)($filters['room_type_id'] ?? '') === (string)$type['room_type_id'] ? 'selected' : '' ?>><?= h($type['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 form-group">
                <label for="check_in">Check-in</label>
                <input id="check_in" type="date" name="check_in" class="form-control" min="<?= h(date('Y-m-d')) ?>" value="<?= h($filters['check_in'] ?? date('Y-m-d')) ?>" required>
            </div>
            <div class="col-md-2 form-group">
                <label for="check_out">Check-out</label>
                <input id="check_out" type="date" name="check_out" class="form-control" min="<?= h(date('Y-m-d', strtotime('+1 day'))) ?>" value="<?= h($filters['check_out'] ?? date('Y-m-d', strtotime('+1 day'))) ?>" required>
            </div>
            <div class="col-md-2 form-group">
                <label for="search">Search rooms</label>
                <input id="search" type="search" name="search" class="form-control" value="<?= h($filters['search'] ?? '') ?>" placeholder="Room or keyword">
            </div>
            <?php if ($amenities): ?>
                <div class="col-12 form-group mb-2">
                    <span class="small font-weight-bold d-block mb-1">Preferred amenities</span>
                    <?php foreach ($amenities as $amenity): ?>
                        <label class="form-check-inline small mr-3">
                            <input type="checkbox" name="amenity_ids[]" value="<?= (int)$amenity['amenity_id'] ?>" <?= in_array((int)$amenity['amenity_id'], $selectedAmenities, true) ? 'checked' : '' ?>>
                            <?= h($amenity['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="col-md-3 ml-auto">
                <button type="submit" class="btn btn-primary btn-block">Find matching rooms</button>
            </div>
        </form>
    </section>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 font-weight-bold mb-0"><?= !empty($_GET['assisted_search']) ? 'Rooms for your stay' : 'Available rooms' ?></h2>
            <small class="text-muted">Rates are recalculated using active date-based pricing rules.</small>
        </div>
    </div>

    <?php if (!$rooms && !$recommended && !$databaseError): ?>
        <div class="alert alert-info">No rooms match those dates and filters. Try changing your search or dates.</div>
    <?php endif; ?>
    <?php if ($recommended): ?>
        <div class="alert alert-info"><strong>No exact matches.</strong> These available rooms are the closest alternatives for your dates and guest count.</div>
        <div class="row">
            <?php foreach ($recommended as $room): ?>
                <?php renderRoomCard($room, true, $isLoggedIn); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($rooms): ?>
        <div class="row">
            <?php foreach ($rooms as $room): ?>
                <?php renderRoomCard($room, false, $isLoggedIn); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<div class="modal fade" id="authModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content p-3">
            <div class="modal-header border-0">
                <h2 class="h5 modal-title font-weight-bold">Your account</h2>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-pills nav-fill mb-3">
                    <li class="nav-item"><a class="nav-link active" data-toggle="pill" href="#tabLogin">Login</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="pill" href="#tabRegister">Register</a></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tabLogin">
                        <form method="post" action="index.php">
                            <?= csrfField() ?>
                            <input type="hidden" name="auth_action" value="login">
                            <input type="hidden" name="redirect_room" id="loginRedirectRoom">
                            <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" required autocomplete="email"></div>
                            <div class="form-group"><label>Password</label><input type="password" name="password" class="form-control" required autocomplete="current-password"></div>
                            <button type="submit" class="btn btn-primary btn-block">Log in</button>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="tabRegister">
                        <form method="post" action="index.php">
                            <?= csrfField() ?>
                            <input type="hidden" name="auth_action" value="register">
                            <input type="hidden" name="redirect_room" id="regRedirectRoom">
                            <div class="form-group"><label>Full name</label><input type="text" name="full_name" class="form-control" maxlength="120" required></div>
                            <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" maxlength="190" required autocomplete="email"></div>
                            <div class="form-group"><label>Contact number</label><input type="tel" name="contact_number" class="form-control" maxlength="40" required></div>
                            <div class="form-group"><label>Password (at least 10 characters)</label><input type="password" name="password" class="form-control" minlength="10" required autocomplete="new-password"></div>
                            <button type="submit" class="btn btn-success btn-block">Create account</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showAuth(roomId) {
    document.getElementById('loginRedirectRoom').value = roomId;
    document.getElementById('regRedirectRoom').value = roomId;
    $('#authModal').modal('show');
}
<?php if (!empty($_GET['book_room'])): ?>
showAuth(<?= (int)$_GET['book_room'] ?>);
<?php elseif (($_GET['auth'] ?? '') === 'required'): ?>
showAuth('');
<?php endif; ?>
document.getElementById('preferenceForm').addEventListener('submit', function (event) {
    var checkIn = document.getElementById('check_in').value;
    var checkOut = document.getElementById('check_out').value;
    if (checkOut <= checkIn) {
        event.preventDefault();
        alert('Check-out must be after check-in.');
    }
});
</script>
</body>
</html>

<?php
function renderRoomCard(array $room, bool $isRecommended, bool $isLoggedIn): void
{
    $img = $room['image_url'] ?: '';
    ?>
    <div class="col-md-4 mb-4">
        <article class="room-card">
            <?php if ($img): ?>
                <img src="<?= h($img) ?>" class="room-img" alt="<?= h($room['room_type'] . ' ' . $room['room_number']) ?>" loading="lazy">
            <?php else: ?>
                <div class="room-img room-placeholder" role="img" aria-label="Room photo unavailable">Room <?= h($room['room_number']) ?></div>
            <?php endif; ?>
            <div class="p-3 d-flex flex-column flex-grow-1">
                <span class="badge <?= $isRecommended ? 'badge-info' : 'badge-success' ?> mb-2 align-self-start"><?= $isRecommended ? 'Recommended' : 'Available' ?></span>
                <h3 class="h5 font-weight-bold"><?= h($room['room_type']) ?> · <?= h($room['room_number']) ?></h3>
                <p class="small mb-1"><strong>Capacity:</strong> <?= (int)$room['capacity'] ?> guests</p>
                <p class="small mb-2"><strong>Amenities:</strong> <?= h($room['amenities'] ?: 'Not specified') ?></p>
                <p class="small text-muted"><?= h(mb_strimwidth($room['description'], 0, 130, '…')) ?></p>
                <div class="mt-auto pt-2 border-top d-flex justify-content-between align-items-center">
                    <span class="price-tag">₱<?= number_format((float)$room['price_per_night'], 2) ?><small class="d-block text-muted">per night</small></span>
                    <span>
                        <a href="room_details.php?room_id=<?= (int)$room['room_id'] ?>" class="btn btn-outline-primary btn-sm">Details</a>
                        <?php if ($isLoggedIn): ?>
                            <a href="book_room.php?room_id=<?= (int)$room['room_id'] ?>" class="btn btn-success btn-sm">Reserve</a>
                        <?php else: ?>
                            <button class="btn btn-primary btn-sm" onclick="showAuth(<?= (int)$room['room_id'] ?>)">Login to reserve</button>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </article>
    </div>
    <?php
}
?>
