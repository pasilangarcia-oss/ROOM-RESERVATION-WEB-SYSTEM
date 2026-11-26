<?php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/RoomModel.php';
require_once __DIR__ . '/ReservationRepositoryInterface.php';

class ReservationModel extends BaseModel implements ReservationRepositoryInterface
{
    public function create(array $data): string
    {
        $this->db->beginTransaction();
        try {
            $room = $this->db->prepare(
                "SELECT r.capacity, r.availability_status, s.name AS status
                 FROM rooms r CROSS JOIN reservation_status s
                 WHERE r.room_id = ? AND s.name = 'Pending' FOR UPDATE"
            );
            $room->execute([$data['room_id']]);
            $roomData = $room->fetch();
            if (!$roomData || $roomData['availability_status'] !== 'available') {
                throw new RuntimeException('This room is not available for reservation.');
            }
            if ((int)$data['guest_count'] > (int)$roomData['capacity']) {
                throw new RuntimeException('The guest count exceeds this room capacity.');
            }
            $overlap = $this->db->prepare(
                "SELECT COUNT(*) FROM reservations r JOIN reservation_status s ON s.status_id = r.status_id
                 WHERE r.room_id = ? AND r.check_in_date < ? AND r.check_out_date > ?
                   AND s.name IN ('Pending', 'Approved', 'Checked-In')"
            );
            $overlap->execute([$data['room_id'], $data['check_out_date'], $data['check_in_date']]);
            if ((int)$overlap->fetchColumn() > 0) {
                throw new RuntimeException('The selected room is already requested or reserved for those dates.');
            }

            $price = new RoomModel($this->db);
            $total = 0.0;
            $start = new DateTimeImmutable($data['check_in_date']);
            $end = new DateTimeImmutable($data['check_out_date']);
            $nights = (int)$start->diff($end)->days;
            for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
                $total += $price->effectiveNightlyPrice((int)$data['room_id'], $day->format('Y-m-d'));
            }
            $nightlyRate = round($total / $nights, 2);
            $reference = 'REQ-' . strtoupper(bin2hex(random_bytes(6)));
            $pending = $this->db->query("SELECT status_id FROM reservation_status WHERE name = 'Pending'")->fetchColumn();
            $stmt = $this->db->prepare(
                'INSERT INTO reservations (booking_reference, user_id, room_id, status_id, guest_count,
                 check_in_date, check_out_date, nightly_rate, total_price, guest_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING reservation_id'
            );
            $stmt->execute([
                $reference, $data['user_id'], $data['room_id'], $pending, $data['guest_count'],
                $data['check_in_date'], $data['check_out_date'], $nightlyRate, round($total, 2),
                $data['guest_note'] ?: null,
            ]);
            $reservationId = (int)$stmt->fetchColumn();
            $transaction = $this->db->prepare(
                "INSERT INTO transactions (reservation_id, transaction_type, amount, details)
                 VALUES (?, 'reservation', ?, 'Reservation request submitted for staff approval')"
            );
            $transaction->execute([$reservationId, 0]);
            $this->db->commit();
            return $reference;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function forUser(int $userId, array $filters = []): array
    {
        $sql = $this->reservationQuery() . ' WHERE r.user_id = ?';
        $params = [$userId];
        if (!empty($filters['status'])) {
            $sql .= ' AND s.name = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (r.booking_reference ILIKE ? OR rt.name ILIKE ? OR r.room_id::text ILIKE ?)';
            $search = '%' . $filters['search'] . '%';
            array_push($params, $search, $search, $search);
        }
        $sql .= ' ORDER BY r.created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function pendingReservations(): array
    {
        $stmt = $this->db->query(
            $this->reservationQuery() . " WHERE s.name = 'Pending' ORDER BY r.created_at"
        );
        return $stmt->fetchAll();
    }

    public function reservationFromQr(string $qrValue): ?array
    {
        $stmt = $this->db->prepare(
            $this->reservationQuery() . ' WHERE q.qr_code_value = ? AND q.is_active IS TRUE'
        );
        $stmt->execute([$qrValue]);
        $reservation = $stmt->fetch();
        return $reservation ?: null;
    }

    public function verifyQr(string $qrValue, ?int $staffId): array
    {
        $reservation = $this->reservationFromQr($qrValue);
        $valid = $reservation
            && $reservation['reservation_status'] === 'Approved'
            && $reservation['check_in_date'] === date('Y-m-d')
            && in_array($reservation['qr_is_active'], [true, 1, '1', 't', 'true'], true);
        if (!$valid) {
            $stmt = $this->db->prepare(
                "INSERT INTO checkin_logs (reservation_id, staff_user_id, scanned_value_prefix, result, message)
                 VALUES (?, ?, ?, 'denied', ?)"
            );
            $stmt->execute([
                $reservation['reservation_id'] ?? null, $staffId, substr($qrValue, 0, 16),
                'Invalid QR, inactive reservation, or arrival date mismatch',
            ]);
        }
        return ['success' => (bool)$valid, 'reservation' => $reservation];
    }

    public function checkedInReservations(): array
    {
        return $this->db->query(
            $this->reservationQuery() . " WHERE s.name = 'Checked-In' ORDER BY r.check_out_date"
        )->fetchAll();
    }

    public function checkinLogs(): array
    {
        return $this->db->query(
            'SELECT l.*, u.full_name AS staff_name, r.booking_reference
             FROM checkin_logs l
             LEFT JOIN users u ON u.user_id = l.staff_user_id
             LEFT JOIN reservations r ON r.reservation_id = l.reservation_id
             ORDER BY l.occurred_at DESC LIMIT 100'
        )->fetchAll();
    }

    public function reportSummary(): array
    {
        $totals = $this->db->query(
            "SELECT COUNT(*) AS reservation_count,
                    COALESCE(SUM(CASE WHEN s.name = 'Completed' THEN r.total_price ELSE 0 END), 0) AS completed_revenue,
                    SUM(CASE WHEN s.name = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN s.name = 'Checked-In' THEN 1 ELSE 0 END) AS checked_in_count
             FROM reservations r JOIN reservation_status s ON s.status_id = r.status_id"
        )->fetch();
        $transactions = $this->db->query(
            'SELECT transaction_type, COUNT(*) AS event_count, COALESCE(SUM(amount), 0) AS total_amount
             FROM transactions GROUP BY transaction_type ORDER BY transaction_type'
        )->fetchAll();
        return ['totals' => $totals, 'transactions' => $transactions];
    }

    private function reservationQuery(): string
    {
        return "SELECT r.*, s.name AS reservation_status, rt.name AS room_type, rm.room_number,
                       u.full_name AS guest_name, u.email AS guest_email, u.contact_number AS guest_contact,
                       q.qr_id, q.qr_code_value,
                       CASE WHEN q.is_active THEN 1 ELSE 0 END AS qr_is_active
                FROM reservations r
                JOIN reservation_status s ON s.status_id = r.status_id
                JOIN rooms rm ON rm.room_id = r.room_id
                JOIN room_types rt ON rt.room_type_id = rm.room_type_id
                JOIN users u ON u.user_id = r.user_id
                LEFT JOIN qr_codes q ON q.reservation_id = r.reservation_id";
    }

    public function decide(int $reservationId, int $staffId, string $decision, string $note = ''): void
    {
        if (!in_array($decision, ['Approved', 'Rejected'], true)) {
            throw new InvalidArgumentException('Invalid reservation decision.');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT r.reservation_id, r.room_id, r.check_in_date, r.check_out_date,
                        r.total_price, s.name AS reservation_status
                 FROM reservations r JOIN reservation_status s ON s.status_id = r.status_id
                 WHERE r.reservation_id = ? FOR UPDATE'
            );
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation || $reservation['reservation_status'] !== 'Pending') {
                throw new RuntimeException('This reservation is no longer pending review.');
            }
            if ($decision === 'Approved') {
                $roomLock = $this->db->prepare(
                    'SELECT availability_status FROM rooms WHERE room_id = ? FOR UPDATE'
                );
                $roomLock->execute([$reservation['room_id']]);
                if ($roomLock->fetchColumn() !== 'available') {
                    throw new RuntimeException('This room is no longer available for approval.');
                }
                $overlap = $this->db->prepare(
                    "SELECT r.reservation_id FROM reservations r JOIN reservation_status s ON s.status_id = r.status_id
                     WHERE r.room_id = ? AND r.reservation_id <> ? AND r.check_in_date < ? AND r.check_out_date > ?
                       AND s.name IN ('Approved', 'Checked-In') FOR UPDATE"
                );
                $overlap->execute([
                    $reservation['room_id'], $reservationId, $reservation['check_out_date'], $reservation['check_in_date'],
                ]);
                if ($overlap->fetch()) {
                    throw new RuntimeException('Another approved reservation conflicts with these dates.');
                }
            }
            $status = $this->db->prepare('SELECT status_id FROM reservation_status WHERE name = ?');
            $status->execute([$decision]);
            $update = $this->db->prepare('UPDATE reservations SET status_id = ? WHERE reservation_id = ?');
            $update->execute([$status->fetchColumn(), $reservationId]);
            $approval = $this->db->prepare(
                'INSERT INTO staff_approvals (reservation_id, staff_user_id, decision, note) VALUES (?, ?, ?, ?)'
            );
            $approval->execute([$reservationId, $staffId, $decision, $note ?: null]);
            if ($decision === 'Approved') {
                $reference = 'RSV-' . strtoupper(bin2hex(random_bytes(6)));
                $this->db->prepare('UPDATE reservations SET booking_reference = ? WHERE reservation_id = ?')
                    ->execute([$reference, $reservationId]);
                $qrValue = bin2hex(random_bytes(32));
                $qr = $this->db->prepare(
                    'INSERT INTO qr_codes (reservation_id, qr_code_value) VALUES (?, ?)'
                );
                $qr->execute([$reservationId, $qrValue]);
            }
            $transaction = $this->db->prepare(
                'INSERT INTO transactions (reservation_id, transaction_type, amount, details) VALUES (?, ?, ?, ?)'
            );
            $transaction->execute([
                $reservationId, 'approval', 0,
                'Staff ' . strtolower($decision) . ' the reservation',
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function checkIn(string $qrValue, ?int $staffId): array
    {
        $this->db->beginTransaction();
        try {
            $qr = $this->db->prepare(
                'SELECT reservation_id FROM qr_codes WHERE qr_code_value = ? AND is_active IS TRUE FOR UPDATE'
            );
            $qr->execute([$qrValue]);
            $reservationId = $qr->fetchColumn();
            $reservation = null;
            if ($reservationId !== false) {
                $stmt = $this->db->prepare(
                    'SELECT r.*, s.name AS reservation_status FROM reservations r
                     JOIN reservation_status s ON s.status_id = r.status_id
                     WHERE r.reservation_id = ? FOR UPDATE'
                );
                $stmt->execute([$reservationId]);
                $reservation = $stmt->fetch() ?: null;
            }
            if (!$reservation
                || $reservation['reservation_status'] !== 'Approved'
                || $reservation['check_in_date'] !== date('Y-m-d')) {
                $log = $this->db->prepare(
                    "INSERT INTO checkin_logs (reservation_id, staff_user_id, scanned_value_prefix, result, message)
                     VALUES (?, ?, ?, 'denied', ?)"
                );
                $log->execute([
                    $reservation['reservation_id'] ?? null, $staffId,
                    substr($qrValue, 0, 16), 'Invalid QR, inactive reservation, or arrival date mismatch',
                ]);
                $this->db->commit();
                return ['success' => false, 'reservation' => $reservation ?: null];
            }
            $statusId = $this->db->query("SELECT status_id FROM reservation_status WHERE name = 'Checked-In'")->fetchColumn();
            $update = $this->db->prepare('UPDATE reservations SET status_id = ? WHERE reservation_id = ?');
            $update->execute([$statusId, $reservation['reservation_id']]);
            $log = $this->db->prepare(
                "INSERT INTO checkin_logs (reservation_id, staff_user_id, scanned_value_prefix, result, message)
                 VALUES (?, ?, ?, 'verified', 'Guest checked in successfully')"
            );
            $log->execute([$reservation['reservation_id'], $staffId, substr($qrValue, 0, 16)]);
            $transaction = $this->db->prepare(
                "INSERT INTO transactions (reservation_id, transaction_type, amount, details)
                 VALUES (?, 'checkin', 0, 'QR verified and guest checked in')"
            );
            $transaction->execute([$reservation['reservation_id']]);
            $details = $this->db->prepare(
                $this->reservationQuery() . ' WHERE r.reservation_id = ?'
            );
            $details->execute([$reservation['reservation_id']]);
            $reservation = $details->fetch();
            $this->db->commit();
            return ['success' => true, 'reservation' => $reservation];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function checkout(int $reservationId, int $staffId, string $note = ''): void
    {
        $this->db->beginTransaction();
        try {
            $qrLock = $this->db->prepare(
                'SELECT qr_id FROM qr_codes WHERE reservation_id = ? FOR UPDATE'
            );
            $qrLock->execute([$reservationId]);
            $stmt = $this->db->prepare(
                "SELECT r.total_price, s.name AS status FROM reservations r
                 JOIN reservation_status s ON s.status_id = r.status_id
                 WHERE r.reservation_id = ? FOR UPDATE"
            );
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation || $reservation['status'] !== 'Checked-In') {
                throw new RuntimeException('Only a checked-in reservation can be checked out.');
            }
            $statusId = $this->db->query("SELECT status_id FROM reservation_status WHERE name = 'Completed'")->fetchColumn();
            $this->db->prepare('UPDATE reservations SET status_id = ? WHERE reservation_id = ?')
                ->execute([$statusId, $reservationId]);
            $this->db->prepare(
                'INSERT INTO checkout_logs (reservation_id, staff_user_id, final_amount, note) VALUES (?, ?, ?, ?)'
            )->execute([$reservationId, $staffId, $reservation['total_price'], $note ?: null]);
            $this->db->prepare('UPDATE qr_codes SET is_active = FALSE WHERE reservation_id = ?')
                ->execute([$reservationId]);
            $this->db->prepare(
                "INSERT INTO transactions (reservation_id, transaction_type, amount, details)
                 VALUES (?, 'checkout', ?, 'Guest checked out; room available for future dates')"
            )->execute([$reservationId, $reservation['total_price']]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function cancel(int $reservationId, int $userId, ?string $qrValue = null): void
    {
        $this->db->beginTransaction();
        try {
            $qrLock = $this->db->prepare(
                'SELECT qr_code_value FROM qr_codes WHERE reservation_id = ? AND is_active IS TRUE FOR UPDATE'
            );
            $qrLock->execute([$reservationId]);
            $activeQrValue = $qrLock->fetchColumn();
            if ($qrValue !== null && ($activeQrValue === false || !hash_equals((string)$activeQrValue, $qrValue))) {
                throw new RuntimeException('Reservation QR verification failed.');
            }
            $stmt = $this->db->prepare(
                "SELECT r.total_price, s.name AS status FROM reservations r
                 JOIN reservation_status s ON s.status_id = r.status_id
                 WHERE r.reservation_id = ? AND r.user_id = ? FOR UPDATE"
            );
            $stmt->execute([$reservationId, $userId]);
            $reservation = $stmt->fetch();
            if (!$reservation || !in_array($reservation['status'], ['Pending', 'Approved'], true)) {
                throw new RuntimeException('This reservation cannot be cancelled.');
            }
            if ($reservation['status'] === 'Approved' && $qrValue === null) {
                throw new RuntimeException('Use the reservation QR confirmation to request cancellation.');
            }
            $statusId = $this->db->query("SELECT status_id FROM reservation_status WHERE name = 'Cancelled'")->fetchColumn();
            $this->db->prepare('UPDATE reservations SET status_id = ? WHERE reservation_id = ?')
                ->execute([$statusId, $reservationId]);
            $this->db->prepare('UPDATE qr_codes SET is_active = FALSE WHERE reservation_id = ?')->execute([$reservationId]);
            $this->db->prepare(
                "INSERT INTO transactions (reservation_id, transaction_type, amount, details)
                 VALUES (?, 'cancellation', 0, 'Cancelled by guest')"
            )->execute([$reservationId]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
