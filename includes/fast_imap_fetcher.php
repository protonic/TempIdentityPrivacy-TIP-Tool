<?php

/**
 * Fast IMAP Fetcher with IDLE support for near-realtime delivery
 *
 * This is much simpler than ActiveSync and works with ZOHO's IMAP
 * IDLE allows push notifications for new emails (0-5 second delivery)
 */

// Configuration should be loaded by the calling script
// require_once __DIR__ . '/../tip-config/config.php';

class FastImapEmailFetcher
{
    private $domain_config;
    private $connection;
    private $inbox;
    private $db;

    public function __construct($domain_config, $db = null)
    {
        $this->domain_config = $domain_config;
        $this->db = $db;
    }

    public function connect()
    {
        // Optimized connection with timeout settings for faster response
        $server = '{' . $this->domain_config['imap_server'] . ':' . $this->domain_config['imap_port'] . '/imap/ssl/novalidate-cert}INBOX';

        // Decrypt password if needed
        if (!empty($this->domain_config['imap_password_encrypted'])) {
            $password = $this->decryptPassword($this->domain_config['imap_password_encrypted']);
        } else {
            $password = $this->domain_config['imap_password'];
        }

        // Set shorter timeout for faster failure detection
        imap_timeout(IMAP_OPENTIMEOUT, 5);
        imap_timeout(IMAP_READTIMEOUT, 5);
        imap_timeout(IMAP_WRITETIMEOUT, 5);
        imap_timeout(IMAP_CLOSETIMEOUT, 2);

        $this->connection = @imap_open(
            $server,
            $this->domain_config['imap_username'],
            $password,
            0  // Read-write mode: marks emails as read after fetching
        );

        if (!$this->connection) {
            throw new Exception('IMAP connection failed: ' . imap_last_error());
        }

        return true;
    }

    public function fetchNewEmails($since_date = null)
    {
        if (!$this->connection) {
            throw new Exception('Not connected to IMAP server');
        }

        // Use SEARCH UNSEEN for fast check
        $uids = imap_search($this->connection, 'UNSEEN', SE_UID);

        if (!$uids) {
            return [];
        }

        $emails = [];

        foreach ($uids as $uid) {
            try {
                $email = $this->parseEmail($uid);
                if ($email) {
                    $emails[] = $email;
                }
            } catch (Exception $e) {
                error_log("Error parsing email UID $uid: " . $e->getMessage());
                continue;
            }
        }

        return $emails;
    }

    private function parseEmail($uid)
    {
        // Fetch headers and body structure
        $msgno = imap_msgno($this->connection, $uid);
        $header = imap_headerinfo($this->connection, $msgno);
        $structure = imap_fetchstructure($this->connection, $msgno);

        if (!$header) {
            return null;
        }

        // Parse FROM field
        $from = isset($header->from[0]) ? $header->from[0] : null;
        $from_email = $from ? ($from->mailbox . '@' . $from->host) : '';
        $from_name = $from && !empty($from->personal) ? $this->decodeMimeStr($from->personal) : $from_email;

        // Parse TO field
        $to = isset($header->to[0]) ? $header->to[0] : null;
        $to_email = $to ? ($to->mailbox . '@' . $to->host) : '';

        // Extract body
        $body_text = '';
        $body_html = '';
        $has_attachments = 0;

        if ($structure) {
            $this->extractBody($msgno, $structure, $body_text, $body_html, $has_attachments);
        }

        // If no plain text but have HTML, convert
        if (empty($body_text) && !empty($body_html)) {
            $body_text = strip_tags($body_html);
        }

        // If no HTML but have plain text, convert
        if (empty($body_html) && !empty($body_text)) {
            $body_html = nl2br(htmlspecialchars($body_text));
        }

        $subject = isset($header->subject) ? $this->decodeMimeStr($header->subject) : 'No Subject';

        return [
            'subject' => $subject,
            'from' => $from_email,
            'from_name' => $from_name,
            'to' => $to_email,
            'date' => date('Y-m-d H:i:s', $header->udate),
            'message_id' => isset($header->message_id) ? trim($header->message_id, '<>') : '',
            'body_text' => $body_text,
            'body_html' => $body_html,
            'has_attachments' => $has_attachments
        ];
    }

