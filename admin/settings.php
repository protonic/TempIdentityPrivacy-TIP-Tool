<?php
// Admin System Settings
require_once 'auth.php'; // Ensure admin is logged in
require_once '../includes/database_class.php';

$db = new Database();
$conn = $db->connect();
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_admin':
                    // Add new admin user
                    $username = trim($_POST['username']);
                    $password = $_POST['password'];
                    $email = trim($_POST['email']);

                    if (strlen($password) < 8) {
                        throw new Exception("Password must be at least 8 characters long");
                    }

                    // Check if username already exists
                    $stmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        throw new Exception("Username already exists");
                    }

                    // Hash password and insert
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("
                        INSERT INTO admin_users (username, password_hash, email, created_at) 
                        VALUES (?, ?, ?, NOW())
                    ");
                    $stmt->execute([$username, $hashedPassword, $email]);

                    // Log the action
                    logAdminAction('admin_created', ['new_username' => $username]);
                    $message = "✅ Admin user '$username' created successfully!";
                    break;

                case 'delete_admin':
                    // Delete admin user
                    $adminId = (int)$_POST['admin_id'];

                    // Prevent deleting yourself
                    if ($adminId == $_SESSION['admin_user_id']) {
                        throw new Exception("You cannot delete your own account");
                    }

                    // Get username for logging
                    $stmt = $conn->prepare("SELECT username FROM admin_users WHERE id = ?");
                    $stmt->execute([$adminId]);
                    $adminUser = $stmt->fetch();

                    if (!$adminUser) {
                        throw new Exception("Admin user not found");
                    }

                    // Delete admin and their sessions
                    $stmt = $conn->prepare("DELETE FROM admin_users WHERE id = ?");
                    $stmt->execute([$adminId]);

                    // Log the action
                    logAdminAction('admin_deleted', ['deleted_username' => $adminUser['username']]);
                    $message = "✅ Admin user '{$adminUser['username']}' deleted successfully!";
                    break;

                case 'change_password':
                    // Change current admin password
                    $currentPassword = $_POST['current_password'];
                    $newPassword = $_POST['new_password'];
                    $confirmPassword = $_POST['confirm_password'];

                    if ($newPassword !== $confirmPassword) {
                        throw new Exception("New passwords do not match");
                    }

                    if (strlen($newPassword) < 8) {
                        throw new Exception("New password must be at least 8 characters long");
                    }

                    // Verify current password
                    $stmt = $conn->prepare("SELECT password_hash FROM admin_users WHERE id = ?");
                    $stmt->execute([$_SESSION['admin_user_id']]);
                    $admin = $stmt->fetch();

                    if (!password_verify($currentPassword, $admin['password_hash'])) {
                        throw new Exception("Current password is incorrect");
                    }

                    // Update password
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("
                        UPDATE admin_users 
                        SET password_hash = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$hashedPassword, $_SESSION['admin_user_id']]);

                    // Log the action
                    logAdminAction('password_changed');
                    $message = "✅ Password changed successfully!";
                    break;

                case 'kill_all_sessions':
                    // Kill all active admin sessions
                    $stmt = $conn->prepare("UPDATE admin_sessions SET is_active = 0 WHERE is_active = 1");
                    $stmt->execute();
                    $affectedRows = $stmt->rowCount();

                    // Log the action
                    logAdminAction('all_sessions_killed', ['sessions_killed' => $affectedRows]);
                    $message = "✅ All active admin sessions have been terminated! {$affectedRows} session(s) killed.";
                    break;
            }
        }
    } catch (Exception $e) {
        $error = "❌ Error: " . $e->getMessage();
        error_log("Settings error: " . $e->getMessage());
    }
}

