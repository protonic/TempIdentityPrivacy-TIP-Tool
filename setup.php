<?php

/**
 * =====================================================================
 *  WARNING: DELETE THIS FILE (setup.php) IMMEDIATELY AFTER SETUP SUCCEEDS.
 *
 *  This wizard writes real database credentials and a secret encryption
 *  key to disk. Leaving it reachable on a live server lets anyone who
 *  finds this URL overwrite your database configuration.
 *  DO NOT leave setup.php on a production web root once setup is done.
 * =====================================================================
 *
 * TIP Tool - First-run setup wizard.
 *
 * Creates external config/cron folders as siblings of the web root, using
 * names you choose (default tip-config / tip-cron):
 *   ../<config-folder>/config.php
 *   ../<config-folder>/database.php
 *   ../<cron-folder>/.htaccess
 *   ../<cron-folder>/cleanup_emails_fixed.php
 *   ../<cron-folder>/fetch_emails_fixed.php
 *
 * Custom folder names let multiple project copies (e.g. a "-dev" or "-auto"
 * checkout) sit as siblings under the same parent directory without one
 * setup run overwriting another's config.
 *
 * It also imports sql/installer_public_release.sql (only into a database with
 * none of the core tables, since that SQL starts with DROP TABLE IF EXISTS)
 * and creates the first admin account, unless an admin already exists.
 *
 * Run this once after uploading the project, then DELETE this file.
 * It refuses to run again once <config-folder>/config.php already exists,
 * unless you explicitly pass ?force=1.
 */

$webRoot = __DIR__;
$parentRoot = dirname($webRoot);

$force = isset($_GET['force']) && $_GET['force'] === '1';

$errors = [];
$success = false;
$writtenFiles = [];

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function random_secret($bytes = 32)
{
    return bin2hex(random_bytes($bytes));
}

function is_valid_folder_name($name)
{
    // Letters, digits, dash, underscore only - no slashes, dots, or path traversal.
    return $name !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $name) === 1;
}

const CORE_TABLES = [
    'admin_users', 'admin_sessions', 'security_log', 'email_messages', 'temp_emails',
    'domains', 'email_generation_stats', 'system_stats', 'rate_limits', 'blocked_ips',
];

// Runs every statement in the installer SQL. Each statement is fetched via
// nextRowset() so an error in any statement (not just the first) is thrown.
function import_schema(PDO $pdo, $sqlFile)
{
    $sql = file_get_contents($sqlFile);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("Could not read $sqlFile");
    }
    $stmt = $pdo->query($sql);
    do {
    } while ($stmt->nextRowset());
    $stmt->closeCursor();
}

$defaults = [
    'site_name' => 'TIP TOOL',
    'site_url' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
        . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('setup.php', '', $_SERVER['PHP_SELF'] ?? ''), '/'),
    'admin_email' => '',
    'admin_username' => 'admin',
    'admin_user_email' => '',
    'config_folder' => 'tip-config',
    'cron_folder' => 'tip-cron',
    'db_host' => 'localhost',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'cron_secret_key' => random_secret(),
    'email_expiry_hours' => 24,
    'rate_limit_hourly' => 20,
    'rate_limit_daily' => 50,
];

$input = $defaults;

// Allow the folder names to be previewed via GET before the form is submitted,
// so the "already configured" check below reflects what the user actually typed.
if (isset($_GET['config_folder'])) {
    $input['config_folder'] = trim((string) $_GET['config_folder']);
}
if (isset($_GET['cron_folder'])) {
    $input['cron_folder'] = trim((string) $_GET['cron_folder']);
}

