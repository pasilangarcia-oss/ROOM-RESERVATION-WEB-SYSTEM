<?php
$host = 'localhost';
$port = '5432';
$dbname = 'finaldefense_db';
$user = 'bancia_user';
$password = '24-3100';

$conn = pg_connect(
    "host=$host port=$port dbname=$dbname user=$user password=$password"
);

if (!$conn) {
    error_log('PostgreSQL connection failed: ' . pg_last_error());
    http_response_code(500);
    exit('Database connection failed. Check that PostgreSQL is running, the database credentials are correct, and the PHP pgsql extension is enabled.');
}

?>