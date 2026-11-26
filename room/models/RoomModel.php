<?php
require_once __DIR__ . '/BaseModel.php';

class RoomModel extends BaseModel
{
    public function roomTypes(): array
    {
        return $this->db->query('SELECT room_type_id, name FROM room_types ORDER BY name')->fetchAll();
    }

    public function allRooms(): array
    {
        return $this->db->query(
            'SELECT r.*, rt.name AS room_type FROM rooms r JOIN room_types rt ON rt.room_type_id = r.room_type_id ORDER BY r.room_number'
        )->fetchAll();
    }

    public function savePreferences(int $userId, array $data): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO user_preferences
                    (user_id, max_budget, guest_count, preferred_room_type_id, check_in_date, check_out_date)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id) DO UPDATE SET
                    max_budget = EXCLUDED.max_budget,
                    guest_count = EXCLUDED.guest_count,
                    preferred_room_type_id = EXCLUDED.preferred_room_type_id,
                    check_in_date = EXCLUDED.check_in_date,
                    check_out_date = EXCLUDED.check_out_date,
                    updated_at = CURRENT_TIMESTAMP
                 RETURNING preference_id'
            );
            $stmt->execute([
                $userId,
                $data['budget'] !== '' ? $data['budget'] : null,
                $data['guests'] !== '' ? $data['guests'] : null,
                $data['room_type_id'] !== '' ? $data['room_type_id'] : null,
                $data['check_in'] ?: null,
                $data['check_out'] ?: null,
            ]);
            $preferenceId = (int)$stmt->fetchColumn();
            $this->db->prepare('DELETE FROM user_preference_amenities WHERE preference_id = ?')
                ->execute([$preferenceId]);
            $insert = $this->db->prepare(
                'INSERT INTO user_preference_amenities (preference_id, amenity_id) VALUES (?, ?)'
            );
            foreach ($data['amenity_ids'] as $amenityId) {
                $insert->execute([$preferenceId, $amenityId]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function amenities(): array
    {
        return $this->db->query('SELECT amenity_id, name FROM amenities ORDER BY name')->fetchAll();
    }

    public function listRooms(array $filters = [], ?int $userId = null): array
    {
        $where = ["r.availability_status = 'available'"];
        $params = [];
        if (!empty($filters['search'])) {
            $where[] = '(r.room_number ILIKE ? OR rt.name ILIKE ? OR r.description ILIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term);
        }
        if (!empty($filters['room_type_id'])) {
            $where[] = 'r.room_type_id = ?';
            $params[] = (int)$filters['room_type_id'];
        }
        if (($filters['guests'] ?? '') !== '') {
            $where[] = 'r.capacity >= ?';
            $params[] = (int)$filters['guests'];
        }
        $from = 'rooms r JOIN room_types rt ON rt.room_type_id = r.room_type_id';
        $sql = "SELECT r.*, rt.name AS room_type, rt.description AS room_type_description,
                       (SELECT ri.image_url FROM room_images ri WHERE ri.room_id = r.room_id ORDER BY ri.sort_order, ri.image_id LIMIT 1) AS image_url,
                       (SELECT string_agg(a.name, ', ' ORDER BY a.name)
                        FROM room_amenities ra JOIN amenities a ON a.amenity_id = ra.amenity_id
                        WHERE ra.room_id = r.room_id) AS amenities
                FROM {$from} WHERE " . implode(' AND ', $where) . ' ORDER BY r.base_price, r.room_number';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll();
        $checkIn = $filters['check_in'] ?? date('Y-m-d');
        $checkOut = $filters['check_out'] ?? date('Y-m-d', strtotime('+1 day'));
        foreach ($rooms as &$room) {
            $room['price_per_night'] = $this->effectiveNightlyPrice((int)$room['room_id'], $checkIn);
            $room['available_for_dates'] = $this->datesAvailable((int)$room['room_id'], $checkIn, $checkOut);
        }
        unset($room);
        $rooms = array_values(array_filter($rooms, static function (array $room): bool {
            return $room['available_for_dates'];
        }));

        if (($filters['budget'] ?? '') !== '') {
            $rooms = array_values(array_filter($rooms, static function (array $room) use ($filters): bool {
                return (float)$room['price_per_night'] <= (float)$filters['budget'];
            }));
        }
        if (!empty($filters['amenity_ids'])) {
            $wanted = array_map('intval', $filters['amenity_ids']);
            foreach ($rooms as &$room) {
                $room['amenity_match_count'] = $this->countMatchingAmenities((int)$room['room_id'], $wanted);
            }
            unset($room);
            if (empty($filters['include_amenity_alternatives'])) {
                $rooms = array_values(array_filter($rooms, static function (array $room) use ($wanted): bool {
                    return $room['amenity_match_count'] === count($wanted);
                }));
            }
            usort($rooms, static function (array $a, array $b): int {
                return ($b['amenity_match_count'] <=> $a['amenity_match_count'])
                    ?: ($a['price_per_night'] <=> $b['price_per_night']);
            });
        }
        return $rooms;
    }

    private function countMatchingAmenities(int $roomId, array $amenityIds): int
    {
        if (!$amenityIds) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($amenityIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM room_amenities WHERE room_id = ? AND amenity_id IN ({$marks})"
        );
        $stmt->execute(array_merge([$roomId], $amenityIds));
        return (int)$stmt->fetchColumn();
    }

    public function getRoom(int $roomId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, rt.name AS room_type, rt.description AS room_type_description,
                    (SELECT string_agg(a.name, ', ' ORDER BY a.name)
                     FROM room_amenities ra JOIN amenities a ON a.amenity_id = ra.amenity_id
                     WHERE ra.room_id = r.room_id) AS amenities,
                    (SELECT string_agg(ra.amenity_id::text, ',')
                     FROM room_amenities ra WHERE ra.room_id = r.room_id) AS amenity_ids,
                    (SELECT image_url FROM room_images WHERE room_id = r.room_id ORDER BY sort_order, image_id LIMIT 1) AS image_url
             FROM rooms r JOIN room_types rt ON rt.room_type_id = r.room_type_id WHERE r.room_id = ?"
        );
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();
        if (!$room) {
            return null;
        }
        $room['price_per_night'] = $this->effectiveNightlyPrice($roomId, date('Y-m-d'));
        $images = $this->db->prepare(
            'SELECT image_url, alt_text FROM room_images WHERE room_id = ? ORDER BY sort_order, image_id'
        );
        $images->execute([$roomId]);
        $room['images'] = $images->fetchAll();
        return $room;
    }

    public function datesAvailable(int $roomId, string $checkIn, string $checkOut): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM reservations r JOIN reservation_status s ON s.status_id = r.status_id
             WHERE r.room_id = ? AND r.check_in_date < ? AND r.check_out_date > ?
               AND s.name IN ('Pending', 'Approved', 'Checked-In')"
        );
        $stmt->execute([$roomId, $checkOut, $checkIn]);
        return (int)$stmt->fetchColumn() === 0;
    }

    public function effectiveNightlyPrice(int $roomId, string $date): float
    {
        $stmt = $this->db->prepare('SELECT base_price FROM rooms WHERE room_id = ?');
        $stmt->execute([$roomId]);
        $base = $stmt->fetchColumn();
        if ($base === false) {
            throw new RuntimeException('Room not found.');
        }
        $override = $this->db->prepare(
            'SELECT price FROM room_pricing WHERE room_id = ? AND is_active IS TRUE AND starts_on <= ? AND ends_on >= ?
             ORDER BY starts_on DESC, room_pricing_id DESC LIMIT 1'
        );
        $override->execute([$roomId, $date, $date]);
        $price = $override->fetchColumn();
        $price = $price === false ? (float)$base : (float)$price;
        $rules = $this->db->prepare(
            'SELECT rule_type, adjustment_type, adjustment_value, min_occupancy_percent FROM pricing_rules
             WHERE is_active IS TRUE AND starts_on <= ? AND ends_on >= ? ORDER BY pricing_rule_id'
        );
        $rules->execute([$date, $date]);
        $dayOfWeek = (int)(new DateTimeImmutable($date))->format('N');
        $occupancy = 0;
        $inventory = null;
        foreach ($rules->fetchAll() as $rule) {
            if ($rule['rule_type'] === 'weekend' && $dayOfWeek < 6) {
                continue;
            }
            if ($rule['rule_type'] === 'occupancy') {
                if ($inventory === null) {
                    $inventory = (int)$this->db->query(
                        "SELECT COUNT(*) FROM rooms WHERE availability_status = 'available'"
                    )->fetchColumn();
                    $occupied = $this->db->prepare(
                        "SELECT COUNT(DISTINCT r.room_id) FROM reservations r
                         JOIN rooms rm ON rm.room_id = r.room_id
                         JOIN reservation_status s ON s.status_id = r.status_id
                         WHERE r.check_in_date <= ? AND r.check_out_date > ?
                           AND rm.availability_status = 'available'
                           AND s.name IN ('Pending', 'Approved', 'Checked-In')"
                    );
                    $occupied->execute([$date, $date]);
                    $occupancy = $inventory > 0
                        ? min(100, (int)floor(((int)$occupied->fetchColumn() / $inventory) * 100))
                        : 0;
                }
                $threshold = (int)($rule['min_occupancy_percent'] ?? 80);
                if ($occupancy < $threshold) {
                    continue;
                }
            }
            $value = (float)$rule['adjustment_value'];
            $price = $rule['adjustment_type'] === 'percent'
                ? $price * (1 + ($value / 100))
                : $price + $value;
        }
        return round(max(0, $price), 2);
    }

    public function saveRoom(array $data, ?int $roomId = null): int
    {
        $this->db->beginTransaction();
        try {
            if ($roomId) {
                $stmt = $this->db->prepare(
                    'UPDATE rooms SET room_number = ?, room_type_id = ?, capacity = ?, description = ?,
                     base_price = ?, availability_status = ? WHERE room_id = ?'
                );
                $stmt->execute([
                    $data['room_number'], $data['room_type_id'], $data['capacity'], $data['description'],
                    $data['base_price'], $data['availability_status'], $roomId,
                ]);
                $id = $roomId;
                $this->db->prepare('DELETE FROM room_amenities WHERE room_id = ?')->execute([$id]);
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO rooms (room_number, room_type_id, capacity, description, base_price, availability_status)
                     VALUES (?, ?, ?, ?, ?, ?) RETURNING room_id'
                );
                $stmt->execute([
                    $data['room_number'], $data['room_type_id'], $data['capacity'], $data['description'],
                    $data['base_price'], $data['availability_status'],
                ]);
                $id = (int)$stmt->fetchColumn();
            }
            $link = $this->db->prepare('INSERT INTO room_amenities (room_id, amenity_id) VALUES (?, ?)');
            foreach ($data['amenity_ids'] as $amenityId) {
                $link->execute([$id, $amenityId]);
            }
            if (!empty($data['image_url'])) {
                $image = $this->db->prepare('INSERT INTO room_images (room_id, image_url, alt_text) VALUES (?, ?, ?)');
                $image->execute([$id, $data['image_url'], $data['room_number'] . ' room']);
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function deactivateRoom(int $roomId): void
    {
        $stmt = $this->db->prepare("UPDATE rooms SET availability_status = 'inactive' WHERE room_id = ?");
        $stmt->execute([$roomId]);
    }

    public function savePricingRule(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO pricing_rules (name, rule_type, adjustment_type, adjustment_value, min_occupancy_percent, starts_on, ends_on)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'], $data['rule_type'], $data['adjustment_type'],
            $data['adjustment_value'], $data['min_occupancy_percent'], $data['starts_on'], $data['ends_on'],
        ]);
    }

    public function pricingRules(): array
    {
        $rules = $this->db->query(
            'SELECT *, CASE WHEN is_active THEN 1 ELSE 0 END AS active_flag
             FROM pricing_rules ORDER BY starts_on DESC'
        )->fetchAll();
        foreach ($rules as &$rule) {
            $rule['is_active'] = (bool)$rule['active_flag'];
            unset($rule['active_flag']);
        }
        unset($rule);
        return $rules;
    }

    public function saveRoomPrice(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO room_pricing (room_id, price, starts_on, ends_on) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$data['room_id'], $data['price'], $data['starts_on'], $data['ends_on']]);
    }

    public function roomPrices(): array
    {
        $prices = $this->db->query(
            'SELECT p.*, CASE WHEN p.is_active THEN 1 ELSE 0 END AS active_flag, r.room_number
             FROM room_pricing p JOIN rooms r ON r.room_id = p.room_id
             ORDER BY p.starts_on DESC, p.room_pricing_id DESC'
        )->fetchAll();
        foreach ($prices as &$price) {
            $price['is_active'] = (bool)$price['active_flag'];
            unset($price['active_flag']);
        }
        unset($price);
        return $prices;
    }

    public function deactivatePricingRule(int $ruleId): void
    {
        $this->db->prepare('UPDATE pricing_rules SET is_active = FALSE WHERE pricing_rule_id = ?')->execute([$ruleId]);
    }

    public function deactivateRoomPrice(int $priceId): void
    {
        $this->db->prepare('UPDATE room_pricing SET is_active = FALSE WHERE room_pricing_id = ?')->execute([$priceId]);
    }
}
