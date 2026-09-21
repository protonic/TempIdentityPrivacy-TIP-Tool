<?php
// Secure Domain Management with Authentication and Password Encryption
require_once 'auth.php'; // This includes config.php and checks authentication
require_once '../includes/database_class.php';

$db = new Database();
$conn = $db->connect();
$message = '';
$cryptoKey = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : null;

// Handle Add Domain
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    try {
        if (empty($cryptoKey)) {
            throw new Exception('CRON_SECRET_KEY is not configured');
        }

        // Encrypt the IMAP password using a random IV per row (prepended to the
        // ciphertext) instead of a deterministic one, to avoid CBC IV reuse.
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted_password = base64_encode($iv . openssl_encrypt(
            $_POST['imap_password'],
            'AES-256-CBC',
            $cryptoKey,
            0,
            $iv
        ));

        $stmt = $conn->prepare("
            INSERT INTO domains (domain_name, imap_server, imap_port, imap_username, imap_password_encrypted, use_ssl, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['domain_name'],
            $_POST['imap_server'],
            $_POST['imap_port'],
            $_POST['imap_username'],
            $encrypted_password,
            isset($_POST['use_ssl']) ? 1 : 0,
            isset($_POST['is_active']) ? 1 : 0
        ]);

        $message = "<div class='success-message'>✅ Domain added successfully with encrypted password.</div>";

        // Log the action
        logAdminAction('domain_added', [
            'domain_name' => $_POST['domain_name'],
            'imap_server' => $_POST['imap_server']
        ]);
    } catch (Exception $e) {
        $message = "<div class='error-message'>❌ Error adding domain: " . htmlspecialchars($e->getMessage()) . "</div>";
        error_log("Domain add error: " . $e->getMessage());
    }
}

// Handle Edit Domain
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    try {
        // Only encrypt password if it was changed (not empty)
        if (!empty($_POST['imap_password'])) {
            if (empty($cryptoKey)) {
                throw new Exception('CRON_SECRET_KEY is not configured');
            }

            $iv = openssl_random_pseudo_bytes(16);
            $encrypted_password = base64_encode($iv . openssl_encrypt(
                $_POST['imap_password'],
                'AES-256-CBC',
                $cryptoKey,
                0,
                $iv
            ));

            $stmt = $conn->prepare("
                UPDATE domains 
                SET domain_name=?, imap_server=?, imap_port=?, imap_username=?, imap_password_encrypted=?, use_ssl=?, is_active=? 
                WHERE id=?
            ");
            $stmt->execute([
                $_POST['domain_name'],
                $_POST['imap_server'],
                $_POST['imap_port'],
                $_POST['imap_username'],
                $encrypted_password,
                isset($_POST['use_ssl']) ? 1 : 0,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['id']
            ]);
        } else {
            // Update without changing password
            $stmt = $conn->prepare("
                UPDATE domains 
                SET domain_name=?, imap_server=?, imap_port=?, imap_username=?, use_ssl=?, is_active=? 
                WHERE id=?
            ");
            $stmt->execute([
                $_POST['domain_name'],
                $_POST['imap_server'],
                $_POST['imap_port'],
                $_POST['imap_username'],
                isset($_POST['use_ssl']) ? 1 : 0,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['id']
            ]);
        }

        $message = "<div class='success-message'>✅ Domain updated successfully.</div>";

        // Log the action
        logAdminAction('domain_modified', [
            'domain_id' => $_POST['id'],
            'domain_name' => $_POST['domain_name'],
            'password_changed' => !empty($_POST['imap_password'])
        ]);
    } catch (Exception $e) {
        $message = "<div class='error-message'>❌ Error updating domain: " . htmlspecialchars($e->getMessage()) . "</div>";
        error_log("Domain update error: " . $e->getMessage());
    }
}

// Handle Delete Domain
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    try {
        $stmt = $conn->prepare("DELETE FROM domains WHERE id=?");
        $stmt->execute([$_POST['id']]);
        $message = "<div class='success-message'>✅ Domain deleted successfully.</div>";

        // Log the action
        logAdminAction('domain_deleted', [
            'domain_id' => $_POST['id']
        ]);
    } catch (Exception $e) {
        $message = "<div class='error-message'>❌ Error deleting domain: " . htmlspecialchars($e->getMessage()) . "</div>";
        error_log("Domain delete error: " . $e->getMessage());
    }
}

