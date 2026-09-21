<?php
// Admin Logout
session_start();

require_once '../includes/config_path.php';
require_once tipConfigDir() . '/config.php';
require_once '../includes/database_class.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Frame-Options: DENY');

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    try {
        $db = new Database();
        $conn = $db->connect();

        // Log the logout action
        if (isset($_SESSION['admin_session_token'])) {
            $stmt = $conn->prepare("
                INSERT INTO security_log (event_type, user_id, username, ip_address, details, severity) 
                VALUES ('admin_access', ?, ?, ?, ?, 'LOW')
            ");
            $stmt->execute([
                $_SESSION['admin_user_id'] ?? null,
                $_SESSION['admin_username'] ?? 'unknown',
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                json_encode(['action' => 'logout', 'method' => 'manual'])
            ]);

            // Deactivate session in database
            $stmt = $conn->prepare("
                UPDATE admin_sessions 
                SET is_active = 0 
                WHERE session_token = ?
            ");
            $stmt->execute([$_SESSION['admin_session_token']]);
        }
    } catch (Exception $e) {
        error_log("Logout error: " . $e->getMessage());
    }
}

// Clear all session data
session_unset();
session_destroy();

// Return to the public home page, where the sign-in dialog is available.
header('Location: ../');
exit;

// Start new session for the message
session_start();
$_SESSION['logout_message'] = 'You have been successfully logged out.';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out - <?php echo SITE_NAME; ?></title>
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

        .logout-container {
            background: white;
            padding: 3rem 2rem;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            text-align: center;
            max-width: 400px;
            width: 100%;
        }

        .logout-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        .logout-container h1 {
            color: #333;
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }

        .logout-container p {
            color: #666;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .login-btn {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.75rem 2rem;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 500;
            transition: transform 0.2s;
        }

        .login-btn:hover {
            transform: translateY(-2px);
        }

        .security-info {
            background: #f0f8ff;
            color: #2c5aa0;
            padding: 1rem;
            border-radius: 5px;
            margin-top: 2rem;
            font-size: 0.9rem;
            border-left: 4px solid #2c5aa0;
        }
    </style>
    <script>
        // Auto-redirect after 5 seconds
        setTimeout(function() {
            window.location.href = 'login';
        }, 5000);
    </script>
</head>

<body>
    <div class="logout-container">
        <div class="logout-icon">Goodbye</div>
        <h1>Successfully Logged Out</h1>
        <p>
            Thank you for using the admin panel securely. Your session has been terminated
            and all administrative access has been revoked.
        </p>
        <a href="login" class="login-btn">Login Again</a>

        <div class="security-info">
            <strong>🛡️ Security Notice:</strong><br>
            Your logout has been logged for security purposes.
            You will be redirected to the login page automatically in 5 seconds.
        </div>
    </div>
</body>

</html>