// Get current admin users
$stmt = $conn->query("
    SELECT id, username, email, created_at, last_login, 
           (SELECT COUNT(*) FROM admin_sessions WHERE user_id = admin_users.id AND is_active = 1) as active_sessions
    FROM admin_users 
    ORDER BY created_at DESC
");
$adminUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Admin Panel</title>
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

        .settings-section {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .settings-section h2 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #007cba;
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-group input[type="number"] {
            width: 150px;
        }

        .btn {
            background-color: #007cba;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }

        .btn:hover {
            background-color: #005a87;
        }

        .btn-danger {
            background-color: #dc3545;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .admin-table th,
        .admin-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .admin-table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }

        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .status-active {
            color: #28a745;
            font-weight: bold;
        }

        .status-inactive {
            color: #6c757d;
        }

        .form-row {
            display: flex;
            gap: 20px;
        }

        .form-row .form-group {
            flex: 1;
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
    <link rel="stylesheet" href="../assets/css/admin-console.css?v=20260731-1">
</head>

<body class="admin-console">
    <div class="container">
        <a class="admin-brand" href="../" aria-label="Adaptive Email Privacy Framework home"><img class="admin-brand-logo" src="../assets/images/brand-logo.webp" alt="" width="30" height="30" aria-hidden="true"><span class="admin-brand-text">Adaptive Email Privacy Framework</span></a>
        <h1>System Settings</h1>

        <div class="nav-links">
            <a href="../mailbox">Inbox</a>
            <a href="./">Dashboard</a>
            <a href="manage_domains">Domains</a>
            <a href="system_stats">Statistics</a>
            <a href="security_logs">Security Logs</a>
            <a href="logout">Logout</a>
        </div>

        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Admin User Management -->
        <div class="settings-section">
            <h2>👥 Admin User Management</h2>

            <!-- Add New Admin -->
            <h3>Add New Admin User</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_admin">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" required minlength="3">
                    </div>
                    <div class="form-group">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required minlength="8">
                    <small>Minimum 8 characters</small>
                </div>
                <button type="submit" class="btn">Add Admin User</button>
            </form>

            <!-- Kill All Active Sessions -->
            <h3>Security Actions</h3>
            <form method="POST" onsubmit="return confirm('Are you sure you want to kill ALL active admin sessions? This will log out all admins from all devices immediately.');">
                <input type="hidden" name="action" value="kill_all_sessions">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                <p style="margin-bottom: 10px; color: #666;">
                    <strong>Warning:</strong> This will immediately terminate all active admin sessions across all devices and browsers.
                    All admins will be logged out and will need to log in again.
                </p>
                <button type="submit" class="btn btn-danger">Kill All Active Sessions</button>
            </form>

            <!-- Current Admin Users -->
            <h3>Current Admin Users</h3>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Created</th>
                        <th>Last Login</th>
                        <th>Active Sessions</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($adminUsers as $admin): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($admin['username']); ?>
                                <?php if ($admin['id'] == $_SESSION['admin_user_id']): ?>
                                    <span style="color: #007cba;">(You)</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($admin['email']); ?></td>
                            <td><?php echo date('M j, Y', strtotime($admin['created_at'])); ?></td>
                            <td>
                                <?php if ($admin['last_login']): ?>
                                    <?php echo date('M j, Y g:i A', strtotime($admin['last_login'])); ?>
                                <?php else: ?>
                                    <span class="status-inactive">Never</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($admin['active_sessions'] > 0): ?>
                                    <span class="status-active"><?php echo $admin['active_sessions']; ?> active</span>
                                <?php else: ?>
                                    <span class="status-inactive">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($admin['id'] != $_SESSION['admin_user_id']): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this admin user?');">
                                        <input type="hidden" name="action" value="delete_admin">
                                        <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                                        <button type="submit" class="btn btn-danger">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="status-inactive">Current User</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Change Password -->
        <div class="settings-section">
            <h2>🔐 Change Your Password</h2>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                <div class="form-group">
                    <label for="current_password">Current Password:</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="new_password">New Password:</label>
                        <input type="password" id="new_password" name="new_password" required minlength="8">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password:</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                    </div>
                </div>
                <button type="submit" class="btn">Change Password</button>
            </form>
        </div>
    </div>
</body>

</html>