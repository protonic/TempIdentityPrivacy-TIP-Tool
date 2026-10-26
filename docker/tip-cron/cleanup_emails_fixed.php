<?php

/**
 * Email Cleanup Cron Job - Command Line Only
 * Run this via cron every hour:
 * 0 * * * * /usr/bin/php /path/to/tip-cron/cleanup_emails_fixed.php
 */

try {
    $projectRoot = dirname(__DIR__) . '/html';
    $configRoot = dirname(__DIR__) . '/tip-config';

    chdir($projectRoot);

    require_once $configRoot . '/config.php';
    require_once $configRoot . '/database.php';
    require_once $projectRoot . '/includes/functions.php';

    $database = new Database();
    $db = $database->connect();

    if (!$db) {
        throw new Exception("Database connection failed");
    }

    // Delete expired emails
    $stmt = $db->prepare("
        DELETE FROM temp_emails 
        WHERE expires_at < NOW() OR is_active = 0
    ");
    $stmt->execute();
    $expiredCount = $stmt->rowCount();

    // Delete old email messages (older than 48 hours)
    $stmt = $db->prepare("
        DELETE em FROM email_messages em
        LEFT JOIN temp_emails te ON em.temp_email_id = te.id
        WHERE te.id IS NULL OR em.received_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ");
    $stmt->execute();
    $messagesDeleted = $stmt->rowCount();

    // Update active email count
    $stmt = $db->prepare("
        SELECT COUNT(*) as active_count FROM temp_emails WHERE is_active = 1 AND expires_at > NOW()
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $activeCount = $result ? $result['active_count'] : 0;

    // Update or insert system stats
    $stmt = $db->prepare("
        INSERT INTO system_stats (stat_name, stat_value) 
        VALUES ('active_email_addresses', ?) 
        ON DUPLICATE KEY UPDATE stat_value = ?
    ");
    $stmt->execute([$activeCount, $activeCount]);

    $message = "Cleanup completed: {$expiredCount} expired emails deleted, {$messagesDeleted} old messages deleted, {$activeCount} active emails remaining";
    logError($message);

    // If accessed via web, return JSON response
    if (isset($_SERVER['HTTP_HOST'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'expired_deleted' => $expiredCount,
            'messages_deleted' => $messagesDeleted,
            'active_count' => $activeCount,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
} catch (Exception $e) {
    $error = "Cleanup error: " . $e->getMessage();
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
