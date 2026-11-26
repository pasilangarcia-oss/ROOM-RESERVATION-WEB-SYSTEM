# RoomReservation setup

## Requirements

- PHP 8.1+ with PDO PostgreSQL (`pdo_pgsql`), Fileinfo, and mbstring enabled
- PostgreSQL 12+
- A web server with write access to `assets/uploads/` for staff room-image uploads

## Database setup

Create the PostgreSQL database once if it does not already exist:

```sql
CREATE DATABASE room_reservation_db;
```

Create the `room_user` role before importing the schema. Run `database/schema.sql` as `postgres` (or another database administrator) in pgAdmin's Query Tool, or use:

```powershell
psql -U postgres -d room_reservation_db -f database/schema.sql
```

The script grants `room_user` connection, schema, table, and sequence privileges. It does not create the role or database.

The schema creates a dedicated PostgreSQL schema named `room_reservation`, along with normalized tables, lookup values, and three sample rooms. The PHP connection sets this schema as its search path. This avoids overwriting existing tables in `public`. Seed rows can be safely applied again. The script does not create the database itself; PostgreSQL requires `CREATE DATABASE` to run separately.

If you want to bring existing accounts from `public.users` into the new app schema, review and run `database/migrate_existing_users.sql` after importing the schema. It copies password hashes but leaves the original users table untouched. Other legacy tables/data are not automatically migrated.

The application defaults to host `127.0.0.1`, port `5432`, database `room_reservation_db`, and user `room_user` (the account previously configured for this project). Set `DB_PASSWORD` to that PostgreSQL account's password; credentials are not stored in the PHP source. Configure these environment variables to match your installation:

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `APP_TIMEZONE` (defaults to `Asia/Manila`)

Make these variables available to the PHP process started by Laragon. Make sure the PHP installation used by Laragon has `extension=pdo_pgsql` enabled, then restart its web server/PHP service. Open the project through the local web server, for example `http://localhost/room/`.

This configures the app to use PostgreSQL; it does not automatically move legacy room, reservation, or payment records from `public`. Those tables remain untouched. Back up and migrate any such records separately before switching production data.

## Staff account

Register a normal account through the site, then promote that account using pgAdmin or `psql`:

```sql
UPDATE users SET role = 'admin' WHERE email = 'staff@example.com';
```

Staff accounts can manage rooms and rates, approve or reject reservations, verify check-in QR codes, check guests out, and view reports and logs.

## Data design

The schema separates users from profile details, room types from rooms, amenities from the room/amenity relation, and reservation states from reservations. Each amenity, image, preference, approval, QR code, pricing entry, check-in, checkout, and transaction has its own row rather than a repeating or delimited field. This keeps attributes dependent on their table key (1NF/2NF/3NF) and preserves historical reservation rates.

Reservation requests are stored as `Pending`; approval creates the QR token. A room is considered available for selected dates only when there is no overlapping pending, approved, or checked-in reservation. Pricing uses an active room-specific date rate (if present), then applies matching dynamic rules; the calculated total is snapshotted on the reservation.
