<?php
// Admin System Statistics Dashboard
require_once 'auth.php'; // Ensure admin is logged in and config is loaded
require_once '../includes/database_class.php';

$db = new Database();
$conn = $db->connect();

if (!$conn) {
    die('Database connection failed. Please check your configuration.');
}

// Get system stats from system_stats table
$statsQuery = $conn->query("SELECT stat_name, stat_value, updated_at FROM system_stats ORDER BY stat_name");
$systemStats = [];
while ($row = $statsQuery->fetch(PDO::FETCH_ASSOC)) {
    $systemStats[$row['stat_name']] = $row;
}

// Get real-time database statistics
$total = $conn->query("SELECT COUNT(*) as count FROM temp_emails")->fetch(PDO::FETCH_ASSOC)['count'];
$active = $conn->query("SELECT COUNT(*) as count FROM temp_emails WHERE is_active = 1")->fetch(PDO::FETCH_ASSOC)['count'];
$expired = $conn->query("SELECT COUNT(*) as count FROM temp_emails WHERE expires_at < NOW()")->fetch(PDO::FETCH_ASSOC)['count'];

// Email messages statistics
$totalMessages = $conn->query("SELECT COUNT(*) as count FROM email_messages")->fetch(PDO::FETCH_ASSOC)['count'];
$messagesLast24h = $conn->query("SELECT COUNT(*) as count FROM email_messages WHERE received_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch(PDO::FETCH_ASSOC)['count'];
$messagesLast48h = $conn->query("SELECT COUNT(*) as count FROM email_messages WHERE received_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)")->fetch(PDO::FETCH_ASSOC)['count'];

// Domain statistics
$totalDomains = $conn->query("SELECT COUNT(*) as count FROM domains")->fetch(PDO::FETCH_ASSOC)['count'];
$activeDomains = $conn->query("SELECT COUNT(*) as count FROM domains WHERE is_active = 1")->fetch(PDO::FETCH_ASSOC)['count'];

// Security statistics
try {
    $adminSessions = $conn->query("SELECT COUNT(*) as count FROM admin_sessions WHERE is_active = 1")->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    $adminSessions = 0;
    error_log("Admin sessions query failed: " . $e->getMessage());
}

try {
    $securityLogs = $conn->query("SELECT COUNT(*) as count FROM security_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    $securityLogs = 0;
    error_log("Security logs query failed: " . $e->getMessage());
}

try {
    $rateLimitHits = $conn->query("SELECT COUNT(*) as count FROM rate_limits WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    $rateLimitHits = 0;
    error_log("Rate limit hits query failed: " . $e->getMessage());
}

// Today's statistics (from today_stats.php functionality)
try {
    $emailsToday = $conn->query("SELECT COUNT(*) as count FROM temp_emails WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC)['count'];
    $messagesToday = $conn->query("SELECT COUNT(*) as count FROM email_messages WHERE DATE(received_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC)['count'];
    $rateLimitHitsToday = $conn->query("SELECT COUNT(*) as count FROM rate_limits WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC)['count'];
    $securityLogsToday = $conn->query("SELECT COUNT(*) as count FROM security_log WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    $emailsToday = $messagesToday = $rateLimitHitsToday = $securityLogsToday = 0;
    error_log("Today's statistics query failed: " . $e->getMessage());
}

// Growth metrics (last 7 days vs previous 7 days) - using historical stats table
// Note: This now uses the email_generation_stats table which persists across email cleanup
try {
    $emailsThisWeekQuery = $conn->query("
        SELECT SUM(emails_generated) as total
        FROM email_generation_stats
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $emailsThisWeek = (int)($emailsThisWeekQuery->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $emailsLastWeekQuery = $conn->query("
        SELECT SUM(emails_generated) as total
        FROM email_generation_stats
        WHERE date BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE_SUB(CURDATE(), INTERVAL 8 DAY)
    ");
    $emailsLastWeek = (int)($emailsLastWeekQuery->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $emailGrowth = $emailsLastWeek > 0 ? round((($emailsThisWeek - $emailsLastWeek) / $emailsLastWeek) * 100, 1) : 0;
} catch (PDOException $e) {
    // If table doesn't exist yet, show 0 growth
    $emailsThisWeek = 0;
    $emailsLastWeek = 0;
    $emailGrowth = 0;
    error_log("Growth calculation failed: " . $e->getMessage());
}

// Most popular domain (last 7 days) - using current active emails since historical domain data isn't stored
try {
    $popularDomain = $conn->query("
        SELECT d.domain_name, COUNT(te.id) as count
        FROM temp_emails te
        JOIN domains d ON te.domain_id = d.id
        WHERE te.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY d.domain_name
        ORDER BY count DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $popularDomain = null;
    error_log("Popular domain query failed: " . $e->getMessage());
}

// Recent activity
$recentEmails = $conn->query("
    SELECT te.email_address, te.created_at, te.expires_at 
    FROM temp_emails te 
    WHERE te.is_active = 1 
    ORDER BY te.created_at DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$recentMessages = $conn->query("
    SELECT em.subject, em.sender_email, em.received_at, te.email_address
    FROM email_messages em
    JOIN temp_emails te ON em.temp_email_id = te.id
    ORDER BY em.received_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Statistics - Admin Panel</title>
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #007cba;
        }

        .stat-card h3 {
            margin: 0 0 15px 0;
            color: #333;
            font-size: 1.1em;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #007cba;
            margin: 10px 0;
        }

        .stat-detail {
            font-size: 0.9em;
            color: #666;
            margin: 5px 0;
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

        .recent-activity {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            max-width: 1000px;
            margin-left: auto;
            margin-right: auto;
        }

        .activity-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .timestamp {
            color: #888;
            font-size: 0.8em;
        }

        .security-card {
            border-left-color: #dc3545;
        }

        .email-card {
            border-left-color: #28a745;
        }

        .domain-card {
            border-left-color: #ffc107;
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
        <h1>System Statistics Dashboard</h1>

        <div class="nav-links">
            <a href="../mailbox">Inbox</a>
            <a href="./">Dashboard</a>
            <a href="manage_domains">Domains</a>
            <a href="settings">Settings</a>
            <a href="security_logs">Security Logs</a>
            <a href="logout">Logout</a>
        </div>

        <div class="stats-grid">
            <!-- Email Statistics -->
            <div class="stat-card email-card">
                <h3>Email Addresses</h3>
                <div class="stat-number"><?php echo number_format($total); ?></div>
                <div class="stat-detail">Total created: <?php echo $systemStats['total_emails_generated']['stat_value'] ?? 'N/A'; ?></div>
                <div class="stat-detail">Currently active: <?php echo number_format($active); ?></div>
                <div class="stat-detail">Expired: <?php echo number_format($expired); ?></div>
            </div>

            <!-- Message Statistics -->
            <div class="stat-card email-card">
                <h3>📬 Email Messages</h3>
                <div class="stat-number"><?php echo number_format($totalMessages); ?></div>
                <div class="stat-detail">Messages in last 48 hours (auto-cleaned)</div>
                <div class="stat-detail">Last 24 hours: <?php echo number_format($messagesLast24h); ?></div>
                <div class="stat-detail">Last 48 hours: <?php echo number_format($messagesLast48h); ?></div>
            </div>

            <!-- Domain Statistics -->
            <div class="stat-card domain-card">
                <h3>Domains</h3>
                <div class="stat-number"><?php echo $activeDomains; ?></div>
                <div class="stat-detail">Active domains</div>
                <div class="stat-detail">Total configured: <?php echo number_format($totalDomains); ?></div>
                <div class="stat-detail">System domains: <?php echo $systemStats['total_domains']['stat_value'] ?? 'N/A'; ?></div>
            </div>

            <!-- Security Statistics -->
            <div class="stat-card security-card">
                <h3>Security Status</h3>
                <div class="stat-number"><?php echo $adminSessions; ?></div>
                <div class="stat-detail">Active admin sessions</div>
                <div class="stat-detail">Security events (24h): <?php echo number_format($securityLogs); ?></div>
                <div class="stat-detail">Rate limit hits (24h): <?php echo number_format($rateLimitHits); ?></div>
            </div>

            <!-- Growth Statistics -->
            <div class="stat-card" style="border-left-color: <?php echo $emailGrowth >= 0 ? '#28a745' : '#dc3545'; ?>;">
                <h3>📈 Growth (Last 7 Days)</h3>
                <div class="stat-number" style="color: <?php echo $emailGrowth >= 0 ? '#28a745' : '#dc3545'; ?>;">
                    <?php echo $emailGrowth >= 0 ? '+' : ''; ?><?php echo $emailGrowth; ?>%
                </div>
                <div class="stat-detail">This week: <?php echo number_format($emailsThisWeek); ?> emails</div>
                <div class="stat-detail">Last week: <?php echo number_format($emailsLastWeek); ?> emails</div>
                <?php if ($popularDomain): ?>
                    <div class="stat-detail">Popular domain: <?php echo htmlspecialchars($popularDomain['domain_name']); ?> (<?php echo $popularDomain['count']; ?>)</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Today's Activity Section -->
        <div class="recent-activity">
            <h3>📊 Today's Activity Overview</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                <div style="background: #e8f5e8; padding: 15px; border-radius: 8px; border-left: 4px solid #28a745;">
                    <h4 style="margin: 0 0 10px 0; color: #28a745;">📧 Today's Emails</h4>
                    <div style="font-size: 24px; font-weight: bold; color: #28a745;"><?php echo number_format($emailsToday ?? 0); ?></div>
                    <div style="font-size: 12px; color: #666;">Generated today</div>
                </div>
                <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; border-left: 4px solid #2196f3;">
                    <h4 style="margin: 0 0 10px 0; color: #2196f3;">📬 Today's Messages</h4>
                    <div style="font-size: 24px; font-weight: bold; color: #2196f3;"><?php echo number_format($messagesToday ?? 0); ?></div>
                    <div style="font-size: 12px; color: #666;">Received today</div>
                </div>
                <div style="background: #fff3e0; padding: 15px; border-radius: 8px; border-left: 4px solid #ff9800;">
                    <h4 style="margin: 0 0 10px 0; color: #ff9800;">⚡ Rate Limit Hits</h4>
                    <div style="font-size: 24px; font-weight: bold; color: #ff9800;"><?php echo number_format($rateLimitHitsToday ?? 0); ?></div>
                    <div style="font-size: 12px; color: #666;">Today</div>
                </div>
                <div style="background: #ffebee; padding: 15px; border-radius: 8px; border-left: 4px solid #f44336;">
                    <h4 style="margin: 0 0 10px 0; color: #f44336;">🛡️ Security Events</h4>
                    <div style="font-size: 24px; font-weight: bold; color: #f44336;"><?php echo number_format($securityLogsToday ?? 0); ?></div>
                    <div style="font-size: 12px; color: #666;">Today</div>
                </div>
            </div>
        </div>

        <!-- Recent Email Addresses -->
        <div class="recent-activity">
            <h3>📧 Recent Email Addresses</h3>
            <?php if (empty($recentEmails)): ?>
                <p>No recent email addresses found.</p>
            <?php else: ?>
                <?php foreach ($recentEmails as $email): ?>
                    <div class="activity-item">
                        <strong><?php echo htmlspecialchars($email['email_address']); ?></strong>
                        <div class="timestamp">
                            Created: <?php echo date('M j, Y g:i A', strtotime($email['created_at'])); ?> |
                            Expires: <?php echo date('M j, Y g:i A', strtotime($email['expires_at'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="recent-activity">
            <h3>📨 Recent Email Messages</h3>
            <?php if (empty($recentMessages)): ?>
                <p>No recent messages found.</p>
            <?php else: ?>
                <?php foreach ($recentMessages as $message): ?>
                    <div class="activity-item">
                        <strong><?php echo htmlspecialchars($message['subject']); ?></strong>
                        <div class="timestamp">
                            To: <?php echo htmlspecialchars($message['email_address']); ?> |
                            From: <?php echo htmlspecialchars($message['sender_email']); ?> |
                            <?php echo date('M j, Y g:i A', strtotime($message['received_at'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Key Performance Indicators - Only Useful Metrics -->
        <div class="recent-activity">
            <h3>Key Performance Indicators</h3>

            <?php
            // Filter out dummy/duplicate stats - only show relevant ones
            $relevantStats = [
                'total_emails_generated' => 'Total Emails Generated',
                'total_messages_received' => 'Total Messages Ever Received',
                'active_email_addresses' => 'Active Email Addresses',
            ];

            $hasRelevantStats = false;
            foreach ($relevantStats as $statKey => $statLabel):
                if (isset($systemStats[$statKey]) && $systemStats[$statKey]['stat_value'] > 0):
                    $hasRelevantStats = true;
            ?>
                    <div class="activity-item">
                        <strong><?php echo $statLabel; ?>:</strong>
                        <?php echo number_format($systemStats[$statKey]['stat_value']); ?>
                        <div class="timestamp">Last updated: <?php echo date('M j, Y g:i A', strtotime($systemStats[$statKey]['updated_at'])); ?></div>
                    </div>
                <?php
                endif;
            endforeach;

            if (!$hasRelevantStats):
                ?>
                <p style="color: #666; text-align: center; padding: 20px;">No key metrics available yet. Start generating emails to see statistics!</p>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="recent-activity">
            <h3>Quick Actions</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <a href="manage_domains" style="background: #007bff; color: white; padding: 15px; border-radius: 8px; text-decoration: none; text-align: center; font-weight: 500; transition: all 0.3s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0,123,255,0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                    Manage Domains
                </a>
                <a href="security_logs" style="background: #dc3545; color: white; padding: 15px; border-radius: 8px; text-decoration: none; text-align: center; font-weight: 500; transition: all 0.3s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(220,53,69,0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                    Security Logs
                </a>
            </div>
        </div>
    </div>
</body>

</html>