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
$emailAddress = $input['email_address'] ?? '';
$messageIds = $input['message_ids'] ?? [];

if (!isValidEmail($emailAddress)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
    exit;
}

if (!is_array($messageIds) || empty($messageIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No messages selected']);
    exit;
}

try {
    $emailManager = new EmailManager();
    $result = $emailManager->deleteMessagesForAddress($emailAddress, $messageIds);

    if (!$result['success']) {
        $message = $result['error'] ?? 'Unable to delete selected messages';
        $isSessionError = stripos($message, 'session') !== false || stripos($message, 'mailbox not found') !== false;
        http_response_code($isSessionError ? 403 : 400);
        echo json_encode(['success' => false, 'error' => $message, 'deleted' => 0]);
        exit;
    }

    echo json_encode(['success' => true, 'deleted' => (int) $result['deleted']]);
} catch (Exception $e) {
    logError('delete messages handler error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error', 'deleted' => 0]);
}
