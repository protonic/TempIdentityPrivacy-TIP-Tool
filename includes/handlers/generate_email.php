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

$domainId = null;
$input = json_decode(file_get_contents('php://input'), true);
if (isset($input['domain_id']) && is_numeric($input['domain_id'])) {
    $domainId = (int)$input['domain_id'];
}

if (checkHighVolumeAbuse($clientIP)) {
    logRateLimitViolation($clientIP);
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'High volume usage detected']);
    exit;
}

if (checkDailyRateLimit($clientIP)) {
    logRateLimitViolation($clientIP);
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Daily rate limit exceeded']);
    exit;
}

if (checkHourlyRateLimit($clientIP)) {
    logRateLimitViolation($clientIP);
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Hourly rate limit exceeded']);
    exit;
}

try {
    $emailManager = new EmailManager();
    echo json_encode($emailManager->generateTempEmail($domainId));
} catch (Exception $e) {
    logError('generate handler error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
