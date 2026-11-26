<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../controllers/RoomController.php';
$staffId = requireStaff();
$controller = new RoomController();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_rule') {
            $controller->savePricingRule();
            flash('success', 'Dynamic pricing rule saved.');
        } elseif ($action === 'save_room_price') {
            $controller->saveRoomPrice();
            flash('success', 'Date-specific room rate saved.');
        } elseif ($action === 'deactivate_rule') {
            $controller->deactivatePricingRule((int)($_POST['pricing_rule_id'] ?? 0));
            flash('success', 'Pricing rule deactivated.');
        } elseif ($action === 'deactivate_room_price') {
            $controller->deactivateRoomPrice((int)($_POST['room_pricing_id'] ?? 0));
            flash('success', 'Date-specific rate deactivated.');
        } else {
            throw new InvalidArgumentException('Unknown pricing action.');
        }
        header('Location: pricing.php');
        exit();
    } catch (Throwable $e) {
        error_log('Pricing action error: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage() : 'The pricing change could not be saved.';
    }
}
$rules = $controller->pricingRules();
$roomPrices = $controller->roomPrices();
$roomList = $controller->allRooms();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dynamic pricing | RoomReservation</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-light navbar-custom"><div class="container"><a class="navbar-brand font-weight-bold text-primary" href="index.php">RoomReservation</a><a href="staff.php" class="btn btn-outline-primary btn-sm">Staff dashboard</a></div></nav>
<main class="container py-4">
    <h1 class="h3 font-weight-bold">Dynamic pricing management</h1>
    <p class="text-muted">Date-specific room rates override the base rate first. Active pricing rules then apply in order. Weekend rules apply only on Saturday and Sunday; occupancy rules use the current occupied-room percentage.</p>
    <?= renderFlash() ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <div class="row">
        <div class="col-lg-6 mb-4">
            <section class="card border-0 shadow-sm h-100"><div class="card-body">
                <h2 class="h5 font-weight-bold">Add a pricing rule</h2>
                <form method="post">
                    <?= csrfField() ?><input type="hidden" name="action" value="save_rule">
                    <div class="form-group"><label>Rule name</label><input name="name" class="form-control" maxlength="120" required></div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Rule category</label><select name="rule_type" id="ruleType" class="form-control" required>
                            <?php foreach (['season' => 'Season', 'holiday' => 'Holiday', 'event' => 'Special event', 'weekend' => 'Weekend', 'occupancy' => 'Occupancy', 'promotion' => 'Promotion', 'custom' => 'Custom'] as $value => $label): ?><option value="<?= h($value) ?>"><?= h($label) ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="form-group col-md-6"><label>Adjustment</label><select name="adjustment_type" class="form-control"><option value="percent">Percent (increase/decrease)</option><option value="fixed">Fixed amount (increase/decrease)</option></select></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Adjustment value</label><input type="number" step="0.01" name="adjustment_value" class="form-control" required><small class="form-text text-muted">Example: 15 means +15%; -10 means 10% off.</small></div>
                        <div class="form-group col-md-6" id="occupancyField" hidden><label>Minimum occupancy (%)</label><input type="number" min="1" max="100" name="min_occupancy_percent" class="form-control"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Starts on</label><input type="date" name="starts_on" class="form-control" required></div>
                        <div class="form-group col-md-6"><label>Ends on</label><input type="date" name="ends_on" class="form-control" required></div>
                    </div>
                    <button class="btn btn-primary">Save rule</button>
                </form>
            </div></section>
        </div>
        <div class="col-lg-6 mb-4">
            <section class="card border-0 shadow-sm h-100"><div class="card-body">
                <h2 class="h5 font-weight-bold">Add a room-specific date rate</h2>
                <form method="post">
                    <?= csrfField() ?><input type="hidden" name="action" value="save_room_price">
                    <div class="form-group"><label>Room</label><select name="room_id" class="form-control" required><?php foreach ($roomList as $room): ?><option value="<?= (int)$room['room_id'] ?>"><?= h($room['room_number'] . ' · ' . $room['room_type']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Nightly rate</label><input type="number" step="0.01" min="0" name="price" class="form-control" required></div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Starts on</label><input type="date" name="starts_on" class="form-control" required></div>
                        <div class="form-group col-md-6"><label>Ends on</label><input type="date" name="ends_on" class="form-control" required></div>
                    </div>
                    <button class="btn btn-outline-primary">Save date rate</button>
                </form>
            </div></section>
        </div>
    </div>
    <section class="card border-0 shadow-sm mb-4"><div class="card-body">
        <h2 class="h5 font-weight-bold">Pricing rules</h2>
        <div class="table-responsive"><table class="table table-sm">
            <thead><tr><th>Rule</th><th>Category</th><th>Adjustment</th><th>Dates</th><th>Status</th><th></th></tr></thead>
            <tbody><?php foreach ($rules as $rule): ?><tr>
                <td><?= h($rule['name']) ?></td><td><?= h($rule['rule_type']) ?><?= $rule['min_occupancy_percent'] !== null ? ' ≥ ' . (int)$rule['min_occupancy_percent'] . '%' : '' ?></td>
                <td><?= h($rule['adjustment_value']) ?> <?= h($rule['adjustment_type']) ?></td><td><?= h($rule['starts_on']) ?> – <?= h($rule['ends_on']) ?></td><td><?= $rule['is_active'] ? 'Active' : 'Inactive' ?></td>
                <td><?php if ($rule['is_active']): ?><form method="post" onsubmit="return confirm('Deactivate this pricing rule?');"><?= csrfField() ?><input type="hidden" name="action" value="deactivate_rule"><input type="hidden" name="pricing_rule_id" value="<?= (int)$rule['pricing_rule_id'] ?>"><button class="btn btn-outline-danger btn-sm">Deactivate</button></form><?php endif; ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    </div></section>
    <section class="card border-0 shadow-sm"><div class="card-body">
        <h2 class="h5 font-weight-bold">Date-specific room rates</h2>
        <div class="table-responsive"><table class="table table-sm">
            <thead><tr><th>Room</th><th>Rate</th><th>Dates</th><th>Status</th><th></th></tr></thead>
            <tbody><?php foreach ($roomPrices as $rate): ?><tr><td><?= h($rate['room_number']) ?></td><td>₱<?= number_format((float)$rate['price'], 2) ?></td><td><?= h($rate['starts_on']) ?> – <?= h($rate['ends_on']) ?></td><td><?= $rate['is_active'] ? 'Active' : 'Inactive' ?></td>
                <td><?php if ($rate['is_active']): ?><form method="post" onsubmit="return confirm('Deactivate this date rate?');"><?= csrfField() ?><input type="hidden" name="action" value="deactivate_room_price"><input type="hidden" name="room_pricing_id" value="<?= (int)$rate['room_pricing_id'] ?>"><button class="btn btn-outline-danger btn-sm">Deactivate</button></form><?php endif; ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    </div></section>
</main>
<script>
var ruleType = document.getElementById('ruleType');
var occupancyField = document.getElementById('occupancyField');
function toggleOccupancy() {
    occupancyField.hidden = ruleType.value !== 'occupancy';
    occupancyField.querySelector('input').required = ruleType.value === 'occupancy';
}
ruleType.addEventListener('change', toggleOccupancy);
toggleOccupancy();
</script>
</body>
</html>
