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
            "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
            "style-src 'self' 'unsafe-inline'; " .
            "img-src 'self' data: https:; " .
            "font-src 'self' data:; " .
            "connect-src 'self'; " .
            "frame-src 'self'; " .
            "object-src 'none'; " .
            "base-uri 'self'; " .
            "form-action 'self'; " .
            "frame-ancestors 'none'"
    );
}

// IMAP Email Fetching Functions
class ImapEmailFetcher
{
    private $imap_connection;
    private $domain_config;

    public function __construct($domain_config)
    {
        $this->domain_config = $domain_config;
    }

    public function connect()
    {
        // Start with INBOX connection
        $mailbox = "{{$this->domain_config['imap_server']}:{$this->domain_config['imap_port']}/imap/ssl/novalidate-cert}INBOX";

        $this->imap_connection = imap_open(
            $mailbox,
            $this->domain_config['imap_username'],
            $this->domain_config['imap_password']
        );

        if (!$this->imap_connection) {
            throw new Exception("IMAP connection failed: " . imap_last_error());
        }

        return true;
    }

    public function fetchNewEmails()
    {
        if (!$this->imap_connection) {
            throw new Exception("No IMAP connection");
        }

        $all_emails = [];

        // Define folders to check (in order of priority)
        $folders_to_check = [
            'INBOX',           // Primary inbox
            'INBOX.Junk',      // Common junk folder
            'INBOX.Spam',      // Common spam folder  
            'Junk',            // Alternative junk folder
            'Spam'             // Alternative spam folder
        ];

        foreach ($folders_to_check as $folder) {
            try {
                $emails_from_folder = $this->fetchEmailsFromFolder($folder);
                if (!empty($emails_from_folder)) {
                    // Add folder info to each email
                    foreach ($emails_from_folder as &$email) {
                        $email['source_folder'] = $folder;
                    }
                    $all_emails = array_merge($all_emails, $emails_from_folder);
                }
            } catch (Exception $e) {
                // Log but continue with other folders
                error_log("Could not fetch from folder '$folder': " . $e->getMessage());
                continue;
            }
        }

        return $all_emails;
    }

    private function fetchEmailsFromFolder($folder)
    {
        // Switch to the specified folder
        $mailbox = "{{$this->domain_config['imap_server']}:{$this->domain_config['imap_port']}/imap/ssl/novalidate-cert}$folder";

        // Close current connection and open new one for this folder
        if ($this->imap_connection) {
            imap_close($this->imap_connection);
        }

        $this->imap_connection = imap_open(
            $mailbox,
            $this->domain_config['imap_username'],
            $this->domain_config['imap_password']
        );

        if (!$this->imap_connection) {
            throw new Exception("Could not open folder '$folder': " . imap_last_error());
        }

        $emails = [];
        $num_messages = imap_num_msg($this->imap_connection);

        for ($i = $num_messages; $i > 0; $i--) {
            $header = imap_headerinfo($this->imap_connection, $i);
            $body_data = $this->getEmailBody($i);

            // Parse email details
            $email_data = [
                'subject' => isset($header->subject) ? imap_utf8($header->subject) : 'No Subject',
                'from' => isset($header->from[0]) ? $header->from[0]->mailbox . '@' . $header->from[0]->host : 'Unknown',
                'from_name' => isset($header->from[0]->personal) ? imap_utf8($header->from[0]->personal) : '',
                'to' => isset($header->to[0]) ? $header->to[0]->mailbox . '@' . $header->to[0]->host : '',
                'date' => isset($header->date) ? date('Y-m-d H:i:s', strtotime($header->date)) : date('Y-m-d H:i:s'),
                'body_text' => $body_data['text'],
                'body_html' => $body_data['html'],
                'message_id' => isset($header->message_id) ? $header->message_id : '',
                'has_attachments' => $this->hasAttachments($i)
            ];

            $emails[] = $email_data;
        }

        return $emails;
    }

    private function getEmailBody($message_number)
    {
        $structure = imap_fetchstructure($this->imap_connection, $message_number);
        $body_text = '';
        $body_html = '';

        if (isset($structure->parts) && count($structure->parts)) {
            // Multi-part message
            for ($i = 0; $i < count($structure->parts); $i++) {
                $part = $structure->parts[$i];
                $body_part = imap_fetchbody($this->imap_connection, $message_number, $i + 1);

                // Decode if necessary
                if ($part->encoding == 3) { // Base64
                    $body_part = base64_decode($body_part);
                } elseif ($part->encoding == 4) { // Quoted-printable
                    $body_part = quoted_printable_decode($body_part);
                }

                // Check content type
                if ($part->subtype == 'PLAIN') {
                    $body_text = $body_part;
                } elseif ($part->subtype == 'HTML') {
                    $body_html = $body_part;
                }
            }
        } else {
            // Simple message
            $body = imap_body($this->imap_connection, $message_number);

            // Decode if necessary
            if ($structure->encoding == 3) { // Base64
                $body = base64_decode($body);
            } elseif ($structure->encoding == 4) { // Quoted-printable
                $body = quoted_printable_decode($body);
            }

            // Check if it's HTML or plain text
            if ($structure->subtype == 'HTML') {
                $body_html = $body;
                // Strip HTML tags for text version
                $body_text = strip_tags($body);
            } else {
                $body_text = $body;
            }
        }

        // If we have HTML but no text, create text version
        if (!empty($body_html) && empty($body_text)) {
            $body_text = $this->htmlToText($body_html);
        }

        // If we have neither, use the raw body as fallback
        if (empty($body_html) && empty($body_text)) {
            $body_text = imap_body($this->imap_connection, $message_number);
        }

        return [
            'text' => $body_text,
            'html' => $body_html
        ];
    }

    private function htmlToText($html)
    {
        // Remove HTML tags and decode entities
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    private function hasAttachments($message_number)
    {
        $structure = imap_fetchstructure($this->imap_connection, $message_number);
        return isset($structure->parts) && count($structure->parts) > 1;
    }

    public function disconnect()
    {
        if ($this->imap_connection) {
            imap_close($this->imap_connection);
        }
    }
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
