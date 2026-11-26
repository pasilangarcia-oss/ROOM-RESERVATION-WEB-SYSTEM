<?php
require_once __DIR__ . '/BaseModel.php';

class AuthModel extends BaseModel
{
    public function register(string $name, string $email, string $password, string $contact): int
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO users (full_name, email, password_hash, contact_number) VALUES (?, ?, ?, ?) RETURNING user_id'
            );
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $contact]);
            $userId = (int)$stmt->fetchColumn();
            $profile = $this->db->prepare('INSERT INTO user_profiles (user_id) VALUES (?)');
            $profile->execute([$userId]);
            $this->db->commit();
            return $userId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function updateProfile(int $userId, array $data): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE users SET full_name = ?, email = ?, contact_number = ? WHERE user_id = ?'
            );
            $stmt->execute([$data['full_name'], $data['email'], $data['contact_number'], $userId]);
            $profile = $this->db->prepare(
                'INSERT INTO user_profiles (user_id, address, emergency_contact_name, emergency_contact_number)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (user_id) DO UPDATE SET
                     address = EXCLUDED.address,
                     emergency_contact_name = EXCLUDED.emergency_contact_name,
                     emergency_contact_number = EXCLUDED.emergency_contact_number,
                     updated_at = CURRENT_TIMESTAMP'
            );
            $profile->execute([
                $userId,
                $data['address'],
                $data['emergency_contact_name'],
                $data['emergency_contact_number'],
            ]);
            if ($data['password'] !== '') {
                $password = $this->db->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
                $password->execute([password_hash($data['password'], PASSWORD_DEFAULT), $userId]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getProfile(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT u.full_name, u.email, u.contact_number, p.address,
                    p.emergency_contact_name, p.emergency_contact_number
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.user_id
             WHERE u.user_id = ?'
        );
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();
        return $profile ?: null;
    }

    public function passwordHash(int $userId): ?string
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        return $hash === false ? null : $hash;
    }
}
