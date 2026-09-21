<?php
// Secure Admin Dashboard with Authentication
require_once 'auth.php'; // This will check authentication and redirect if not logged in

logAdminAction('dashboard_access');

$currentAdmin = $admin_user ?? ['username' => 'Admin'];

$current_time = date('Y-m-d H:i:s');
$timezone = date_default_timezone_get();
$formatted_time = date('F j, Y \a\t g:i:s A T');
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - Adaptive Email Privacy Framework</title>
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

    .dashboard-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 3rem;
      margin-top: 2rem;
      margin-bottom: 2rem;
    }

    .dashboard-card {
      background: white;
      padding: 2.5rem;
      border-radius: 10px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      transition: transform 0.2s, box-shadow 0.2s;
      border: 1px solid #e9ecef;
    }

    .dashboard-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .dashboard-card h3 {
      margin-top: 0;
      color: #333;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .dashboard-card p {
      color: #666;
      margin-bottom: 1.5rem;
    }

    .card-action {
      display: inline-block;
      background: #667eea;
      color: white;
      padding: 0.75rem 1.5rem;
      text-decoration: none;
      border-radius: 5px;
      transition: background 0.3s;
    }

    .card-action:hover {
      background: #5a67d8;
    }

    .welcome-section {
      background: white;
      padding: 2rem;
      border-radius: 10px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      margin-bottom: 2rem;
    }

    .security-notice {
      background: #f0f8ff;
      border-left: 4px solid #2c5aa0;
      padding: 1rem;
      margin-top: 2rem;
      border-radius: 0 5px 5px 0;
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
  <link rel="stylesheet" href="../assets/css/admin-dashboard.css?v=20260729-1">
</head>

<body>
  <div class="container">
    <a class="dashboard-brand" href="../" aria-label="Adaptive Email Privacy Framework home"><img class="dashboard-brand-logo" src="../assets/images/brand-logo.webp" alt="" width="30" height="30" aria-hidden="true"><span class="dashboard-brand-text">Adaptive Email Privacy Framework</span></a>
    <h1>Secure Admin Dashboard</h1>

    <div class="nav-links">
      <a href="../mailbox">Inbox</a>
      <a href="manage_domains">Domains</a>
      <a href="system_stats">Statistics</a>
      <a href="settings">Settings</a>
      <a href="security_logs">Security Logs</a>
      <a href="logout">Logout</a>
    </div>

    <div class="welcome-section">
      <h2>Welcome back, <?php echo htmlspecialchars($currentAdmin['username']); ?>!</h2>
      <p>You have secure access to the <?php echo SITE_NAME; ?> administration panel. All your actions are logged for security purposes.</p>
      <p><strong>Current System Time:</strong> <?php echo $formatted_time; ?></p>
      <p><strong>Server Timezone:</strong> <?php echo $timezone; ?></p>
    </div>

    <div class="dashboard-grid">
      <div class="dashboard-card">
        <h3>Domain Management</h3>
        <p>Add, edit, or remove email domains. Configure IMAP settings and manage domain status.</p>
        <a href="manage_domains" class="card-action">Manage Domains</a>
      </div>

      <div class="dashboard-card">
        <h3>System Statistics</h3>
        <p>View system performance, email counts, and operational statistics.</p>
        <a href="system_stats" class="card-action">View Statistics</a>
      </div>

      <div class="dashboard-card">
        <h3>Security Logs</h3>
        <p>Monitor login attempts, admin actions, and security events.</p>
        <a href="security_logs" class="card-action">View Security Logs</a>
      </div>

      <div class="dashboard-card">
        <h3>System Settings</h3>
        <p>Configure system settings, update passwords, and manage preferences.</p>
        <a href="settings" class="card-action">System Settings</a>
      </div>
    </div>

    <div class="security-notice">
      <strong>Security Information:</strong><br>
      • Your session will expire automatically after 1 hour of inactivity<br>
      • All administrative actions are logged and monitored<br>
      • Always logout when finished to maintain security<br>
      • Report any suspicious activity immediately
    </div>
  </div>
</body>

</html>