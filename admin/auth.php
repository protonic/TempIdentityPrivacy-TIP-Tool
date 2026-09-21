<?php
// Admin Authentication Check
// Include this at the top of all admin pages

session_start();

require_once __DIR__ . '/../includes/config_path.php';
require_once tipConfigDir() . '/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_class.php';

function requireAdminPostCsrf()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!$csrfToken || !validateCSRFToken($csrfToken)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

function checkAdminAuth()
{
    $requestIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $requestUserAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        header('Location: login');
        exit;
    }

    if (!isset($_SESSION['admin_session_token']) || !isset($_SESSION['admin_user_id'])) {
        destroyAdminSession();
        header('Location: login');
        exit;
    }

    try {
        $db = new Database();
        $conn = $db->connect();

        $stmt = $conn->prepare("
            SELECT s.*, u.username, u.is_active 
            FROM admin_sessions s 
            JOIN admin_users u ON s.user_id = u.id 
            WHERE s.session_token = ? 
                AND s.user_id = ? 
                AND s.is_active = 1 
                AND s.expires_at > NOW()
                AND u.is_active = 1
        ");
        $stmt->execute([$_SESSION['admin_session_token'], $_SESSION['admin_user_id']]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            destroyAdminSession();
            header('Location: login?expired=1');
            exit;
        }

        if (!empty($session['ip_address']) && $session['ip_address'] !== $requestIp) {
            destroyAdminSession();
            header('Location: login?ip_changed=1');
            exit;
        }

        if (!empty($session['user_agent']) && $session['user_agent'] !== $requestUserAgent) {
            destroyAdminSession();
            header('Location: login?device_changed=1');
            exit;
        }

        $stmt = $conn->prepare("
            UPDATE admin_sessions 
            SET last_activity = NOW() 
            WHERE session_token = ?
        ");
        $stmt->execute([$_SESSION['admin_session_token']]);

        return [
            'user_id' => $session['user_id'],
            'username' => $session['username'],
            'session_id' => $session['id']
        ];
    } catch (Exception $e) {
        error_log("Admin auth check error: " . $e->getMessage());
        destroyAdminSession();
        header('Location: login');
        exit;
    }
}

function destroyAdminSession()
{
    if (isset($_SESSION['admin_session_token'])) {
        try {
            $db = new Database();
            $conn = $db->connect();

            // Deactivate session in database
            $stmt = $conn->prepare("
                UPDATE admin_sessions 
                SET is_active = 0 
                WHERE session_token = ?
            ");
            $stmt->execute([$_SESSION['admin_session_token']]);

            // Log logout
            $stmt = $conn->prepare("
                INSERT INTO security_log (event_type, user_id, username, ip_address, details, severity) 
                VALUES ('session_expired', ?, ?, ?, ?, 'LOW')
            ");
            $stmt->execute([
                $_SESSION['admin_user_id'] ?? null,
                $_SESSION['admin_username'] ?? 'unknown',
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                json_encode(['action' => 'session_destroyed'])
            ]);
        } catch (Exception $e) {
            error_log("Session destroy error: " . $e->getMessage());
        }
    }

    // Clear session variables
    unset($_SESSION['admin_logged_in']);
    unset($_SESSION['admin_user_id']);
    unset($_SESSION['admin_username']);
    unset($_SESSION['admin_session_token']);
}

function logAdminAction($action, $details = [])
{
    if (!isset($_SESSION['admin_user_id'])) return;

    try {
        $db = new Database();
        $conn = $db->connect();

        $stmt = $conn->prepare("
            INSERT INTO security_log (event_type, user_id, username, ip_address, user_agent, details, severity) 
            VALUES ('admin_access', ?, ?, ?, ?, ?, 'MEDIUM')
        ");
        $stmt->execute([
            $_SESSION['admin_user_id'],
            $_SESSION['admin_username'],
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            json_encode(array_merge(['action' => $action], $details))
        ]);
    } catch (Exception $e) {
        error_log("Admin action log error: " . $e->getMessage());
    }
}

// Check authentication (this runs automatically when file is included)
$admin_user = checkAdminAuth();
requireAdminPostCsrf();
