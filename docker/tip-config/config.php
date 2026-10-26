<?php
// Main configuration file for the Docker image.
// Same constants setup.php writes, but every value comes from an environment
// variable (see .env.example) so no secrets are baked into the image.

if (!function_exists('tipEnv')) {
    function tipEnv($name, $default = null)
    {
        $value = getenv($name);
        return ($value === false || $value === '') ? $default : $value;
    }
}

define('DB_HOST', tipEnv('DB_HOST', 'db'));
define('DB_USER', tipEnv('DB_USER', 'tiptool'));
define('DB_PASS', tipEnv('DB_PASS', ''));
define('DB_NAME', tipEnv('DB_NAME', 'tiptool'));

// Email settings
define('EMAIL_EXPIRY_HOURS', (int) tipEnv('EMAIL_EXPIRY_HOURS', 24));
define('MAX_EMAILS_PER_DOMAIN', 100);
define('CLEANUP_INTERVAL', 3600);

// IMAP/POP3 settings
define('IMAP_TIMEOUT', 30);
define('POP3_TIMEOUT', 30);
define('MAX_EMAIL_SIZE', 10485760);

// System settings
define('SITE_NAME', tipEnv('SITE_NAME', 'TIP TOOL'));
define('SITE_URL', rtrim(tipEnv('SITE_URL', 'http://localhost:8080'), '/') . '/');
define('ADMIN_EMAIL', tipEnv('ADMIN_EMAIL', ''));
define('ENABLE_LOGGING', true);
define('LOG_FILE', dirname(__DIR__) . '/html/logs/error.log');

// Security settings
define('CSRF_TOKEN_EXPIRY', 3600);
// Admin session IP pinning: 'subnet' (same /24 or /64), 'strict' (exact IP), or 'off'.
define('SESSION_PIN_IP', tipEnv('SESSION_PIN_IP', 'subnet'));
define('RATE_LIMIT_REQUESTS', 10);
define('RATE_LIMIT_HOURLY', (int) tipEnv('RATE_LIMIT_HOURLY', 20));
define('RATE_LIMIT_DAILY', (int) tipEnv('RATE_LIMIT_DAILY', 50));
define('CRON_SECRET_KEY', tipEnv('CRON_SECRET_KEY', ''));
define('TRUST_CF_CONNECTING_IP', filter_var(tipEnv('TRUST_CF_CONNECTING_IP', 'false'), FILTER_VALIDATE_BOOLEAN));
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);

date_default_timezone_set('UTC');
