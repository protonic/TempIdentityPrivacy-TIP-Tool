<?php
// Admin Login System
session_start();

require_once '../includes/config_path.php';
require_once tipConfigDir() . '/config.php';
require_once '../includes/functions.php';
require_once '../includes/database_class.php';

// Check if already logged in
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: ../mailbox');
    exit;
}

// Basic anti-brute-force throttle based on IP and user-agent.
// Persisted in the `rate_limits` table (keyed by IP + endpoint) rather than
// $_SESSION so it survives a cookie/session reset from the same IP.
$rateLimitMaxAttempts = 8;
$rateLimitWindowMinutes = 15;
$loginRateLimitEndpoint = 'admin_login';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

$error_message = '';

/**
 * Count recent login-throttle hits for this IP within the rate limit window.
 */
function getLoginAttemptCount(PDO $conn, $ip, $endpoint, $windowMinutes)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM rate_limits
        WHERE ip_address = ? AND endpoint = ?
          AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $stmt->execute([$ip, $endpoint, $windowMinutes]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($result['count'] ?? 0);
}

/**
 * Record one login-throttle hit for this IP.
 */
function recordLoginAttempt(PDO $conn, $ip, $endpoint, $userAgent)
{
    $stmt = $conn->prepare("
        INSERT INTO rate_limits (ip_address, endpoint, user_agent, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$ip, $endpoint, $userAgent]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (!$csrfToken || !validateCSRFToken($csrfToken)) {
        $error_message = 'Invalid request token. Please refresh the page and try again.';
    } elseif (!empty($username) && !empty($password)) {
        try {
            $db = new Database();
            $conn = $db->connect();

            if (!$conn) {
                throw new Exception("Database connection failed");
            }

            $attempts = getLoginAttemptCount($conn, $ipAddress, $loginRateLimitEndpoint, $rateLimitWindowMinutes);
        } catch (Exception $e) {
            $conn = null;
            $attempts = 0;
            error_log("Admin login rate limit check error: " . $e->getMessage());
        }

        if ($attempts >= $rateLimitMaxAttempts) {
            $error_message = 'Too many login attempts from your network. Please wait a few minutes before trying again.';
        } else {
            try {
                if (!$conn) {
                    throw new Exception("Database connection failed");
                }

                // Get user from database
                $stmt = $conn->prepare("
                    SELECT id, username, password_hash, is_active, failed_login_attempts, locked_until 
                    FROM admin_users 
                    WHERE username = ? AND is_active = 1
                ");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Check if account is locked
                    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                        $error_message = 'Account is temporarily locked. Please try again later.';

                        // Log failed attempt
                        $stmt = $conn->prepare("
                            INSERT INTO security_log (event_type, username, ip_address, user_agent, details, severity) 
                            VALUES ('login_failed', ?, ?, ?, ?, 'HIGH')
                        ");
                        $stmt->execute([
                            $username,
                            $ip_address,
                            $user_agent,
                            json_encode(['reason' => 'account_locked', 'attempts' => $user['failed_login_attempts']])
                        ]);
                    } else {
                        // Verify password
                        if (password_verify($password, $user['password_hash'])) {
                            // Success! Reset failed attempts and create session
                            $session_token = bin2hex(random_bytes(64));
                            $expires_at = date('Y-m-d H:i:s', time() + (1 * 3600)); // 1 hour

                            // Create admin session
                            $stmt = $conn->prepare("
                                INSERT INTO admin_sessions (user_id, session_token, ip_address, user_agent, expires_at) 
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$user['id'], $session_token, $ip_address, $user_agent, $expires_at]);

                            // Update user login info
                            $stmt = $conn->prepare("
                                UPDATE admin_users 
                                SET last_login = NOW(), last_login_ip = ?, failed_login_attempts = 0, locked_until = NULL 
                                WHERE id = ?
                            ");
                            $stmt->execute([$ip_address, $user['id']]);

                            // Set session variables
                            session_regenerate_id(true);
                            $_SESSION['admin_logged_in'] = true;
                            $_SESSION['admin_user_id'] = $user['id'];
                            $_SESSION['admin_username'] = $user['username'];
                            $_SESSION['admin_session_token'] = $session_token;

                            // Note: the persistent IP throttle counter (rate_limits table)
                            // is intentionally not cleared here; it naturally expires
                            // after $rateLimitWindowMinutes.

                            // Log successful login
                            $stmt = $conn->prepare("
                                INSERT INTO security_log (event_type, user_id, username, ip_address, user_agent, details, severity) 
                                VALUES ('login_success', ?, ?, ?, ?, ?, 'MEDIUM')
                            ");
                            $stmt->execute([
                                $user['id'],
                                $username,
                                $ip_address,
                                $user_agent,
                                json_encode(['session_token' => substr($session_token, 0, 16) . '...'])
                            ]);

                            header('Location: ../mailbox');
                            exit;
                        } else {
                            // Failed password
                            $failed_attempts = $user['failed_login_attempts'] + 1;
                            $locked_until = null;

                            // Lock account after 5 failed attempts
                            if ($failed_attempts >= 5) {
                                $locked_until = date('Y-m-d H:i:s', time() + (30 * 60)); // 30 minutes
                                $error_message = 'Too many failed attempts. Account locked for 30 minutes.';
                            } else {
                                $error_message = 'Invalid username or password.';
                            }

                            // Update failed attempts
                            $stmt = $conn->prepare("
                                UPDATE admin_users 
                                SET failed_login_attempts = ?, locked_until = ? 
                                WHERE id = ?
                            ");
                            $stmt->execute([$failed_attempts, $locked_until, $user['id']]);

                            // Log failed attempt
                            $stmt = $conn->prepare("
                                INSERT INTO security_log (event_type, user_id, username, ip_address, user_agent, details, severity) 
                                VALUES ('login_failed', ?, ?, ?, ?, ?, 'MEDIUM')
                            ");
                            $stmt->execute([
                                $user['id'],
                                $username,
                                $ip_address,
                                $user_agent,
                                json_encode(['reason' => 'invalid_password', 'attempts' => $failed_attempts])
                            ]);

                            recordLoginAttempt($conn, $ipAddress, $loginRateLimitEndpoint, $user_agent);
                        }
                    }
                } else {
                    $error_message = 'Invalid username or password.';

                    // Log failed attempt (unknown user)
                    $stmt = $conn->prepare("
                        INSERT INTO security_log (event_type, username, ip_address, user_agent, details, severity) 
                        VALUES ('login_failed', ?, ?, ?, ?, 'MEDIUM')
                    ");
                    $stmt->execute([
                        $username,
                        $ip_address,
                        $user_agent,
                        json_encode(['reason' => 'unknown_user'])
                    ]);

                    recordLoginAttempt($conn, $ipAddress, $loginRateLimitEndpoint, $user_agent);
                }
            } catch (Exception $e) {
                $error_message = 'A system error occurred. Please try again later.';
                error_log("Admin login error: " . $e->getMessage());
            }
        }
    } else {
        $error_message = 'Please enter both username and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - Adaptive Email Privacy Framework</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-header h1 {
            color: #333;
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
        }

        .login-header p {
            color: #666;
            margin: 0;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .login-btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .login-btn:hover {
            transform: translateY(-2px);
        }

        .error-message {
            background: #fee;
            color: #c33;
            padding: 0.75rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #c33;
        }

        .security-notice {
            background: #f0f8ff;
            color: #2c5aa0;
            padding: 0.75rem;
            border-radius: 5px;
            margin-top: 1rem;
            font-size: 0.9rem;
            border-left: 4px solid #2c5aa0;
        }
    </style>
    <link rel="stylesheet" href="../assets/css/login.css?v=20260729-1">
</head>

<body>
    <a class="login-brand" href="../" aria-label="Adaptive Email Privacy Framework home">
        <img class="login-brand-logo" src="../assets/images/brand-logo.webp" alt="" width="30" height="30" aria-hidden="true">
        <span>Adaptive Email Privacy Framework</span>
    </a>
    <div class="login-container">
        <div class="login-header">
            <h1>🔐 Admin Login</h1>
            <p><?php echo SITE_NAME; ?> Administration</p>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text"
                    id="username"
                    name="username"
                    required
                    autocomplete="username"
                    value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password">
            </div>

            <button type="submit" class="login-btn">
                🚀 Login to Admin Panel
            </button>
        </form>

        <div class="security-notice">
            <strong>🛡️ Security Notice:</strong><br>
            This is a secure admin area. All login attempts are logged and monitored.
            After 5 failed attempts, your account will be temporarily locked.
        </div>
    </div>
</body>

</html>