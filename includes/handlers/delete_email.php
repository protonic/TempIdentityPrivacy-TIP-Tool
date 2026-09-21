<?php
ob_start();
error_reporting(E_ERROR | E_PARSE);

require_once '../config_path.php';
require_once tipConfigDir() . '/config.php';
require_once '../functions.php';
require_once '../email_class.php';

initSecureSession();
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
ob_clean();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$headers = getallheaders();
$csrfToken = null;
foreach ($headers as $key => $value) {
    if (strtolower($key) === 'x-csrf-token') {
        $csrfToken = $value;
        break;
    }
}

if (!$csrfToken || !validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$clientIP = getClientIP();
if (isIPBlocked($clientIP)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied - IP blocked']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['email_address']) || !isValidEmail($input['email_address'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
    exit;
}

try {
    $emailManager = new EmailManager();
    $deleted = $emailManager->deleteTempEmail($input['email_address']);
    if ($deleted) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Mailbox not found for this session. Please refresh and try again.']);
    }
} catch (Exception $e) {
    logError('delete handler error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
