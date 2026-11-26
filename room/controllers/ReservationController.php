<?php
require_once __DIR__ . '/../models/ReservationModel.php';
require_once __DIR__ . '/../models/ReservationRepositoryInterface.php';

class ReservationController
{
    private ReservationRepositoryInterface $reservations;

    public function __construct(?ReservationRepositoryInterface $reservations = null)
    {
        $this->reservations = $reservations ?? new ReservationModel();
    }

    public function create(array $data, int $userId): string
    {
        $roomId = filter_var($data['room_id'] ?? '', FILTER_VALIDATE_INT);
        $guestCount = filter_var($data['guest_count'] ?? '', FILTER_VALIDATE_INT);
        $checkIn = (string)($data['check_in_date'] ?? '');
        $checkOut = (string)($data['check_out_date'] ?? '');
        $validDate = static function (string $date): bool {
            $parsed = DateTime::createFromFormat('!Y-m-d', $date);
            return $parsed && $parsed->format('Y-m-d') === $date;
        };
        if (!$roomId || !$guestCount || $guestCount < 1 || !$validDate($checkIn) || !$validDate($checkOut)
            || $checkIn < date('Y-m-d') || $checkOut <= $checkIn) {
            throw new InvalidArgumentException('Choose a valid room, guest count, and future check-in/check-out dates.');
        }
        if ((new DateTimeImmutable($checkIn))->diff(new DateTimeImmutable($checkOut))->days > 365) {
            throw new InvalidArgumentException('A stay cannot exceed 365 nights.');
        }
        return $this->reservations->create([
            'user_id' => $userId,
            'room_id' => $roomId,
            'guest_count' => $guestCount,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'guest_note' => trim((string)($data['guest_note'] ?? '')),
        ]);
    }

    public function forUser(int $userId, array $filters = []): array
    {
        return $this->reservations->forUser($userId, $filters);
    }

    public function pending(): array
    {
        return $this->reservations->pendingReservations();
    }

    public function reservationFromQr(string $qrValue): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $qrValue)) {
            return null;
        }
        return $this->reservations->reservationFromQr(strtolower($qrValue));
    }

    public function verifyQr(string $qrValue, ?int $staffId): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $qrValue)) {
            return $this->reservations->verifyQr($qrValue, $staffId);
        }
        return $this->reservations->verifyQr(strtolower($qrValue), $staffId);
    }

    public function checkedInReservations(): array
    {
        return $this->reservations->checkedInReservations();
    }

    public function checkinLogs(): array
    {
        return $this->reservations->checkinLogs();
    }

    public function reportSummary(): array
    {
        return $this->reservations->reportSummary();
    }

    public function decide(int $reservationId, int $staffId, string $decision, string $note): void
    {
        $this->reservations->decide($reservationId, $staffId, $decision, $note);
    }

    public function checkIn(string $qrValue, ?int $staffId): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $qrValue)) {
            return $this->reservations->verifyQr($qrValue, $staffId);
        }
        return $this->reservations->checkIn(strtolower($qrValue), $staffId);
    }

    public function checkout(int $reservationId, int $staffId, string $note): void
    {
        $this->reservations->checkout($reservationId, $staffId, $note);
    }

    public function cancel(int $reservationId, int $userId, ?string $qrValue = null): void
    {
        $this->reservations->cancel($reservationId, $userId, $qrValue);
    }
}