// Preview paths using whatever folder names are currently selected (GET or defaults),
// so the "already configured" banner is accurate before the form is even submitted.
$configDir = $parentRoot . '/' . ($input['config_folder'] !== '' ? $input['config_folder'] : 'tip-config');
$cronDir = $parentRoot . '/' . ($input['cron_folder'] !== '' ? $input['cron_folder'] : 'tip-cron');
$configFile = $configDir . '/config.php';
$alreadyConfigured = is_file($configFile);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($input as $key => $default) {
        if (isset($_POST[$key])) {
            $input[$key] = trim((string) $_POST[$key]);
        }
    }

    if ($input['cron_secret_key'] === '') {
        $input['cron_secret_key'] = random_secret();
    }

    if (!is_valid_folder_name($input['config_folder'])) {
        $errors[] = 'Config folder name may only contain letters, digits, dashes, and underscores.';
    }
    if (!is_valid_folder_name($input['cron_folder'])) {
        $errors[] = 'Cron folder name may only contain letters, digits, dashes, and underscores.';
    }
    if ($input['config_folder'] === $input['cron_folder']) {
        $errors[] = 'Config folder name and cron folder name must be different.';
    }

    // Re-derive the real paths now that POSTed folder names are validated.
    if (is_valid_folder_name($input['config_folder']) && is_valid_folder_name($input['cron_folder'])) {
        $configDir = $parentRoot . '/' . $input['config_folder'];
        $cronDir = $parentRoot . '/' . $input['cron_folder'];
        $configFile = $configDir . '/config.php';
        $alreadyConfigured = is_file($configFile);
    }

    if ($alreadyConfigured && !$force) {
        $errors[] = "{$input['config_folder']}/config.php already exists. Setup has already run for this folder. Delete it manually, choose a different config folder name, or re-run with ?force=1 if you really want to overwrite it.";
    }

    if ($input['site_name'] === '') {
        $errors[] = 'Site name is required.';
    }
    if (!filter_var(rtrim($input['site_url'], '/'), FILTER_VALIDATE_URL)) {
        $errors[] = 'Site URL must be a valid URL.';
    }
    if ($input['admin_email'] !== '' && !filter_var($input['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Admin email must be a valid email address.';
    }
    if ($input['db_host'] === '' || $input['db_name'] === '' || $input['db_user'] === '') {
        $errors[] = 'Database host, name, and user are required.';
    }
    if (strlen($input['cron_secret_key']) < 32) {
        $errors[] = 'Cron secret key must be at least 32 characters.';
    }

    // Passwords are read raw (never trimmed, never echoed back into the form).
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

    // Test the DB connection and inspect the schema before writing anything.
    $pdo = null;
    $schemaAction = 'skip';
    $adminAction = 'skip';
    if (!$errors) {
        try {
            $pdo = new PDO(
                "mysql:host={$input['db_host']};dbname={$input['db_name']}",
                $input['db_user'],
                $input['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );

            $existingTables = [];
            foreach (CORE_TABLES as $table) {
                if ($pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn() !== false) {
                    $existingTables[] = $table;
                }
            }

            $hasAdminTable = in_array('admin_users', $existingTables, true);
            $hasAdmins = $hasAdminTable && (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0;

            if (!$existingTables) {
                // The installer SQL starts with DROP TABLE IF EXISTS, so it is only
                // ever run against a database that has none of the core tables yet.
                $schemaAction = 'import';
            } elseif (!$hasAdminTable) {
                $errors[] = 'This database already contains some TIP Tool tables (' . implode(', ', $existingTables)
                    . ') but not admin_users. To avoid overwriting data, import sql/installer_public_release.sql manually or use an empty database.';
            }

            if (!$errors) {
                if ($hasAdmins && !$force) {
                    $adminAction = 'skip';
                } else {
                    $adminAction = 'create';

                    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $input['admin_username'])) {
                        $errors[] = 'Admin username must be 3-50 characters: letters, digits, dot, dash, or underscore.';
                    }
                    if ($input['admin_user_email'] === '' || strlen($input['admin_user_email']) > 100
                        || !filter_var($input['admin_user_email'], FILTER_VALIDATE_EMAIL)) {
                        $errors[] = 'A valid admin account email address is required (max 100 characters).';
                    }
                    if (strlen($adminPassword) < 10) {
                        $errors[] = 'Admin password must be at least 10 characters.';
                    } elseif (strlen($adminPassword) > 72) {
                        $errors[] = 'Admin password must be at most 72 characters (bcrypt limit).';
                    }
                    if ($adminPassword !== $adminPasswordConfirm) {
                        $errors[] = 'Admin password and confirmation do not match.';
                    }
                    if (!$errors && $hasAdminTable) {
                        $check = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE username = ?');
                        $check->execute([$input['admin_username']]);
                        if ((int) $check->fetchColumn() > 0) {
                            $errors[] = 'An admin user with that username already exists.';
                        }
                    }
                }
            }
        } catch (PDOException $ex) {
            $errors[] = 'Could not connect to the database with those credentials: ' . $ex->getMessage();
        }
    }

    $setupNotes = [];

    if (!$errors) {
        try {
            if ($schemaAction === 'import') {
                import_schema($pdo, $webRoot . '/sql/installer_public_release.sql');
                $setupNotes[] = 'Database schema imported from sql/installer_public_release.sql.';
            } else {
                $setupNotes[] = 'Database schema already present - import skipped.';
            }

            if ($adminAction === 'create') {
                $insert = $pdo->prepare('INSERT INTO admin_users (username, password_hash, email, is_active) VALUES (?, ?, ?, 1)');
                $insert->execute([
                    $input['admin_username'],
                    password_hash($adminPassword, PASSWORD_DEFAULT),
                    $input['admin_user_email'],
                ]);
                $setupNotes[] = 'Admin account "' . $input['admin_username'] . '" created.';
            } else {
                $setupNotes[] = 'An admin account already exists - no new admin was created.';
            }
            $adminPassword = $adminPasswordConfirm = '';
            $pdo = null;
        } catch (Throwable $ex) {
            $errors[] = 'Database setup failed: ' . $ex->getMessage();
        }
    }

    if (!$errors) {
        try {
            if (!is_dir($configDir) && !mkdir($configDir, 0755, true)) {
                throw new RuntimeException("Could not create $configDir");
            }
            if (!is_dir($cronDir) && !mkdir($cronDir, 0755, true)) {
                throw new RuntimeException("Could not create $cronDir");
            }

            $webRootName = basename($webRoot);
            $configFolderName = $input['config_folder'];
            $cronFolderName = $input['cron_folder'];

            $configPhp = "<?php\n"
                . "// Main configuration file - generated by setup.php on " . date('Y-m-d H:i:s') . "\n"
                . "define('DB_HOST', " . var_export($input['db_host'], true) . ");\n"
                . "define('DB_USER', " . var_export($input['db_user'], true) . ");\n"
                . "define('DB_PASS', " . var_export($input['db_pass'], true) . ");\n"
                . "define('DB_NAME', " . var_export($input['db_name'], true) . ");\n\n"
                . "// Email settings\n"
                . "define('EMAIL_EXPIRY_HOURS', " . (int) $input['email_expiry_hours'] . ");\n"
                . "define('MAX_EMAILS_PER_DOMAIN', 100);\n"
                . "define('CLEANUP_INTERVAL', 3600);\n\n"
                . "// IMAP/POP3 settings\n"
                . "define('IMAP_TIMEOUT', 30);\n"
                . "define('POP3_TIMEOUT', 30);\n"
                . "define('MAX_EMAIL_SIZE', 10485760);\n\n"
                . "// System settings\n"
                . "define('SITE_NAME', " . var_export($input['site_name'], true) . ");\n"
                . "define('SITE_URL', " . var_export(rtrim($input['site_url'], '/') . '/', true) . ");\n"
                . "define('ADMIN_EMAIL', " . var_export($input['admin_email'], true) . ");\n"
                . "define('ENABLE_LOGGING', true);\n"
                . "define('LOG_FILE', dirname(__DIR__) . '/{$webRootName}/logs/error.log');\n\n"
                . "// Security settings\n"
                . "define('CSRF_TOKEN_EXPIRY', 3600);\n"
                . "define('RATE_LIMIT_REQUESTS', 10);\n"
                . "define('RATE_LIMIT_HOURLY', " . (int) $input['rate_limit_hourly'] . ");\n"
                . "define('RATE_LIMIT_DAILY', " . (int) $input['rate_limit_daily'] . ");\n"
                . "define('CRON_SECRET_KEY', " . var_export($input['cron_secret_key'], true) . ");\n"
                . "define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);\n\n"
                . "date_default_timezone_set('UTC');\n";

            $databasePhp = "<?php\n"
                . "require_once __DIR__ . '/config.php';\n\n"
                . "class Database\n"
                . "{\n"
                . "    private \$host = DB_HOST;\n"
                . "    private \$user = DB_USER;\n"
                . "    private \$pass = DB_PASS;\n"
                . "    private \$dbname = DB_NAME;\n"
                . "    private \$connection;\n\n"
                . "    public function connect()\n"
                . "    {\n"
                . "        \$this->connection = null;\n"
                . "        try {\n"
                . "            \$this->connection = new PDO(\n"
                . "                \"mysql:host=\" . \$this->host . \";dbname=\" . \$this->dbname,\n"
                . "                \$this->user,\n"
                . "                \$this->pass,\n"
                . "                [\n"
                . "                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
                . "                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n"
                . "                    PDO::MYSQL_ATTR_INIT_COMMAND => \"SET NAMES utf8mb4\"\n"
                . "                ]\n"
                . "            );\n"
                . "        } catch (PDOException \$e) {\n"
                . "            error_log(\"Database connection error: \" . \$e->getMessage());\n"
                . "            die(\"Database connection failed. Please try again later.\");\n"
                . "        }\n"
                . "        return \$this->connection;\n"
                . "    }\n"
                . "}\n";

            $cronHtaccess = "# Deny all access to cron directory\n"
                . "Order deny,allow\n"
                . "Deny from all\n";

            $cleanupPhp = "<?php\n\n"
                . "/**\n"
                . " * Email Cleanup Cron Job - Command Line Only\n"
                . " * Run this via cron every hour:\n"
                . " * 0 * * * * /usr/bin/php /path/to/{$cronFolderName}/cleanup_emails_fixed.php\n"
                . " */\n\n"
                . "try {\n"
                . "    \$projectRoot = dirname(__DIR__) . '/{$webRootName}';\n"
                . "    \$configRoot = dirname(__DIR__) . '/{$configFolderName}';\n\n"
                . "    chdir(\$projectRoot);\n\n"
                . "    require_once \$configRoot . '/config.php';\n"
                . "    require_once \$configRoot . '/database.php';\n"
                . "    require_once \$projectRoot . '/includes/functions.php';\n\n"
                . "    \$database = new Database();\n"
                . "    \$db = \$database->connect();\n\n"
                . "    if (!\$db) {\n"
                . "        throw new Exception(\"Database connection failed\");\n"
                . "    }\n\n"
                . "    // Delete expired emails\n"
                . "    \$stmt = \$db->prepare(\"\n"
                . "        DELETE FROM temp_emails \n"
                . "        WHERE expires_at < NOW() OR is_active = 0\n"
                . "    \");\n"
                . "    \$stmt->execute();\n"
                . "    \$expiredCount = \$stmt->rowCount();\n\n"
                . "    // Delete old email messages (older than 48 hours)\n"
                . "    \$stmt = \$db->prepare(\"\n"
                . "        DELETE em FROM email_messages em\n"
                . "        LEFT JOIN temp_emails te ON em.temp_email_id = te.id\n"
                . "        WHERE te.id IS NULL OR em.received_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)\n"
                . "    \");\n"
                . "    \$stmt->execute();\n"
                . "    \$messagesDeleted = \$stmt->rowCount();\n\n"
                . "    // Update active email count\n"
                . "    \$stmt = \$db->prepare(\"\n"
                . "        SELECT COUNT(*) as active_count FROM temp_emails WHERE is_active = 1 AND expires_at > NOW()\n"
                . "    \");\n"
                . "    \$stmt->execute();\n"
                . "    \$result = \$stmt->fetch();\n"
                . "    \$activeCount = \$result ? \$result['active_count'] : 0;\n\n"
                . "    // Update or insert system stats\n"
                . "    \$stmt = \$db->prepare(\"\n"
                . "        INSERT INTO system_stats (stat_name, stat_value) \n"
                . "        VALUES ('active_email_addresses', ?) \n"
                . "        ON DUPLICATE KEY UPDATE stat_value = ?\n"
                . "    \");\n"
                . "    \$stmt->execute([\$activeCount, \$activeCount]);\n\n"
                . "    \$message = \"Cleanup completed: {\$expiredCount} expired emails deleted, {\$messagesDeleted} old messages deleted, {\$activeCount} active emails remaining\";\n"
                . "    logError(\$message);\n\n"
                . "    if (isset(\$_SERVER['HTTP_HOST'])) {\n"
                . "        header('Content-Type: application/json');\n"
                . "        echo json_encode([\n"
                . "            'success' => true,\n"
                . "            'message' => \$message,\n"
                . "            'expired_deleted' => \$expiredCount,\n"
                . "            'messages_deleted' => \$messagesDeleted,\n"
                . "            'active_count' => \$activeCount,\n"
                . "            'timestamp' => date('Y-m-d H:i:s')\n"
                . "        ]);\n"
                . "    }\n"
                . "} catch (Exception \$e) {\n"
                . "    \$error = \"Cleanup error: \" . \$e->getMessage();\n"
                . "    logError(\$error);\n\n"
                . "    if (isset(\$_SERVER['HTTP_HOST'])) {\n"
                . "        header('Content-Type: application/json');\n"
                . "        http_response_code(500);\n"
                . "        echo json_encode([\n"
                . "            'success' => false,\n"
                . "            'error' => \$error,\n"
                . "            'timestamp' => date('Y-m-d H:i:s')\n"
                . "        ]);\n"
                . "    }\n"
                . "}\n";

            $fetchPhp = "<?php\n\n"
                . "/**\n"
                . " * Email Fetch Cron Job - Command Line Only\n"
                . " * Run this via cron every 2-5 minutes:\n"
                . " * Every 2 minutes: /usr/bin/php /path/to/{$cronFolderName}/fetch_emails_fixed.php\n"
                . " */\n\n"
                . "try {\n"
                . "    \$projectRoot = dirname(__DIR__) . '/{$webRootName}';\n"
                . "    \$configRoot = dirname(__DIR__) . '/{$configFolderName}';\n\n"
                . "    chdir(\$projectRoot);\n\n"
                . "    require_once \$configRoot . '/config.php';\n"
                . "    require_once \$configRoot . '/database.php';\n"
                . "    require_once \$projectRoot . '/includes/functions.php';\n"
                . "    require_once \$projectRoot . '/includes/fast_imap_fetcher.php';\n\n"
                . "    \$database = new Database();\n"
                . "    \$db = \$database->connect();\n\n"
                . "    if (!\$db) {\n"
                . "        throw new Exception(\"Database connection failed\");\n"
                . "    }\n\n"
                . "    // Get all active domains\n"
                . "    \$stmt = \$db->prepare(\"SELECT * FROM domains WHERE is_active = 1\");\n"
                . "    \$stmt->execute();\n"
                . "    \$domains = \$stmt->fetchAll();\n\n"
                . "    \$totalProcessed = 0;\n"
                . "    \$totalStored = 0;\n\n"
                . "    foreach (\$domains as \$domain) {\n"
                . "        try {\n"
                . "            // Decrypt the password if it's encrypted\n"
                . "            if (!empty(\$domain['imap_password_encrypted'])) {\n"
                . "                try {\n"
                . "                    \$raw_password = base64_decode(\$domain['imap_password_encrypted']);\n"
                . "                    \$decrypted_password = false;\n"
                . "                    if (strlen(\$raw_password) > 16) {\n"
                . "                        \$decrypted_password = openssl_decrypt(\n"
                . "                            substr(\$raw_password, 16),\n"
                . "                            'AES-256-CBC',\n"
                . "                            CRON_SECRET_KEY,\n"
                . "                            0,\n"
                . "                            substr(\$raw_password, 0, 16)\n"
                . "                        );\n"
                . "                    }\n"
                . "                    if (\$decrypted_password === false || \$decrypted_password === '') {\n"
                . "                        // Backward compatibility with old deterministic-IV encrypted rows\n"
                . "                        \$decrypted_password = openssl_decrypt(\n"
                . "                            \$raw_password,\n"
                . "                            'AES-256-CBC',\n"
                . "                            CRON_SECRET_KEY,\n"
                . "                            0,\n"
                . "                            substr(hash('sha256', CRON_SECRET_KEY), 0, 16)\n"
                . "                        );\n"
                . "                    }\n"
                . "                    \$domain['imap_password'] = \$decrypted_password;\n"
                . "                } catch (Exception \$e) {\n"
                . "                    error_log(\"Password decryption failed for domain {\$domain['domain_name']}: \" . \$e->getMessage());\n"
                . "                    continue;\n"
                . "                }\n"
                . "            } elseif (empty(\$domain['imap_password'])) {\n"
                . "                error_log(\"No password found for domain {\$domain['domain_name']}\");\n"
                . "                continue;\n"
                . "            }\n\n"
                . "            // Check if there are any active temporary emails for this domain\n"
                . "            \$stmt = \$db->prepare(\"\n"
                . "                SELECT COUNT(*) as count \n"
                . "                FROM temp_emails \n"
                . "                WHERE domain_id = ? AND is_active = 1 AND expires_at > NOW()\n"
                . "            \");\n"
                . "            \$stmt->execute([\$domain['id']]);\n"
                . "            \$activeEmailCount = \$stmt->fetch()['count'];\n\n"
                . "            // Skip IMAP connection if no active temp emails exist\n"
                . "            if (\$activeEmailCount == 0) {\n"
                . "                logError(\"Domain {\$domain['domain_name']}: No active temp emails, skipping IMAP check\");\n"
                . "                continue;\n"
                . "            }\n\n"
                . "            // Use optimized Fast IMAP (15-30 second delivery with 15s cron)\n"
                . "            \$protocol = 'fast-imap';\n"
                . "            \$start_time = microtime(true);\n\n"
                . "            \$fetcher = new FastImapEmailFetcher(\$domain, \$db);\n"
                . "            logError(\"Using Fast IMAP for domain {\$domain['domain_name']} (optimized mode) - {\$activeEmailCount} active email(s)\");\n\n"
                . "            \$fetcher->connect();\n"
                . "            \$emails = \$fetcher->fetchNewEmails();\n"
                . "            \$processed = 0;\n"
                . "            \$stored = 0;\n\n"
                . "            foreach (\$emails as \$email) {\n"
                . "                \$processed++;\n\n"
                . "                // Extract recipient email from the 'to' field\n"
                . "                \$recipient = \$email['to'];\n\n"
                . "                // Check if this email address exists in our temp_emails table\n"
                . "                \$stmt = \$db->prepare(\"\n"
                . "                    SELECT id FROM temp_emails \n"
                . "                    WHERE email_address = ? AND is_active = 1 AND expires_at > NOW()\n"
                . "                \");\n"
                . "                \$stmt->execute([\$recipient]);\n"
                . "                \$tempEmail = \$stmt->fetch();\n\n"
                . "                if (\$tempEmail) {\n"
                . "                    // Check if this message already exists\n"
                . "                    \$messageId = \$email['message_id'] ?? md5(\$email['subject'] . \$email['date'] . \$email['from']);\n"
                . "                    \$stmt = \$db->prepare(\"\n"
                . "                        SELECT id FROM email_messages \n"
                . "                        WHERE temp_email_id = ? AND message_id = ?\n"
                . "                    \");\n"
                . "                    \$stmt->execute([\$tempEmail['id'], \$messageId]);\n\n"
                . "                    if (!\$stmt->fetch()) {\n"
                . "                        // Insert new message\n"
                . "                        \$stmt = \$db->prepare(\"\n"
                . "                            INSERT INTO email_messages \n"
                . "                            (temp_email_id, sender_email, sender_name, subject, body_text, body_html, received_at, message_id, has_attachments) \n"
                . "                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\n"
                . "                        \");\n\n"
                . "                        \$result = \$stmt->execute([\n"
                . "                            \$tempEmail['id'],\n"
                . "                            \$email['from'],\n"
                . "                            \$email['from_name'] ?? '',\n"
                . "                            \$email['subject'],\n"
                . "                            \$email['body_text'] ?? '',\n"
                . "                            \$email['body_html'] ?? '',\n"
                . "                            \$email['date'],\n"
                . "                            \$messageId,\n"
                . "                            \$email['has_attachments'] ?? 0\n"
                . "                        ]);\n\n"
                . "                        if (\$result) {\n"
                . "                            \$stored++;\n"
                . "                        }\n"
                . "                    }\n"
                . "                }\n"
                . "            }\n\n"
                . "            \$fetcher->disconnect();\n\n"
                . "            \$totalProcessed += \$processed;\n"
                . "            \$totalStored += \$stored;\n\n"
                . "            \$fetch_time = round((microtime(true) - \$start_time) * 1000, 2);\n"
                . "            logError(\"Domain {\$domain['domain_name']} [{\$protocol}]: processed \$processed emails, stored \$stored new emails in {\$fetch_time}ms\");\n"
                . "        } catch (Exception \$e) {\n"
                . "            logError(\"Error fetching emails for domain {\$domain['domain_name']}: \" . \$e->getMessage());\n"
                . "            continue;\n"
                . "        }\n"
                . "    }\n\n"
                . "    \$message = \"Email fetch completed: processed \$totalProcessed emails, stored \$totalStored new emails\";\n"
                . "    logError(\$message);\n\n"
                . "    if (isset(\$_SERVER['HTTP_HOST'])) {\n"
                . "        header('Content-Type: application/json');\n"
                . "        echo json_encode([\n"
                . "            'success' => true,\n"
                . "            'message' => \$message,\n"
                . "            'processed' => \$totalProcessed,\n"
                . "            'stored' => \$totalStored,\n"
                . "            'timestamp' => date('Y-m-d H:i:s')\n"
                . "        ]);\n"
                . "    }\n"
                . "} catch (Exception \$e) {\n"
                . "    \$error = \"Email fetch error: \" . \$e->getMessage();\n"
                . "    logError(\$error);\n\n"
                . "    if (isset(\$_SERVER['HTTP_HOST'])) {\n"
                . "        header('Content-Type: application/json');\n"
                . "        http_response_code(500);\n"
                . "        echo json_encode([\n"
                . "            'success' => false,\n"
                . "            'error' => \$error,\n"
                . "            'timestamp' => date('Y-m-d H:i:s')\n"
                . "        ]);\n"
                . "    }\n"
                . "}\n";

            $configFolderNamePhp = "<?php\n\n"
                . "// Name of the sibling folder holding config.php and database.php.\n"
                . "// Written by setup.php - do not edit by hand.\n\n"
                . "return " . var_export($configFolderName, true) . ";\n";

            $cronFolderNamePhp = "<?php\n\n"
                . "// Name of the sibling folder holding the standalone cron scripts.\n"
                . "// Written by setup.php - do not edit by hand.\n\n"
                . "return " . var_export($cronFolderName, true) . ";\n";

            $files = [
                $configDir . '/config.php' => $configPhp,
                $configDir . '/database.php' => $databasePhp,
                $cronDir . '/.htaccess' => $cronHtaccess,
                $cronDir . '/cleanup_emails_fixed.php' => $cleanupPhp,
                $cronDir . '/fetch_emails_fixed.php' => $fetchPhp,
                $webRoot . '/includes/config_folder_name.php' => $configFolderNamePhp,
                $webRoot . '/includes/cron_folder_name.php' => $cronFolderNamePhp,
            ];

            foreach ($files as $path => $contents) {
                if (file_put_contents($path, $contents) === false) {
                    throw new RuntimeException("Could not write $path");
                }
                $writtenFiles[] = $path;
            }

            $success = true;
        } catch (Throwable $ex) {
            $errors[] = 'Setup failed: ' . $ex->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>TIP Tool - Setup</title>
    <style>
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: #0F172A;
            color: #E2E8F0;
            margin: 0;
            padding: 2rem 1rem;
        }

        .wrap {
            max-width: 640px;
            margin: 0 auto;
        }

        h1 {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
        }

        p.sub {
            color: #94A3B8;
            margin-top: 0;
            margin-bottom: 1.5rem;
        }

        .card {
            background: #1E293B;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        fieldset {
            border: 1px solid #334155;
            border-radius: 6px;
            margin-bottom: 1rem;
            padding: 1rem;
        }

        legend {
            padding: 0 0.5rem;
            color: #38BDF8;
            font-weight: 600;
        }

        label {
            display: block;
            font-size: 0.85rem;
            margin-top: 0.75rem;
            margin-bottom: 0.25rem;
            color: #CBD5E1;
        }

        input[type=text],
        input[type=email],
        input[type=password],
        input[type=number],
        input[type=url] {
            width: 100%;
            padding: 0.5rem 0.6rem;
            background: #0F172A;
            border: 1px solid #334155;
            border-radius: 6px;
            color: #E2E8F0;
            font-size: 0.9rem;
            box-sizing: border-box;
        }

        button {
            margin-top: 1.5rem;
            width: 100%;
            padding: 0.75rem;
            background: #0EA5E9;
            border: none;
            border-radius: 6px;
            color: #0F172A;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
        }

        button:hover {
            background: #38BDF8;
        }

        .btn-link {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            width: 100%;
            padding: 0.75rem;
            background: #0EA5E9;
            border: none;
            border-radius: 6px;
            color: #0F172A;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
            box-sizing: border-box;
        }

        .btn-link:hover {
            background: #38BDF8;
        }

        .msg {
            border-radius: 6px;
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .msg.err {
            background: #450A0A;
            border: 1px solid #7F1D1D;
            color: #FCA5A5;
        }

        .msg.ok {
            background: #052E1A;
            border: 1px solid #14532D;
            color: #86EFAC;
        }

        .msg.warn {
            background: #451A03;
            border: 1px solid #92400E;
            color: #FCD34D;
        }

        code {
            background: #0F172A;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            color: #FBBF24;
            word-break: break-all;
        }

        ul {
            margin: 0.5rem 0 0;
            padding-left: 1.2rem;
        }

        .hint {
            font-size: 0.78rem;
            color: #64748B;
            margin-top: 0.25rem;
        }

        .hint.good {
            color: #86EFAC;
        }

        .hint.bad {
            color: #FCA5A5;
        }

        .pw-wrap {
            position: relative;
        }

        .pw-wrap input {
            padding-right: 2.6rem;
        }

        button.pw-toggle {
            position: absolute;
            top: 50%;
            right: 0.35rem;
            transform: translateY(-50%);
            width: 2rem;
            height: 2rem;
            margin: 0;
            padding: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            color: #94A3B8;
            border-radius: 6px;
        }

        button.pw-toggle:hover,
        button.pw-toggle:focus-visible {
            background: #1E293B;
            color: #E2E8F0;
        }

        button.pw-toggle svg {
            width: 1.2rem;
            height: 1.2rem;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <h1>TIP Tool Setup</h1>
        <p class="sub">Creates config and cron folders next to this web root — you choose the folder names below.</p>

        <div class="msg warn">
            <strong>⚠ Delete this file when you are done.</strong>
            <code>setup.php</code> can write database credentials and a secret key to disk.
            Leaving it on a live server after setup succeeds is a security risk — remove it
            from the web root as soon as setup completes successfully.
        </div>

        <?php if ($success): ?>
            <div class="msg ok">
                <strong>Setup complete.</strong> The following files were written:
                <ul>
                    <?php foreach ($writtenFiles as $f): ?>
                        <li><code><?= e($f) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="msg ok">
                <strong>Database:</strong>
                <ul>
                    <?php foreach ($setupNotes as $note): ?>
                        <li><?= e($note) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="card">
                <p><strong>Next steps:</strong></p>
                <ul>
                    <li>Sign in at <code>/admin/login</code>, open <code>Manage Domains</code>, and add your catch-all IMAP mailbox.</li>
                    <li>Point your Hostinger/host cron jobs at:
                        <ul>
                            <li><code><?= e($cronDir . '/fetch_emails_fixed.php') ?></code> (every 1-2 minutes)</li>
                            <li><code><?= e($cronDir . '/cleanup_emails_fixed.php') ?></code> (hourly)</li>
                        </ul>
                    </li>
                    <li><strong>⚠ Delete <code>setup.php</code> from the web root right now</strong> — it should never remain on a live server after setup succeeds.</li>
                </ul>
                <a class="btn-link" href="<?= e(rtrim($input['site_url'], '/') . '/') ?>">Finish &amp; go to homepage</a>
            </div>
        <?php else: ?>

            <?php if ($alreadyConfigured && !$force): ?>
                <div class="msg err">
                    <strong>Already configured.</strong> <code><?= e($configFile) ?></code> exists.
                    Re-running setup would overwrite live credentials. If you really intend to
                    regenerate everything, reload this page with <code>?force=1</code>.
                </div>
            <?php endif; ?>

            <?php foreach ($errors as $err): ?>
                <div class="msg err"><?= e($err) ?></div>
            <?php endforeach; ?>

            <form method="post" action="setup.php<?= $force ? '?force=1' : '' ?>" class="card">
                <fieldset>
                    <legend>Site</legend>
                    <label>Site name</label>
                    <input type="text" name="site_name" value="<?= e($input['site_name']) ?>" required>

                    <label>Site URL</label>
                    <input type="url" name="site_url" value="<?= e($input['site_url']) ?>" required>

                    <label>Admin notification email (optional)</label>
                    <input type="email" name="admin_email" value="<?= e($input['admin_email']) ?>">
                </fieldset>

                <fieldset>
                    <legend>Folders</legend>
                    <label>Config folder name (created as a sibling of this web root)</label>
                    <input type="text" name="config_folder" value="<?= e($input['config_folder']) ?>" pattern="[A-Za-z0-9_-]+" required>
                    <p class="hint">Will be created at <code><?= e($configDir) ?></code>. Change this if another project copy already uses <code>tip-config</code> at this level.</p>

                    <label>Cron folder name (created as a sibling of this web root)</label>
                    <input type="text" name="cron_folder" value="<?= e($input['cron_folder']) ?>" pattern="[A-Za-z0-9_-]+" required>
                    <p class="hint">Will be created at <code><?= e($cronDir) ?></code>.</p>
                </fieldset>

                <fieldset>
                    <legend>Database</legend>
                    <label>DB host</label>
                    <input type="text" name="db_host" value="<?= e($input['db_host']) ?>" required>

                    <label>DB name</label>
                    <input type="text" name="db_name" value="<?= e($input['db_name']) ?>" required>

                    <label>DB user</label>
                    <input type="text" name="db_user" value="<?= e($input['db_user']) ?>" required>

                    <label>DB password</label>
                    <input type="password" name="db_pass" value="<?= e($input['db_pass']) ?>">
                    <p class="hint">If this database has none of the TIP Tool tables yet, the schema is imported for you.</p>
                </fieldset>

                <fieldset>
                    <legend>Admin account</legend>
                    <p class="hint">Required for a fresh install. Skipped automatically if the database already has an admin (unless you use <code>?force=1</code>). Passwords are never stored in plain text and are not re-filled if the form is redisplayed.</p>

                    <label for="admin_username">Username (used to sign in)</label>
                    <input type="text" id="admin_username" name="admin_username" value="<?= e($input['admin_username']) ?>" pattern="[A-Za-z0-9_.\-]{3,50}" autocomplete="username">

                    <label for="admin_user_email">Email</label>
                    <input type="email" id="admin_user_email" name="admin_user_email" value="<?= e($input['admin_user_email']) ?>" placeholder="john.doe@example.com" maxlength="100" autocomplete="email">

                    <label for="admin_password">Password (10-72 characters)</label>
                    <div class="pw-wrap">
                        <input type="password" id="admin_password" name="admin_password" minlength="10" maxlength="72" autocomplete="new-password">
                        <button type="button" class="pw-toggle" data-target="admin_password" aria-label="Show password" aria-pressed="false"></button>
                    </div>

                    <label for="admin_password_confirm">Confirm password</label>
                    <div class="pw-wrap">
                        <input type="password" id="admin_password_confirm" name="admin_password_confirm" minlength="10" maxlength="72" autocomplete="new-password">
                        <button type="button" class="pw-toggle" data-target="admin_password_confirm" aria-label="Show password" aria-pressed="false"></button>
                    </div>
                    <p class="hint" id="pw-match" aria-live="polite"></p>
                </fieldset>

                <fieldset>
                    <legend>Security</legend>
                    <label>Cron secret key (used to encrypt IMAP passwords)</label>
                    <input type="text" name="cron_secret_key" value="<?= e($input['cron_secret_key']) ?>" required>
                    <p class="hint">Auto-generated. Leave as-is unless you have a reason to change it. Keep it secret and never commit it.</p>
                </fieldset>

                <fieldset>
                    <legend>Limits</legend>
                    <label>Email expiry (hours)</label>
                    <input type="number" name="email_expiry_hours" value="<?= e($input['email_expiry_hours']) ?>" min="1">

                    <label>Rate limit - per hour per IP</label>
                    <input type="number" name="rate_limit_hourly" value="<?= e($input['rate_limit_hourly']) ?>" min="1">

                    <label>Rate limit - per day per IP</label>
                    <input type="number" name="rate_limit_daily" value="<?= e($input['rate_limit_daily']) ?>" min="1">
                </fieldset>

                <button type="submit">Run setup</button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        (function() {
            var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
            var EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

            document.querySelectorAll('.pw-toggle').forEach(function(btn) {
                var input = document.getElementById(btn.dataset.target);
                btn.innerHTML = EYE;
                btn.addEventListener('click', function() {
                    var show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    btn.innerHTML = show ? EYE_OFF : EYE;
                    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
                });
            });

            var pw = document.getElementById('admin_password');
            var confirmPw = document.getElementById('admin_password_confirm');
            var status = document.getElementById('pw-match');
            if (!pw || !confirmPw || !status) return;

            function checkMatch() {
                if (confirmPw.value === '') {
                    status.textContent = '';
                    status.className = 'hint';
                    confirmPw.setCustomValidity('');
                    return;
                }
                if (pw.value === confirmPw.value) {
                    status.textContent = 'Passwords match.';
                    status.className = 'hint good';
                    confirmPw.setCustomValidity('');
                } else {
                    status.textContent = 'Passwords do not match.';
                    status.className = 'hint bad';
                    confirmPw.setCustomValidity('Passwords do not match');
                }
            }
            pw.addEventListener('input', checkMatch);
            confirmPw.addEventListener('input', checkMatch);
        })();
    </script>
</body>

</html>