// Fetch domains
$stmt = $conn->query("SELECT * FROM domains ORDER BY created_at DESC");
$domains = $stmt->fetchAll();

logAdminAction('manage_domains_access');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domains - Adaptive Email Privacy Framework</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }

        .nav-links {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 30px;
            flex-wrap: wrap;
            align-items: center;
        }

        .nav-links a {
            background-color: #007bff;
            color: white;
            padding: 12px 18px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 500;
            font-size: 14px;
            white-space: nowrap;
            transition: all 0.3s ease;
            border: none;
            display: inline-block;
        }

        .nav-links a:hover {
            background-color: #0056b3;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 123, 255, 0.3);
        }

        .success-message,
        .error-message {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 2rem;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .form-section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .form-section h2 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .domains-list {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .domains-list h2 {
            background: #667eea;
            color: white;
            margin: 0;
            padding: 1rem 2rem;
        }

        .domain-item {
            border-bottom: 1px solid #eee;
            padding: 1.5rem 2rem;
        }

        .domain-item:last-child {
            border-bottom: none;
        }

        .domain-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .domain-name {
            font-size: 1.2rem;
            font-weight: bold;
            color: #333;
        }

        .domain-status {
            display: flex;
            gap: 0.5rem;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .domain-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            color: #666;
            overflow: hidden;
        }

        .detail-label {
            font-weight: 500;
            color: #333;
            margin-bottom: 4px;
        }

        .detail-item>div:not(.detail-label) {
            word-break: break-all;
            font-size: 14px;
        }

        .domain-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .edit-form {
            display: none;
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 5px;
            margin-top: 1rem;
        }

        .password-note {
            background: #fff3cd;
            color: #856404;
            padding: 0.5rem;
            border-radius: 3px;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        @media (max-width: 768px) {
            .nav-links {
                gap: 8px;
            }

            .nav-links a {
                padding: 10px 14px;
                font-size: 13px;
            }
        }

        @media (max-width: 480px) {
            .nav-links {
                gap: 6px;
            }

            .nav-links a {
                padding: 8px 12px;
                font-size: 12px;
            }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/admin-console.css?v=20260729-1">
    <script>
        function toggleEdit(domainId) {
            const form = document.getElementById('edit-form-' + domainId);
            if (form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
            } else {
                form.style.display = 'none';
            }
        }

        function confirmDelete(domainName) {
            return confirm('Are you sure you want to delete the domain "' + domainName + '"? This action cannot be undone.');
        }
    </script>
</head>

<body>
    <div class="container">
        <a class="admin-brand" href="../" aria-label="Adaptive Email Privacy Framework home"><img class="admin-brand-logo" src="../assets/images/brand-logo.webp" alt="" width="30" height="30" aria-hidden="true"><span class="admin-brand-text">Adaptive Email Privacy Framework</span></a>
        <h1>Domain Management</h1>

        <div class="nav-links">
            <a href="../mailbox">Inbox</a>
            <a href="./">Dashboard</a>
            <a href="system_stats">Statistics</a>
            <a href="settings">Settings</a>
            <a href="security_logs">Security Logs</a>
            <a href="logout">Logout</a>
        </div>

        <?php echo $message; ?>

        <!-- Add Domain Form -->
        <div class="form-section">
            <h2>Add New Domain</h2>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="domain_name">Domain Name</label>
                        <input type="text" id="domain_name" name="domain_name" placeholder="example.com" required>
                    </div>
                    <div class="form-group">
                        <label for="imap_server">IMAP Server</label>
                        <input type="text" id="imap_server" name="imap_server" placeholder="imap.example.com" required>
                    </div>
                    <div class="form-group">
                        <label for="imap_port">IMAP Port</label>
                        <input type="number" id="imap_port" name="imap_port" value="993" required>
                    </div>
                    <div class="form-group">
                        <label for="imap_username">IMAP Username</label>
                        <input type="text" id="imap_username" name="imap_username" placeholder="catchall@example.com" required>
                    </div>
                    <div class="form-group">
                        <label for="imap_password">IMAP Password</label>
                        <input type="password" id="imap_password" name="imap_password" required>
                        <div class="password-note">🔒 Password will be encrypted before storage</div>
                    </div>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" id="use_ssl" name="use_ssl" checked>
                    <label for="use_ssl">Use SSL/TLS</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" id="is_active" name="is_active" checked>
                    <label for="is_active">Active</label>
                </div>
                <button type="submit" class="btn btn-primary">🚀 Add Domain</button>
            </form>
        </div>

        <!-- Existing Domains -->
        <div class="domains-list">
            <h2>📋 Existing Domains (<?php echo count($domains); ?>)</h2>
            <?php if (empty($domains)): ?>
                <div style="padding: 2rem; text-align: center; color: #666;">
                    No domains configured yet. Add your first domain above.
                </div>
            <?php else: ?>
                <?php foreach ($domains as $domain): ?>
                    <div class="domain-item">
                        <div class="domain-header">
                            <div class="domain-name"><?php echo htmlspecialchars($domain['domain_name']); ?></div>
                            <div class="domain-status">
                                <span class="status-badge <?php echo $domain['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $domain['is_active'] ? '✅ Active' : '❌ Inactive'; ?>
                                </span>
                                <span class="status-badge <?php echo $domain['use_ssl'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $domain['use_ssl'] ? '🔒 SSL' : '🔓 No SSL'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="domain-details">
                            <div class="detail-item">
                                <div class="detail-label">IMAP Server</div>
                                <div><?php echo htmlspecialchars($domain['imap_server']); ?>:<?php echo $domain['imap_port']; ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Username</div>
                                <div><?php echo htmlspecialchars($domain['imap_username']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Password Status</div>
                                <div><?php echo !empty($domain['imap_password_encrypted']) ? '🔒 Encrypted' : '⚠️ Not Set'; ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Created</div>
                                <div><?php echo date('M j, Y g:i A', strtotime($domain['created_at'])); ?></div>
                            </div>
                        </div>

                        <div class="domain-actions">
                            <button type="button" onclick="toggleEdit(<?php echo $domain['id']; ?>)" class="btn btn-primary">
                                ✏️ Edit
                            </button>
                            <form method="post" style="display: inline;" onsubmit="return confirmDelete('<?php echo htmlspecialchars($domain['domain_name']); ?>')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $domain['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                                <button type="submit" class="btn btn-danger">Delete</button>
                            </form>
                        </div>

                        <!-- Edit Form -->
                        <div id="edit-form-<?php echo $domain['id']; ?>" class="edit-form">
                            <h4>Edit Domain: <?php echo htmlspecialchars($domain['domain_name']); ?></h4>
                            <form method="post">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="id" value="<?php echo $domain['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Domain Name</label>
                                        <input type="text" name="domain_name" value="<?php echo htmlspecialchars($domain['domain_name']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>IMAP Server</label>
                                        <input type="text" name="imap_server" value="<?php echo htmlspecialchars($domain['imap_server']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>IMAP Port</label>
                                        <input type="number" name="imap_port" value="<?php echo $domain['imap_port']; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>IMAP Username</label>
                                        <input type="text" name="imap_username" value="<?php echo htmlspecialchars($domain['imap_username']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>IMAP Password</label>
                                        <input type="password" name="imap_password" placeholder="Leave empty to keep current password">
                                        <div class="password-note">🔒 Leave empty to keep current encrypted password</div>
                                    </div>
                                </div>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="use_ssl" <?php echo $domain['use_ssl'] ? 'checked' : ''; ?>>
                                    <label>Use SSL/TLS</label>
                                </div>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="is_active" <?php echo $domain['is_active'] ? 'checked' : ''; ?>>
                                    <label>Active</label>
                                </div>
                                <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>