<?php
ob_start();
error_reporting(E_ERROR | E_PARSE);

require_once '../config_path.php';
require_once tipConfigDir() . '/config.php';
require_once tipConfigDir() . '/database.php';
require_once '../functions.php';

initSecureSession();
header('Content-Type: application/json');
ob_clean();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$csrfToken = null;
foreach (getallheaders() as $key => $value) {
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

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Administrator sign-in required']);
    exit;
}

try {
    $database = new Database();
    $db = $database->connect();
    $statement = $db->prepare("SELECT id, email_address FROM temp_emails WHERE email_address = 'sample-inbox-aepf@mamutti.local' AND is_active = 1 LIMIT 1");
    $statement->execute();
    $sampleInbox = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$sampleInbox) {
        throw new RuntimeException('The sample inbox has not been seeded yet');
    }

    $claim = $db->prepare('UPDATE temp_emails SET session_id = ?, expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?');
    $claim->execute([session_id(), $sampleInbox['id']]);

    echo json_encode([
        'success' => true,
        'email_address' => $sampleInbox['email_address'],
        'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days'))
    ]);
} catch (Exception $exception) {
    logError('sample inbox error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to open the sample inbox']);
}
