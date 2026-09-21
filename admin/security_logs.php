<?php
// Admin Security Logs
require_once 'auth.php'; // Ensure admin is logged in
require_once '../includes/database_class.php';

$db = new Database();
$conn = $db->connect();

// Pagination settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Filter settings
$eventType = isset($_GET['event_type']) ? $_GET['event_type'] : '';
$severity = isset($_GET['severity']) ? $_GET['severity'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query
$whereConditions = [];
$params = [];

if (!empty($eventType)) {
    $whereConditions[] = "event_type = ?";
    $params[] = $eventType;
}

if (!empty($severity)) {
    $whereConditions[] = "severity = ?";
    $params[] = $severity;
}

if (!empty($dateFrom)) {
    $whereConditions[] = "created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}

if (!empty($dateTo)) {
    $whereConditions[] = "created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total FROM security_log $whereClause";
$countStmt = $conn->prepare($countQuery);
$countStmt->execute($params);
$totalLogs = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalLogs / $limit);

// Get logs
$logsQuery = "
    SELECT id, event_type, user_id, username, ip_address, details, severity, created_at 
    FROM security_log 
    $whereClause 
    ORDER BY created_at DESC 
    LIMIT $limit OFFSET $offset
";
$logsStmt = $conn->prepare($logsQuery);
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_events,
        COUNT(CASE WHEN severity = 'HIGH' THEN 1 END) as high_severity,
        COUNT(CASE WHEN severity = 'MEDIUM' THEN 1 END) as medium_severity,
        COUNT(CASE WHEN severity = 'LOW' THEN 1 END) as low_severity,
        COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h,
        COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as last_7d
    FROM security_log
";
$statsStmt = $conn->query($statsQuery);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get event types for filter
$eventTypesQuery = "SELECT DISTINCT event_type FROM security_log ORDER BY event_type";
$eventTypesStmt = $conn->query($eventTypesQuery);
$eventTypes = $eventTypesStmt->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Logs - Admin Panel</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .container {
            max-width: 1400px;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-card h3 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 1em;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            margin: 10px 0;
        }

        .high-severity {
            color: #dc3545;
            border-left: 4px solid #dc3545;
        }

        .medium-severity {
            color: #ffc107;
            border-left: 4px solid #ffc107;
        }

        .low-severity {
            color: #28a745;
            border-left: 4px solid #28a745;
        }

        .total-events {
            color: #007cba;
            border-left: 4px solid #007cba;
        }

        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .filter-row {
            display: flex;
            gap: 15px;
            align-items: end;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .btn {
            background-color: #007cba;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            background-color: #005a87;
        }

        .logs-table {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background-color: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #dee2e6;
        }

        .table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: top;
        }

        .table tr:hover {
            background-color: #f8f9fa;
        }

        .severity-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
        }

        .severity-high {
            background-color: #dc3545;
            color: white;
        }

        .severity-medium {
            background-color: #ffc107;
            color: #212529;
        }

        .severity-low {
            background-color: #28a745;
            color: white;
        }

        .details-cell {
            max-width: 300px;
            word-wrap: break-word;
            font-family: monospace;
            font-size: 0.9em;
        }

        .pagination {
            text-align: center;
            margin-top: 20px;
        }

        .pagination a,
        .pagination span {
            display: inline-block;
            padding: 8px 12px;
            margin: 0 4px;
            border: 1px solid #dee2e6;
            text-decoration: none;
            color: #007cba;
        }

        .pagination .current {
            background-color: #007cba;
            color: white;
        }

        .timestamp {
            white-space: nowrap;
            font-size: 0.9em;
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
        <h1>Security Logs</h1>

        <div class="nav-links">
            <a href="../mailbox">Inbox</a>
            <a href="./">Dashboard</a>
            <a href="manage_domains">Domains</a>
            <a href="system_stats">Statistics</a>
            <a href="settings">Settings</a>
            <a href="logout">Logout</a>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card total-events">
                <h3>Total Events</h3>
                <div class="stat-number"><?php echo number_format($stats['total_events']); ?></div>
            </div>
            <div class="stat-card high-severity">
                <h3>High Severity</h3>
                <div class="stat-number"><?php echo number_format($stats['high_severity']); ?></div>
            </div>
            <div class="stat-card medium-severity">
                <h3>Medium Severity</h3>
                <div class="stat-number"><?php echo number_format($stats['medium_severity']); ?></div>
            </div>
            <div class="stat-card low-severity">
                <h3>Low Severity</h3>
                <div class="stat-number"><?php echo number_format($stats['low_severity']); ?></div>
            </div>
            <div class="stat-card total-events">
                <h3>Last 24 Hours</h3>
                <div class="stat-number"><?php echo number_format($stats['last_24h']); ?></div>
            </div>
            <div class="stat-card total-events">
                <h3>Last 7 Days</h3>
                <div class="stat-number"><?php echo number_format($stats['last_7d']); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="event_type">Event Type:</label>
                        <select name="event_type" id="event_type">
                            <option value="">All Events</option>
                            <?php foreach ($eventTypes as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>"
                                    <?php echo $eventType === $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $type))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="severity">Severity:</label>
                        <select name="severity" id="severity">
                            <option value="">All Levels</option>
                            <option value="HIGH" <?php echo $severity === 'HIGH' ? 'selected' : ''; ?>>High</option>
                            <option value="MEDIUM" <?php echo $severity === 'MEDIUM' ? 'selected' : ''; ?>>Medium</option>
                            <option value="LOW" <?php echo $severity === 'LOW' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="date_from">Date From:</label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="date_to">Date To:</label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>
                    <div class="filter-group">
                        <button type="submit" class="btn">Filter</button>
                        <a href="security_logs" class="btn" style="background-color: #6c757d;">🔄 Clear</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Logs Table -->
        <div class="logs-table">
            <table class="table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Event Type</th>
                        <th>User</th>
                        <th>IP Address</th>
                        <th>Severity</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px;">
                                No security logs found for the selected criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="timestamp">
                                    <?php echo date('M j, Y<br>g:i:s A', strtotime($log['created_at'])); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['event_type']))); ?>
                                </td>
                                <td>
                                    <?php if ($log['username']): ?>
                                        <strong><?php echo htmlspecialchars($log['username']); ?></strong>
                                        <?php if ($log['user_id']): ?>
                                            <br><small>ID: <?php echo $log['user_id']; ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <em>System</em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                <td>
                                    <span class="severity-badge severity-<?php echo strtolower($log['severity']); ?>">
                                        <?php echo $log['severity']; ?>
                                    </span>
                                </td>
                                <td class="details-cell">
                                    <?php
                                    if (!empty($log['details'])) {
                                        $details = json_decode($log['details'], true);
                                        if (json_last_error() === JSON_ERROR_NONE && is_array($details)) {
                                            foreach ($details as $key => $value) {
                                                echo "<strong>" . htmlspecialchars($key) . ":</strong> " .
                                                    htmlspecialchars($value) . "<br>";
                                            }
                                        } else {
                                            echo htmlspecialchars($log['details']);
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">« Previous</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 5); $i <= min($totalPages, $page + 5); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next »</a>
                <?php endif; ?>
            </div>
            <p style="text-align: center; color: #666;">
                Showing page <?php echo $page; ?> of <?php echo $totalPages; ?>
                (<?php echo number_format($totalLogs); ?> total events)
            </p>
        <?php endif; ?>
    </div>
</body>

</html>