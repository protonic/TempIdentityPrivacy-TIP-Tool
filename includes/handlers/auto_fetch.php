<?php
ob_start();
error_reporting(E_ERROR | E_PARSE);

require_once '../config_path.php';
require_once tipConfigDir() . '/config.php';
require_once tipConfigDir() . '/database.php';
require_once '../functions.php';
require_once '../fast_imap_fetcher.php';

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

$clientIP = getClientIP();
if (isIPBlocked($clientIP)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied - IP blocked']);
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

$input = json_decode(file_get_contents('php://input'), true);
$emailAddress = $input['email_address'] ?? null;

if (!$emailAddress || !isValidEmail($emailAddress)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
    exit;
}

// Guard against aggressive polling from multiple tabs or rapid refreshes.
$lastAutoFetchAt = $_SESSION['last_auto_fetch_at'] ?? 0;
if ((time() - (int) $lastAutoFetchAt) < 45) {
    echo json_encode(['success' => true, 'stored' => 0, 'throttled' => true]);
    exit;
}
$_SESSION['last_auto_fetch_at'] = time();

try {
    $database = new Database();
    $db = $database->connect();

    $ownershipStmt = $db->prepare("
        SELECT id
        FROM temp_emails
        WHERE email_address = ?
            AND session_id = ?
            AND is_active = 1
            AND expires_at > NOW()
        LIMIT 1
    ");
    $ownershipStmt->execute([$emailAddress, session_id()]);
    if (!$ownershipStmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Mailbox access denied for this session']);
        exit;
    }

    $addressDomain = strtolower(substr(strrchr($emailAddress, '@'), 1));
    $stmt = $db->prepare('SELECT * FROM domains WHERE is_active = 1 AND LOWER(domain_name) = ? LIMIT 1');
    $stmt->execute([$addressDomain]);
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($domains)) {
        echo json_encode(['success' => true, 'stored' => 0]);
        exit;
    }

    $stored = 0;

    foreach ($domains as $domain) {
        try {
            if (!empty($domain['imap_password_encrypted'])) {
                $secretKey = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '';
                if ($secretKey === '') {
                    continue;
                }
                $domain['imap_password'] = openssl_decrypt(
                    base64_decode($domain['imap_password_encrypted']),
                    'AES-256-CBC',
                    $secretKey,
                    0,
                    substr(hash('sha256', $secretKey), 0, 16)
                );
            }

            if (empty($domain['imap_password'])) {
                continue;
            }

            $fetcher = new FastImapEmailFetcher($domain, $db);
            $fetcher->connect();
            $emails = $fetcher->fetchNewEmails();

            foreach ($emails as $email) {
                $recipient = $email['to'];
                $stmt = $db->prepare('SELECT id FROM temp_emails WHERE email_address = ? AND is_active = 1 AND expires_at > NOW()');
                $stmt->execute([$recipient]);
                $tempEmail = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$tempEmail) {
                    continue;
                }

                $messageId = $email['message_id'] ?? md5(($email['subject'] ?? '') . ($email['date'] ?? '') . ($email['from'] ?? ''));

                $checkStmt = $db->prepare('SELECT id FROM email_messages WHERE temp_email_id = ? AND message_id = ?');
                $checkStmt->execute([$tempEmail['id'], $messageId]);
                if ($checkStmt->fetch()) {
                    continue;
                }

                $insertStmt = $db->prepare(
                    'INSERT INTO email_messages (temp_email_id, sender_email, sender_name, subject, body_text, body_html, received_at, message_id, has_attachments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $ok = $insertStmt->execute([
                    $tempEmail['id'],
                    $email['from'] ?? '',
                    $email['from_name'] ?? '',
                    $email['subject'] ?? '',
                    $email['body_text'] ?? '',
                    $email['body_html'] ?? '',
                    $email['date'] ?? date('Y-m-d H:i:s'),
                    $messageId,
                    $email['has_attachments'] ?? 0,
                ]);

                if ($ok) {
                    $stored++;
                }
            }

            $fetcher->disconnect();
        } catch (Exception $e) {
            logError('auto fetch domain error: ' . $e->getMessage());
        }
    }

    echo json_encode(['success' => true, 'stored' => $stored]);
} catch (Exception $e) {
    logError('auto fetch handler error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Auto fetch failed']);
}
