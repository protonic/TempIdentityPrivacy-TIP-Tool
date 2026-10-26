<?php

/**
 * First-run bootstrap for the Docker image - the container equivalent of
 * setup.php (which is removed from the image).
 *
 *  1. Checks the required environment variables.
 *  2. Waits for MySQL to accept connections.
 *  3. Imports sql/installer_public_release.sql if the core tables are missing.
 *  4. Creates the first admin from ADMIN_USERNAME / ADMIN_PASSWORD /
 *     ADMIN_USER_EMAIL if there is no admin yet.
 *
 * Safe to run on every start: each step is skipped once done.
 */

require '/var/www/tip-config/config.php';

const CORE_TABLES = [
    'admin_users', 'admin_sessions', 'security_log', 'email_messages', 'temp_emails',
    'domains', 'email_generation_stats', 'system_stats', 'rate_limits', 'blocked_ips',
];

function out($message)
{
    fwrite(STDOUT, "[tip-bootstrap] $message\n");
}

function fail($message)
{
    fwrite(STDERR, "[tip-bootstrap] ERROR: $message\n");
    exit(1);
}

if (DB_PASS === '') {
    fail('DB_PASS is not set.');
}
if (strlen(CRON_SECRET_KEY) < 32) {
    fail('CRON_SECRET_KEY must be at least 32 characters (it encrypts stored IMAP passwords - keep it stable).');
}
if (!filter_var(rtrim(SITE_URL, '/'), FILTER_VALIDATE_URL)) {
    fail('SITE_URL must be a valid URL.');
}

$pdo = null;
for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        break;
    } catch (PDOException $ex) {
        out("Waiting for database ($attempt/30): " . $ex->getMessage());
        sleep(2);
    }
}
if (!$pdo) {
    fail('Could not connect to the database.');
}

$existingTables = [];
foreach (CORE_TABLES as $table) {
    if ($pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn() !== false) {
        $existingTables[] = $table;
    }
}

if (!$existingTables) {
    // The installer SQL starts with DROP TABLE IF EXISTS, so it is only
    // ever run against a database that has none of the core tables yet.
    $sql = file_get_contents('/var/www/html/sql/installer_public_release.sql');
    if ($sql === false || trim($sql) === '') {
        fail('Could not read sql/installer_public_release.sql');
    }
    $stmt = $pdo->query($sql);
    do {
    } while ($stmt->nextRowset());
    $stmt->closeCursor();
    out('Database schema imported.');
} elseif (!in_array('admin_users', $existingTables, true)) {
    fail('Database already contains some TIP Tool tables (' . implode(', ', $existingTables) . ') but not admin_users. Use an empty database or import the schema manually.');
} else {
    out('Database schema already present.');
}

if ((int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0) {
    out('Admin account exists - skipping admin creation.');
    exit(0);
}

$username = (string) tipEnv('ADMIN_USERNAME', '');
$password = (string) tipEnv('ADMIN_PASSWORD', '');
$email = (string) tipEnv('ADMIN_USER_EMAIL', '');

if ($username === '' || $password === '') {
    out('WARNING: no admin account yet. Set ADMIN_USERNAME, ADMIN_PASSWORD and ADMIN_USER_EMAIL, then restart.');
    exit(0);
}
if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
    fail('ADMIN_USERNAME must be 3-50 characters: letters, digits, dot, dash, or underscore.');
}
if (strlen($password) < 10 || strlen($password) > 72) {
    fail('ADMIN_PASSWORD must be 10-72 characters.');
}
if ($email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('ADMIN_USER_EMAIL must be a valid email address (max 100 characters).');
}

$insert = $pdo->prepare('INSERT INTO admin_users (username, password_hash, email, is_active) VALUES (?, ?, ?, 1)');
$insert->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email]);
out("Admin account \"$username\" created. You can now remove ADMIN_PASSWORD from .env.");
