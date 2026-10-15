<?php

// Secure session configuration for HTTPS
function initSecureSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        // Send Secure cookies on HTTPS, but allow local HTTP development in XAMPP.
        // Production should be served over HTTPS so the cookie remains Secure there.
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
        ini_set('session.cookie_secure', $isHttps ? '1' : '0');
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Strict');
        session_start();
    }
}

// Send security headers to protect against common web vulnerabilities
function sendSecurityHeaders()
{
    // Prevent clickjacking attacks
    header("X-Frame-Options: DENY");

    // Prevent MIME type sniffing
    header("X-Content-Type-Options: nosniff");

    // Enable XSS protection (legacy browsers)
    header("X-XSS-Protection: 1; mode=block");

    // Control referrer information
    header("Referrer-Policy: strict-origin-when-cross-origin");

    // Prevent search engines and AI crawlers from indexing private content
    header("X-Robots-Tag: noindex, nofollow, noarchive, nosnippet");

    // Prevent caching of sensitive or private content
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");

    // Reduce browser feature exposure for a higher-security private deployment
    header("Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()");
    header("Cross-Origin-Resource-Policy: same-origin");
    header("Cross-Origin-Opener-Policy: same-origin");

    // Enable HSTS when the site is served over HTTPS in production
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    }

    // Content Security Policy - Comprehensive protection against XSS, injection attacks
    header(
        "Content-Security-Policy: " .
            "default-src 'self'; " .
            "script-src 'self' https://cdnjs.cloudflare.com; " .
            "style-src 'self' 'unsafe-inline'; " .
            "img-src 'self' data:; " .
            "font-src 'self' data:; " .
            "connect-src 'self'; " .
            "frame-src 'self'; " .
            "object-src 'none'; " .
            "base-uri 'self'; " .
            "form-action 'self'; " .
            "frame-ancestors 'none'"
    );
}

// Utility functions
function sanitizeInput($input)
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateCSRFToken()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token)
{
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }

    // Default to 1 hour if CSRF_TOKEN_EXPIRY is not defined
    $expiry = defined('CSRF_TOKEN_EXPIRY') ? CSRF_TOKEN_EXPIRY : 3600;

    if (time() - $_SESSION['csrf_token_time'] > $expiry) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function logError($message)
{
    // Default logging settings if constants are not defined
    $enableLogging = defined('ENABLE_LOGGING') ? ENABLE_LOGGING : true;
    $logFile = defined('LOG_FILE') ? LOG_FILE : 'logs/error.log';

    if ($enableLogging) {
        error_log(date('Y-m-d H:i:s') . " - " . $message . PHP_EOL, 3, $logFile);
    }
}

function isValidEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Check if an IP address is blocked
 */
function isIPBlocked($ip)
{
    static $blockedCache = [];
    static $cacheTime = 0;

    // Cache for 5 minutes to avoid DB queries on every request
    if (time() - $cacheTime > 300) {
        $blockedCache = [];
        $cacheTime = time();

        try {
            // Load config if not already loaded
            if (!defined('DB_HOST')) {
                require_once __DIR__ . '/config.php';
            }

            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $stmt = $pdo->query("
                SELECT ip_address FROM blocked_ips
                WHERE is_permanent = 1 OR expires_at > NOW() OR expires_at IS NULL
            ");

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $blockedCache[] = $row['ip_address'];
            }
        } catch (Exception $e) {
            error_log("IP block check error: " . $e->getMessage());
            // If DB fails, allow access to prevent false blocks
            return false;
        }
    }

    // Check if IP is blocked (supports both individual IPs and CIDR blocks)
    require_once __DIR__ . '/cidr_utils.php';
    foreach ($blockedCache as $blockedEntry) {
        if (ipInCIDR($ip, $blockedEntry)) {
            return true;
        }
    }

    return false;
}

/**
 * Get client IP address
 */
function getClientIP()
{
    // Security: only trust client-supplied headers when explicitly enabled via
    // config (e.g. TRUST_CF_CONNECTING_IP = true when fronted by Cloudflare).
    // Headers like X-Forwarded-For / X-Real-IP are trivially spoofable on a
    // direct connection and must never be trusted by default, since they are
    // used for rate limiting and admin IP blocking.
    if (defined('TRUST_CF_CONNECTING_IP') && TRUST_CF_CONNECTING_IP && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Check if IP exceeds hourly rate limit (20 emails/hour)
 */
function checkHourlyRateLimit($ip)
{
    try {
        // Load config if not already loaded
        if (!defined('DB_HOST')) {
            require_once __DIR__ . '/config_path.php';
            require_once tipConfigDir() . '/config.php';
        }

        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM temp_emails
            WHERE ip_address = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$ip]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['count'] >= RATE_LIMIT_HOURLY;
    } catch (Exception $e) {
        error_log("Hourly rate limit check error: " . $e->getMessage());
        return false; // Allow access if DB fails
    }
}

/**
 * Check if IP exceeds daily rate limit (50 emails/day)
 */
function checkDailyRateLimit($ip)
{
    try {
        // Load config if not already loaded
        if (!defined('DB_HOST')) {
            require_once __DIR__ . '/config_path.php';
            require_once tipConfigDir() . '/config.php';
        }

        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM temp_emails
            WHERE ip_address = ?
              AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$ip]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['count'] >= RATE_LIMIT_DAILY;
    } catch (Exception $e) {
        error_log("Daily rate limit check error: " . $e->getMessage());
        return false; // Allow access if DB fails
    }
}

/**
 * Check for high volume abuse (more than 50 emails in 24 hours)
 */
function checkHighVolumeAbuse($ip)
{
    try {
        // Load config if not already loaded
        if (!defined('DB_HOST')) {
            require_once __DIR__ . '/config_path.php';
            require_once tipConfigDir() . '/config.php';
        }

        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM temp_emails
            WHERE ip_address = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute([$ip]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['count'] > 50;
    } catch (Exception $e) {
        error_log("High volume abuse check error: " . $e->getMessage());
        return false; // Allow access if DB fails
    }
}

/**
 * Log rate limit violations for monitoring
 */
function logRateLimitViolation($ip)
{
    static $logCache = [];
    static $lastLogTime = 0;

    // Prevent logging the same IP more than once per minute
    $cacheKey = $ip . '_' . date('Y-m-d-H-i');
    if (isset($logCache[$cacheKey])) {
        return;
    }
    $logCache[$cacheKey] = true;

    // Clean old cache entries (keep last 10 minutes)
    if (time() - $lastLogTime > 600) {
        $logCache = [];
        $lastLogTime = time();
    }

    try {
        // Load config if not already loaded
        if (!defined('DB_HOST')) {
            require_once __DIR__ . '/config_path.php';
            require_once tipConfigDir() . '/config.php';
        }

        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->prepare("
            INSERT INTO rate_limits (ip_address, endpoint, user_agent, created_at)
            VALUES (?, ?, ?, NOW())
        ");

        $endpoint = $_SERVER['REQUEST_URI'] ?? '/includes/handlers/generate_email.php';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        $stmt->execute([$ip, $endpoint, $userAgent]);
    } catch (Exception $e) {
        error_log("Rate limit violation logging failed: " . $e->getMessage());
        // Don't fail the rate limit response if logging fails
    }
}