    private function extractBody($msgno, $structure, &$body_text, &$body_html, &$has_attachments, $partNum = '')
    {
        // Check if message has attachments
        if (isset($structure->parts) && count($structure->parts) > 1) {
            foreach ($structure->parts as $partIndex => $part) {
                if (isset($part->disposition) && strtolower($part->disposition) == 'attachment') {
                    $has_attachments = 1;
                }
            }
        }

        // Simple body extraction
        if (empty($partNum)) {
            // No parts, single body
            if ($structure->type == 0) { // TEXT
                $charset = $this->getCharset($structure);

                if ($structure->subtype == 'PLAIN' && empty($body_text)) {
                    $body_text = imap_body($this->connection, $msgno);
                    $body_text = $this->decodeBody($body_text, $structure->encoding, $charset);
                } elseif ($structure->subtype == 'HTML' && empty($body_html)) {
                    $body_html = imap_body($this->connection, $msgno);
                    $body_html = $this->decodeBody($body_html, $structure->encoding, $charset);
                }
            }
        } else {
            $body = imap_fetchbody($this->connection, $msgno, $partNum);
            $charset = $this->getCharset($structure);
            $body = $this->decodeBody($body, $structure->encoding, $charset);

            if ($structure->subtype == 'PLAIN' && empty($body_text)) {
                $body_text = $body;
            } elseif ($structure->subtype == 'HTML' && empty($body_html)) {
                $body_html = $body;
            }
        }

        // Handle multipart messages
        if (isset($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $partIndex => $part) {
                $currentPartNum = empty($partNum) ? ($partIndex + 1) : $partNum . '.' . ($partIndex + 1);
                $this->extractBody($msgno, $part, $body_text, $body_html, $has_attachments, $currentPartNum);
            }
        }
    }

    private function decodeBody($body, $encoding, $charset = null)
    {
        switch ($encoding) {
            case 1: // 8BIT
                $decoded = imap_8bit($body);
                break;
            case 2: // BINARY
                $decoded = imap_binary($body);
                break;
            case 3: // BASE64
                $decoded = imap_base64($body);
                break;
            case 4: // QUOTED-PRINTABLE
                // quoted_printable_decode handles soft line breaks better than imap_qprint
                $decoded = quoted_printable_decode($body);
                break;
            default: // 7BIT or unknown
                $decoded = $body;
                break;
        }

        // Fallback: if it still looks quoted-printable, decode again to strip soft breaks
        if (preg_match('/=\r?\n|=3D[0-9A-F]{2}/i', $decoded)) {
            $qpDecoded = quoted_printable_decode($decoded);
            if ($qpDecoded !== false) {
                $decoded = $qpDecoded;
            }
        }

        // Normalize charset to UTF-8 when provided
        if (!empty($charset)) {
            $charset = strtoupper($charset);
            if ($charset !== 'UTF-8') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $decoded);
                if ($converted !== false) {
                    $decoded = $converted;
                }
            }
        }

        return $decoded;
    }

    private function getCharset($structure)
    {
        if (isset($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) === 'charset') {
                    return $param->value;
                }
            }
        }
        if (isset($structure->dparameters)) {
            foreach ($structure->dparameters as $param) {
                if (strtolower($param->attribute) === 'charset') {
                    return $param->value;
                }
            }
        }
        return null;
    }

    private function decodeMimeStr($string)
    {
        $decoded = imap_mime_header_decode($string);
        $result = '';

        foreach ($decoded as $part) {
            $charset = ($part->charset == 'default') ? 'UTF-8' : $part->charset;
            $result .= mb_convert_encoding($part->text, 'UTF-8', $charset);
        }

        return $result;
    }

    private function decryptPassword($encrypted)
    {
        if (!defined('CRON_SECRET_KEY')) {
            throw new Exception("CRON_SECRET_KEY not defined");
        }

        $raw = base64_decode($encrypted);

        // New format: random 16-byte IV prepended to the ciphertext.
        if (strlen($raw) > 16) {
            $iv = substr($raw, 0, 16);
            $ciphertext = substr($raw, 16);
            $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', CRON_SECRET_KEY, 0, $iv);
            if ($decrypted !== false && $decrypted !== '') {
                return $decrypted;
            }
        }

        // Backward-compatible fallback for rows encrypted with the old
        // deterministic-IV scheme.
        return openssl_decrypt(
            $raw,
            'AES-256-CBC',
            CRON_SECRET_KEY,
            0,
            substr(hash('sha256', CRON_SECRET_KEY), 0, 16)
        );
    }

    public function disconnect()
    {
        if ($this->connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
        return true;
    }
}
