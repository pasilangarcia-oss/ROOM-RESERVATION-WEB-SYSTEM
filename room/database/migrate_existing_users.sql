-- Optional: copy existing public.users accounts into the new application schema.
-- Run only after database/schema.sql. This does not modify or delete public.users.

BEGIN;

INSERT INTO room_reservation.users
    (user_id, full_name, email, password_hash, contact_number, role, created_at)
SELECT user_id,
       full_name,
       email,
       password_hash,
       contact_number,
       CASE WHEN LOWER(COALESCE(role, 'guest')) IN ('admin', 'staff')
            THEN LOWER(role) ELSE 'guest' END,
       COALESCE(created_at, CURRENT_TIMESTAMP)::timestamptz
FROM public.users
ON CONFLICT (email) DO NOTHING;

INSERT INTO room_reservation.user_profiles (user_id)
SELECT u.user_id
FROM room_reservation.users u
JOIN public.users legacy ON legacy.email = u.email
ON CONFLICT (user_id) DO NOTHING;

SELECT setval(
    pg_get_serial_sequence('room_reservation.users', 'user_id'),
    COALESCE(MAX(user_id), 1),
    MAX(user_id) IS NOT NULL
)
FROM room_reservation.users;

COMMIT;
