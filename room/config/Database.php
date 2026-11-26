<?php

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Manila');

class Database
{
    private static ?PDO $connection = null;

    public static function connect(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5432';
        $database = getenv('DB_NAME') ?: 'room_system_db';
        $username = getenv('DB_USER') ?: 'system_user';
        $password = getenv('DB_PASSWORD') ?: '12345';

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
        self::$connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        self::$connection->exec('SET search_path TO room_reservation, public');

        return self::$connection;
    }
}
