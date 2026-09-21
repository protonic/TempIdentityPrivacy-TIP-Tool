<?php

/**
 * EmailManager class
 * 
 * Responsible for managing temporary email addresses and fetching emails.
 * Handles:
 * - Creating disposable email addresses
 * - Retrieving emails for a given temp email address
 * - Deleting disposable emails
 * - Statistics updates
 * 
 * Requires:
 * - Database connection via PDO
 * - Proper IMAP configuration in the `domains` table
 */

require_once __DIR__ . '/config_path.php';
require_once tipConfigDir() . '/database.php';

class EmailManager
{
    protected $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
    }

    /**
     * Generates a new temporary email address
     * 
     * @param int|null $domainId Optionally specify domain ID. If null, pick a random active domain.
     * @param int $expiryHours How long (in hours) the email should live (default 1 hour)
     * @return array ['success' => bool, 'email_address' => string|null, 'expires_at' => string|null, 'error' => string|null]
     */
    public function generateTempEmail(?int $domainId = null, int $expiryHours = 1): array
    {
        try {
            // Select a domain if not specified
            if ($domainId === null) {
                $stmt = $this->db->prepare("SELECT id, domain_name FROM domains WHERE is_active = 1 ORDER BY RAND() LIMIT 1");
                $stmt->execute();
                $domain = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$domain) {
                    return ['success' => false, 'error' => 'No active domains found'];
                }
                $domainId = (int)$domain['id'];
                $domainName = $domain['domain_name'];
            } else {
                $stmt = $this->db->prepare("SELECT domain_name FROM domains WHERE id = ? AND is_active = 1 LIMIT 1");
                $stmt->execute([$domainId]);
                $domain = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$domain) {
                    return ['success' => false, 'error' => 'Invalid or inactive domain ID'];
                }
                $domainName = $domain['domain_name'];
            }

            // Generate a random identifier for email (e.g. 7-10 characters alphanumeric)
            $randomPart = $this->generateRandomString(8);
            $emailAddress = strtolower($randomPart . '@' . $domainName);

            // Check uniqueness (very unlikely to collide but good to check)
            $stmt = $this->db->prepare("SELECT id FROM temp_emails WHERE email_address = ? LIMIT 1");
            $stmt->execute([$emailAddress]);
            if ($stmt->fetch()) {
                // Collision happened, retry once more recursively (careful on infinite loops)
                return $this->generateTempEmail($domainId, $expiryHours);
            }

            // Insert email into database with expiration date
            $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiryHours hours"));
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $sessionId = session_id();

            if (empty($sessionId)) {
                return ['success' => false, 'error' => 'Session initialization failed'];
            }

            $stmt = $this->db->prepare("
                INSERT INTO temp_emails (email_address, domain_id, expires_at, ip_address, session_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$emailAddress, $domainId, $expiresAt, $ipAddress, $sessionId]);

            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => 'Failed to insert temporary email'];
            }

            // Get the inserted temp email ID
            $tempEmailId = $this->db->lastInsertId();

            // Update historical email generation stats
            try {
                $this->updateEmailGenerationStats();
            } catch (Exception $e) {
                // Don't fail email generation if stats update fails
                error_log("Email generation stats update failed: " . $e->getMessage());
            }

            // Return success response
            return [
                'success' => true,
                'email_address' => $emailAddress,
                'expires_at' => $expiresAt,
                'error' => null
            ];
        } catch (PDOException $ex) {
            // Log error using your log function; here we just return error
            return ['success' => false, 'error' => 'Database error: ' . $ex->getMessage()];
        }
    }

    /**
     * Retrieve emails for a given temporary email address
     * 
     * @param string $emailAddress Full email address to query
     * @return array ['success' => bool, 'emails' => array|null, 'error' => string|null]
     */
    public function getEmailsForAddress(string $emailAddress): array
    {
        try {
            $sessionId = session_id();
            if (empty($sessionId)) {
                return ['success' => false, 'emails' => null, 'error' => 'Session not found'];
            }

            // Validate email format
            if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'emails' => null, 'error' => 'Invalid email address'];
            }

            // Find temp email by address and check active and not expired
            $stmt = $this->db->prepare("
                SELECT id, expires_at, is_active 
                FROM temp_emails
                WHERE email_address = ?
                    AND session_id = ?
                    AND is_active = 1
                    AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$emailAddress, $sessionId]);
            $tempEmail = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tempEmail) {
                return ['success' => false, 'emails' => null, 'error' => 'Email address not found, expired, or not owned by this session'];
            }

            $tempEmailId = $tempEmail['id'];

            // Fetch associated emails, newest first
            $stmt = $this->db->prepare("
                SELECT id, sender_email, sender_name, subject, body_text, body_html, received_at, has_attachments, is_read
                FROM email_messages 
                WHERE temp_email_id = ?
                ORDER BY received_at DESC
                LIMIT 100
            ");
            $stmt->execute([$tempEmailId]);
            $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Optionally sanitize or process emails here

            return ['success' => true, 'emails' => $emails, 'error' => null];
        } catch (PDOException $ex) {
            return ['success' => false, 'emails' => null, 'error' => 'Database error: ' . $ex->getMessage()];
        }
    }

    /**
     * Delete a temporary email address and associated emails
     * 
     * @param string $emailAddress Email address to delete
     * @return bool True on success, false on failure
     */
    public function deleteTempEmail(string $emailAddress): bool
    {
        try {
            $sessionId = session_id();
            if (empty($sessionId)) {
                return false;
            }

            // Primary match: same browser session that created the mailbox.
            $stmt = $this->db->prepare("
                SELECT id
                FROM temp_emails
                WHERE email_address = ?
                    AND session_id = ?
                LIMIT 1
            ");
            $stmt->execute([$emailAddress, $sessionId]);
            $tempEmail = $stmt->fetch(PDO::FETCH_ASSOC);

            // Note: this is a public, non-admin-gated endpoint, so deletion is
            // intentionally restricted to the mailbox owned by the calling
            // session. Admin deletion of arbitrary mailboxes should go through
            // the admin panel instead, not this method.
            if (!$tempEmail) {
                return false;
            }

            $tempEmailId = (int) $tempEmail['id'];

            $this->db->beginTransaction();

            // Delete related email messages first (due to FK constraints)
            $stmt = $this->db->prepare("DELETE FROM email_messages WHERE temp_email_id = ?");
            $stmt->execute([$tempEmailId]);

            // Then delete temp email
            $stmt = $this->db->prepare("DELETE FROM temp_emails WHERE id = ?");
            $stmt->execute([$tempEmailId]);

            $this->db->commit();

            // Keep this non-fatal so stats table issues do not report delete failure.
            try {
                $this->updateActiveEmailCount();
            } catch (Exception $ex) {
                // Ignore stats sync failures for delete success path.
            }

            return true;
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    /**
     * Delete selected inbox messages from SQL mailbox for an owned temp address.
     *
     * @param string $emailAddress
     * @param array<int,mixed> $messageIds
     * @return array{success:bool,deleted:int,error:?string}
     */
    public function deleteMessagesForAddress(string $emailAddress, array $messageIds): array
    {
        try {
            $sessionId = session_id();
            if (empty($sessionId)) {
                return ['success' => false, 'deleted' => 0, 'error' => 'Session not found'];
            }

            if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'deleted' => 0, 'error' => 'Invalid email address'];
            }

            $normalizedIds = [];
            foreach ($messageIds as $id) {
                $intId = (int) $id;
                if ($intId > 0) {
                    $normalizedIds[$intId] = true;
                }
            }
            $ids = array_keys($normalizedIds);

            if (empty($ids)) {
                return ['success' => false, 'deleted' => 0, 'error' => 'No messages selected'];
            }

            $ownerStmt = $this->db->prepare(" 
                SELECT id
                FROM temp_emails
                WHERE email_address = ?
                    AND session_id = ?
                    AND is_active = 1
                    AND expires_at > NOW()
                LIMIT 1
            ");
            $ownerStmt->execute([$emailAddress, $sessionId]);
            $tempEmail = $ownerStmt->fetch(PDO::FETCH_ASSOC);

            if (!$tempEmail) {
                return ['success' => false, 'deleted' => 0, 'error' => 'Mailbox not found for this session'];
            }

            $tempEmailId = (int) $tempEmail['id'];
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $this->db->beginTransaction();

            $params = array_merge([$tempEmailId], $ids);
            $deleteStmt = $this->db->prepare("DELETE FROM email_messages WHERE temp_email_id = ? AND id IN ($placeholders)");
            $deleteStmt->execute($params);
            $deleted = (int) $deleteStmt->rowCount();

            $this->db->commit();

            return ['success' => true, 'deleted' => $deleted, 'error' => null];
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'deleted' => 0, 'error' => 'Database error: ' . $ex->getMessage()];
        }
    }

    /**
     * Archive selected inbox messages and remove them from the active SQL inbox.
     *
     * @param string $emailAddress
     * @param array<int,mixed> $messageIds
     * @return array{success:bool,archived:int,error:?string}
     */
    public function archiveMessagesForAddress(string $emailAddress, array $messageIds): array
    {
        try {
            $sessionId = session_id();
            if (empty($sessionId)) {
                return ['success' => false, 'archived' => 0, 'error' => 'Session not found'];
            }

            $adminUserId = (int) ($_SESSION['admin_user_id'] ?? 0);
            if ($adminUserId <= 0) {
                return ['success' => false, 'archived' => 0, 'error' => 'Admin authentication required'];
            }

            if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'archived' => 0, 'error' => 'Invalid email address'];
            }

            $normalizedIds = [];
            foreach ($messageIds as $id) {
                $intId = (int) $id;
                if ($intId > 0) {
                    $normalizedIds[$intId] = true;
                }
            }
            $ids = array_keys($normalizedIds);

            if (empty($ids)) {
                return ['success' => false, 'archived' => 0, 'error' => 'No messages selected'];
            }

            $ownerStmt = $this->db->prepare(" 
                SELECT id
                FROM temp_emails
                WHERE email_address = ?
                    AND session_id = ?
                    AND is_active = 1
                    AND expires_at > NOW()
                LIMIT 1
            ");
            $ownerStmt->execute([$emailAddress, $sessionId]);
            $tempEmail = $ownerStmt->fetch(PDO::FETCH_ASSOC);

            if (!$tempEmail) {
                return ['success' => false, 'archived' => 0, 'error' => 'Mailbox not found for this session'];
            }

            $tempEmailId = (int) $tempEmail['id'];
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $this->ensureArchivedTableExists();

            $selectParams = array_merge([$tempEmailId], $ids);
            $selectStmt = $this->db->prepare(" 
                SELECT id, sender_email, sender_name, subject, body_text, body_html, received_at, message_id, has_attachments, source_folder, is_read
                FROM email_messages
                WHERE temp_email_id = ? AND id IN ($placeholders)
            ");
            $selectStmt->execute($selectParams);
            $rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                return ['success' => false, 'archived' => 0, 'error' => 'No matching messages found'];
            }

            $this->db->beginTransaction();

            $insertStmt = $this->db->prepare(" 
                INSERT IGNORE INTO archived_messages (
                    archived_by_user_id,
                    email_address,
                    original_temp_email_id,
                    source_message_id,
                    sender_email,
                    sender_name,
                    subject,
                    body_text,
                    body_html,
                    received_at,
                    message_id,
                    has_attachments,
                    source_folder,
                    is_read,
                    archived_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $archived = 0;
            foreach ($rows as $row) {
                $insertStmt->execute([
                    $adminUserId,
                    $emailAddress,
                    $tempEmailId,
                    (int) $row['id'],
                    $row['sender_email'] ?? '',
                    $row['sender_name'] ?? '',
                    $row['subject'] ?? '',
                    $row['body_text'] ?? '',
                    $row['body_html'] ?? '',
                    $row['received_at'] ?? date('Y-m-d H:i:s'),
                    $row['message_id'] ?? null,
                    (int) ($row['has_attachments'] ?? 0),
                    $row['source_folder'] ?? 'INBOX',
                    (int) ($row['is_read'] ?? 0),
                ]);
                if ((int) $insertStmt->rowCount() > 0) {
                    $archived++;
                }
            }

            $deleteParams = array_merge([$tempEmailId], $ids);
            $deleteStmt = $this->db->prepare("DELETE FROM email_messages WHERE temp_email_id = ? AND id IN ($placeholders)");
            $deleteStmt->execute($deleteParams);

            $this->db->commit();

            return ['success' => true, 'archived' => $archived, 'error' => null];
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'archived' => 0, 'error' => 'Database error: ' . $ex->getMessage()];
        }
    }

    /**
     * Return archived messages for a specific admin user.
     *
     * @param int $adminUserId
     * @return array{success:bool,emails:?array,error:?string}
     */
    public function getArchivedEmailsForUser(int $adminUserId): array
    {
        try {
            if ($adminUserId <= 0) {
                return ['success' => false, 'emails' => null, 'error' => 'Admin authentication required'];
            }

            $this->ensureArchivedTableExists();

            $stmt = $this->db->prepare(" 
                SELECT id, email_address, sender_email, sender_name, subject, body_text, body_html, received_at, message_id, has_attachments, source_folder, is_read, archived_at
                FROM archived_messages
                WHERE archived_by_user_id = ?
                ORDER BY archived_at DESC, id DESC
                LIMIT 1000
            ");
            $stmt->execute([$adminUserId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return ['success' => true, 'emails' => $rows, 'error' => null];
        } catch (PDOException $ex) {
            return ['success' => false, 'emails' => null, 'error' => 'Database error: ' . $ex->getMessage()];
        }
    }

    /**
     * Create archive table on demand for backward-compatible deployments.
     */
    protected function ensureArchivedTableExists(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS `archived_messages` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `archived_by_user_id` int(11) NOT NULL,
                `email_address` varchar(255) NOT NULL,
                `original_temp_email_id` int(11) DEFAULT NULL,
                `source_message_id` int(11) NOT NULL,
                `sender_email` varchar(255) NOT NULL,
                `sender_name` varchar(255) DEFAULT NULL,
                `subject` text DEFAULT NULL,
                `body_text` longtext DEFAULT NULL,
                `body_html` longtext DEFAULT NULL,
                `received_at` datetime NOT NULL,
                `message_id` varchar(255) DEFAULT NULL,
                `has_attachments` tinyint(1) DEFAULT 0,
                `source_folder` varchar(50) DEFAULT 'INBOX',
                `is_read` tinyint(1) DEFAULT 0,
                `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_source_message` (`archived_by_user_id`,`source_message_id`),
                KEY `idx_archived_user` (`archived_by_user_id`),
                KEY `idx_archived_email` (`email_address`),
                KEY `idx_archived_at` (`archived_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $this->db->exec($sql);
    }

    /**
     * Update the active email addresses count in system_stats table
     */
    protected function updateActiveEmailCount(): void
    {
        $stmt = $this->db->prepare("
            UPDATE system_stats SET stat_value = (
                SELECT COUNT(*) FROM temp_emails WHERE is_active = 1 AND expires_at > NOW()
            ) WHERE stat_name = 'active_email_addresses'
        ");
        $stmt->execute();
    }

    /**
     * Update daily email generation statistics for historical tracking
     */
    protected function updateEmailGenerationStats(): void
    {
        try {
            // Create table if it doesn't exist
            $createTableSql = "
                CREATE TABLE IF NOT EXISTS `email_generation_stats` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `date` date NOT NULL,
                    `emails_generated` int(11) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_date` (`date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $this->db->exec($createTableSql);

            // Increment today's count
            $stmt = $this->db->prepare("
                INSERT INTO email_generation_stats (date, emails_generated)
                VALUES (CURDATE(), 1)
                ON DUPLICATE KEY UPDATE emails_generated = emails_generated + 1
            ");
            $stmt->execute();
        } catch (PDOException $e) {
            // Log error but don't fail email generation
            error_log("Failed to update email generation stats: " . $e->getMessage());
        }
    }

    /**
     * Generate a random alphanumeric string
     * 
     * @param int $length Length of the string
     * @return string
     */
    protected function generateRandomString(int $length = 8): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}
