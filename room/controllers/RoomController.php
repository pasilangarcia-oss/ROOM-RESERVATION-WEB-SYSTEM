<?php
require_once __DIR__ . '/../models/RoomModel.php';

class RoomController
{
    private RoomModel $rooms;

    public function __construct(?RoomModel $rooms = null)
    {
        $this->rooms = $rooms ?? new RoomModel();
    }

    public function getRooms(?int $userId = null): array
    {
        $getString = static function (string $key, string $default = ''): string {
            $value = $_GET[$key] ?? $default;
            return is_string($value) ? trim($value) : '';
        };
        $filters = [
            'search' => $getString('search'),
            'room_type_id' => $getString('room_type_id'),
            'guests' => $getString('guests'),
            'budget' => $getString('budget'),
            'check_in' => $getString('check_in', date('Y-m-d')),
            'check_out' => $getString('check_out', date('Y-m-d', strtotime('+1 day'))),
            'amenity_ids' => $this->validIds($_GET['amenity_ids'] ?? []),
        ];
        if ($filters['budget'] !== '' && (!is_numeric($filters['budget']) || (float)$filters['budget'] < 0)) {
            throw new InvalidArgumentException('Budget must be a non-negative amount.');
        }
        if ($filters['guests'] !== '' && filter_var($filters['guests'], FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Guest count must be a whole number.');
        }
        if ($filters['guests'] !== '' && (int)$filters['guests'] < 1) {
            throw new InvalidArgumentException('Guest count must be at least one.');
        }
        if ($filters['room_type_id'] !== '' && filter_var($filters['room_type_id'], FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Choose a valid room type.');
        }
        if ($filters['room_type_id'] !== '' && (int)$filters['room_type_id'] < 1) {
            throw new InvalidArgumentException('Choose a valid room type.');
        }
        if (!$this->validDateRange($filters['check_in'], $filters['check_out'])
            || $filters['check_in'] < date('Y-m-d')) {
            throw new InvalidArgumentException('Choose valid future check-in and check-out dates.');
        }
        if ((new DateTimeImmutable($filters['check_in']))->diff(new DateTimeImmutable($filters['check_out']))->days > 365) {
            throw new InvalidArgumentException('A stay cannot exceed 365 nights.');
        }
        if (strlen($filters['search']) > 100) {
            throw new InvalidArgumentException('Search text is too long.');
        }
        if (!empty($_GET['assisted_search']) && $userId) {
            $this->rooms->savePreferences($userId, $filters);
        }
        $exact = $this->rooms->listRooms($filters, $userId);
        $recommended = [];
        if (!$exact && !empty($_GET['assisted_search'])) {
            $relaxed = $filters;
            $relaxed['budget'] = '';
            $relaxed['room_type_id'] = '';
            $relaxed['search'] = '';
            $relaxed['include_amenity_alternatives'] = true;
            $recommended = array_slice($this->rooms->listRooms($relaxed, $userId), 0, 3);
        }
        return [
            'exact' => $exact,
            'recommended' => $recommended,
            'room_types' => $this->rooms->roomTypes(),
            'amenities' => $this->rooms->amenities(),
            'filters' => $filters,
        ];
    }

    public function getRoom(int $roomId): ?array
    {
        return $this->rooms->getRoom($roomId);
    }

    public function priceForDate(int $roomId, string $date): float
    {
        return $this->rooms->effectiveNightlyPrice($roomId, $date);
    }

    public function datesAvailable(int $roomId, string $checkIn, string $checkOut): bool
    {
        return $this->rooms->datesAvailable($roomId, $checkIn, $checkOut);
    }

    public function allRooms(): array
    {
        return $this->rooms->allRooms();
    }

    public function roomTypes(): array
    {
        return $this->rooms->roomTypes();
    }

    public function amenities(): array
    {
        return $this->rooms->amenities();
    }

    public function pricingRules(): array
    {
        return $this->rooms->pricingRules();
    }

    public function roomPrices(): array
    {
        return $this->rooms->roomPrices();
    }

    public function deactivatePricingRule(int $ruleId): void
    {
        $this->rooms->deactivatePricingRule($ruleId);
    }

    public function deactivateRoomPrice(int $priceId): void
    {
        $this->rooms->deactivateRoomPrice($priceId);
    }

    public function saveRoom(?int $roomId): void
    {
        $roomNumber = trim((string)($_POST['room_number'] ?? ''));
        $typeId = filter_var($_POST['room_type_id'] ?? '', FILTER_VALIDATE_INT);
        $capacity = filter_var($_POST['capacity'] ?? '', FILTER_VALIDATE_INT);
        $price = filter_var($_POST['base_price'] ?? '', FILTER_VALIDATE_FLOAT);
        $description = trim((string)($_POST['description'] ?? ''));
        $status = (string)($_POST['availability_status'] ?? 'available');
        if ($roomNumber === '' || strlen($roomNumber) > 30 || !$typeId || !$capacity || $capacity < 1
            || $price === false || $price < 0 || $description === ''
            || !in_array($status, ['available', 'maintenance', 'inactive'], true)) {
            throw new InvalidArgumentException('Enter a room number, type, positive capacity, valid price, description, and status.');
        }
        $amenities = $this->validIds($_POST['amenity_ids'] ?? []);
        $imageUrl = $this->handleImageUpload();
        if (!$imageUrl && !empty($_POST['image_url'])) {
            $imageUrl = filter_var(trim((string)$_POST['image_url']), FILTER_VALIDATE_URL);
            if (!$imageUrl || !in_array(parse_url($imageUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new InvalidArgumentException('The image URL must be a valid HTTP or HTTPS URL.');
            }
        }
        $this->rooms->saveRoom([
            'room_number' => $roomNumber,
            'room_type_id' => $typeId,
            'capacity' => $capacity,
            'base_price' => $price,
            'description' => $description,
            'availability_status' => $status,
            'amenity_ids' => $amenities,
            'image_url' => $imageUrl ?: null,
        ], $roomId);
    }

    public function savePricingRule(): void
    {
        $name = trim((string)($_POST['name'] ?? ''));
        $type = (string)($_POST['rule_type'] ?? '');
        $adjustment = (string)($_POST['adjustment_type'] ?? '');
        $value = filter_var($_POST['adjustment_value'] ?? '', FILTER_VALIDATE_FLOAT);
        $occupancy = filter_var($_POST['min_occupancy_percent'] ?? '', FILTER_VALIDATE_INT);
        $start = (string)($_POST['starts_on'] ?? '');
        $end = (string)($_POST['ends_on'] ?? '');
        if ($name === '' || !in_array($type, ['season', 'holiday', 'event', 'weekend', 'occupancy', 'promotion', 'custom'], true)
            || !in_array($adjustment, ['percent', 'fixed'], true) || $value === false
            || ($adjustment === 'percent' && ($value < -100 || $value > 500))
            || ($adjustment === 'fixed' && ($value < -100000 || $value > 500000))
            || ($type === 'occupancy' && ($occupancy === false || $occupancy < 1 || $occupancy > 100))
            || !$this->validDateRange($start, $end)) {
            throw new InvalidArgumentException('Enter a valid pricing rule, adjustment, and date range.');
        }
        $this->rooms->savePricingRule([
            'name' => $name, 'rule_type' => $type, 'adjustment_type' => $adjustment,
            'adjustment_value' => $value, 'min_occupancy_percent' => $type === 'occupancy' ? $occupancy : null,
            'starts_on' => $start, 'ends_on' => $end,
        ]);
    }

    public function saveRoomPrice(): void
    {
        $roomId = filter_var($_POST['room_id'] ?? '', FILTER_VALIDATE_INT);
        $price = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
        $start = (string)($_POST['starts_on'] ?? '');
        $end = (string)($_POST['ends_on'] ?? '');
        if (!$roomId || $price === false || $price < 0 || !$this->validDateRange($start, $end)) {
            throw new InvalidArgumentException('Enter a valid room rate and date range.');
        }
        $this->rooms->saveRoomPrice([
            'room_id' => $roomId, 'price' => $price, 'starts_on' => $start, 'ends_on' => $end,
        ]);
    }

    private function validDateRange(string $start, string $end): bool
    {
        $startDate = DateTime::createFromFormat('!Y-m-d', $start);
        $endDate = DateTime::createFromFormat('!Y-m-d', $end);
        return $startDate && $endDate && $startDate->format('Y-m-d') === $start
            && $endDate->format('Y-m-d') === $end && $endDate >= $startDate;
    }

    private function validIds($ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        $valid = [];
        foreach ($ids as $id) {
            $value = filter_var($id, FILTER_VALIDATE_INT);
            if ($value && $value > 0) {
                $valid[] = $value;
            }
        }
        return array_values(array_unique($valid));
    }

    private function handleImageUpload(): ?string
    {
        if (empty($_FILES['room_image']['tmp_name'])) {
            return null;
        }
        if ($_FILES['room_image']['error'] !== UPLOAD_ERR_OK || $_FILES['room_image']['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Room images must be successfully uploaded and no larger than 5 MB.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['room_image']['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            throw new RuntimeException('Upload a JPG, PNG, or WebP room image.');
        }
        $directory = __DIR__ . '/../assets/uploads';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Room image upload directory could not be created.');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($_FILES['room_image']['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
            throw new RuntimeException('Room image could not be saved.');
        }
        return '../assets/uploads/' . $filename;
    }
}
