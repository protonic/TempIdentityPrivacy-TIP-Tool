<?php

/**
 * Email Fetch Cron Job - Command Line Only
 * Run this via cron every 2-5 minutes:
 * Every 2 minutes: /usr/bin/php /path/to/tip-cron/fetch_emails_fixed.php
 */

try {
    $projectRoot = dirname(__DIR__) . '/html';
    $configRoot = dirname(__DIR__) . '/tip-config';

    chdir($projectRoot);

    require_once $configRoot . '/config.php';
    require_once $configRoot . '/database.php';
    require_once $projectRoot . '/includes/functions.php';
    require_once $projectRoot . '/includes/fast_imap_fetcher.php';

    $database = new Database();
    $db = $database->connect();

    if (!$db) {
        throw new Exception("Database connection failed");
    }

    // Get all active domains
    $stmt = $db->prepare("SELECT * FROM domains WHERE is_active = 1");
    $stmt->execute();
    $domains = $stmt->fetchAll();

    $totalProcessed = 0;
    $totalStored = 0;

    foreach ($domains as $domain) {
        try {
            // Decrypt the password if it's encrypted
            if (!empty($domain['imap_password_encrypted'])) {
                try {
                    $decrypted_password = openssl_decrypt(
                        base64_decode($domain['imap_password_encrypted']),
                        'AES-256-CBC',
                        CRON_SECRET_KEY,
                        0,
                        substr(hash('sha256', CRON_SECRET_KEY), 0, 16)
                    );
                    $domain['imap_password'] = $decrypted_password;
                } catch (Exception $e) {
                    error_log("Password decryption failed for domain {$domain['domain_name']}: " . $e->getMessage());
                    continue;
                }
            } elseif (empty($domain['imap_password'])) {
                error_log("No password found for domain {$domain['domain_name']}");
                continue;
            }

            // Check if there are any active temporary emails for this domain
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM temp_emails 
                WHERE domain_id = ? AND is_active = 1 AND expires_at > NOW()
            ");
            $stmt->execute([$domain['id']]);
            $activeEmailCount = $stmt->fetch()['count'];

            // Skip IMAP connection if no active temp emails exist
            if ($activeEmailCount == 0) {
                logError("Domain {$domain['domain_name']}: No active temp emails, skipping IMAP check");
                continue;
            }

            // Use optimized Fast IMAP (15-30 second delivery with 15s cron)
            $protocol = 'fast-imap';
            $start_time = microtime(true);

            $fetcher = new FastImapEmailFetcher($domain, $db);
            logError("Using Fast IMAP for domain {$domain['domain_name']} (optimized mode) - {$activeEmailCount} active email(s)");

            $fetcher->connect();
            $emails = $fetcher->fetchNewEmails();
            $processed = 0;
            $stored = 0;

            foreach ($emails as $email) {
                $processed++;

                // Extract recipient email from the 'to' field
                $recipient = $email['to'];

                // Check if this email address exists in our temp_emails table
                $stmt = $db->prepare("
                    SELECT id FROM temp_emails 
                    WHERE email_address = ? AND is_active = 1 AND expires_at > NOW()
                ");
                $stmt->execute([$recipient]);
                $tempEmail = $stmt->fetch();

                if ($tempEmail) {
                    // Check if this message already exists
                    $messageId = $email['message_id'] ?? md5($email['subject'] . $email['date'] . $email['from']);
                    $stmt = $db->prepare("
                        SELECT id FROM email_messages 
                        WHERE temp_email_id = ? AND message_id = ?
                    ");
                    $stmt->execute([$tempEmail['id'], $messageId]);

                    if (!$stmt->fetch()) {
                        // Insert new message
                        $stmt = $db->prepare("
                            INSERT INTO email_messages 
                            (temp_email_id, sender_email, sender_name, subject, body_text, body_html, received_at, message_id, has_attachments) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $result = $stmt->execute([
                            $tempEmail['id'],
                            $email['from'],
                            $email['from_name'] ?? '',
                            $email['subject'],
                            $email['body_text'] ?? '',
                            $email['body_html'] ?? '',
                            $email['date'],
                            $messageId,
                            $email['has_attachments'] ?? 0
                        ]);

                        if ($result) {
                            $stored++;
                        }
                    }
                }
            }

            $fetcher->disconnect();

            $totalProcessed += $processed;
            $totalStored += $stored;

            $fetch_time = round((microtime(true) - $start_time) * 1000, 2);
            logError("Domain {$domain['domain_name']} [{$protocol}]: processed $processed emails, stored $stored new emails in {$fetch_time}ms");
        } catch (Exception $e) {
            logError("Error fetching emails for domain {$domain['domain_name']}: " . $e->getMessage());
            continue;
        }
    }

    $message = "Email fetch completed: processed $totalProcessed emails, stored $totalStored new emails";
    logError($message);

    // If accessed via web, return JSON response
    if (isset($_SERVER['HTTP_HOST'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'processed' => $totalProcessed,
            'stored' => $totalStored,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
} catch (Exception $e) {
    $error = "Email fetch error: " . $e->getMessage();
    logError($error);

    // If accessed via web, return JSON error response
    if (isset($_SERVER['HTTP_HOST'])) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $error,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}
