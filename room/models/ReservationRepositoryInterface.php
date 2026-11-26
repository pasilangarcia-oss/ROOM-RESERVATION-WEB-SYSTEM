<?php

interface ReservationRepositoryInterface
{
    public function create(array $data): string;
    public function forUser(int $userId, array $filters = []): array;
    public function pendingReservations(): array;
    public function reservationFromQr(string $qrValue): ?array;
    public function verifyQr(string $qrValue, ?int $staffId): array;
    public function checkedInReservations(): array;
    public function checkinLogs(): array;
    public function reportSummary(): array;
    public function decide(int $reservationId, int $staffId, string $decision, string $note = ''): void;
    public function checkIn(string $qrValue, ?int $staffId): array;
    public function checkout(int $reservationId, int $staffId, string $note = ''): void;
    public function cancel(int $reservationId, int $userId, ?string $qrValue = null): void;
}
