<?php
require_once __DIR__ . '/includes/config_path.php';
require_once tipConfigDir() . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/database_class.php';

initSecureSession();

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_user_id']) || empty($_SESSION['admin_session_token'])) {
    header('Location: admin/login');
    exit;
}

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    if (!$isLocalHost) {
        header('Location: https://' . $host . $_SERVER['REQUEST_URI']);
        exit;
    }
}

try {
    $database = new Database();
    $connection = $database->connect();
    $statement = $connection->prepare(
        'SELECT s.id FROM admin_sessions s JOIN admin_users u ON u.id = s.user_id WHERE s.session_token = ? AND s.user_id = ? AND s.is_active = 1 AND s.expires_at > NOW() AND u.is_active = 1 LIMIT 1'
    );
    $statement->execute([$_SESSION['admin_session_token'], $_SESSION['admin_user_id']]);
    if (!$statement->fetch()) {
        header('Location: admin/login?expired=1');
        exit;
    }
} catch (Exception $exception) {
    error_log('Archived mailbox authentication error: ' . $exception->getMessage());
    header('Location: admin/login');
    exit;
}

sendSecurityHeaders();

$siteName = defined('SITE_NAME') ? SITE_NAME : 'Adaptive Email Privacy Framework';
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://example.com';
$canonicalUrl = $siteUrl . '/archived';
$metaTitle = 'Archived Emails | ' . $siteName;
$metaDescription = 'View important archived emails preserved from your SQL mailbox.';
$mailboxCssVersion = @filemtime(__DIR__ . '/assets/css/mailbox.css') ?: time();
$archivedJsVersion = @filemtime(__DIR__ . '/assets/js/archived.js') ?: time();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f8fafc">
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title><?php echo htmlspecialchars($metaTitle); ?></title>
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($metaTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonicalUrl); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($siteName); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($metaTitle); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <link rel="icon" href="assets/images/brand-logo.webp" type="image/webp">
    <link rel="icon" href="assets/images/favicon.ico" type="image/x-icon" sizes="any">
    <link rel="apple-touch-icon" href="assets/images/apple-touch-icon.png">
    <link rel="stylesheet" href="assets/css/style.min.css">
    <link rel="stylesheet" href="assets/css/mailbox.css?v=<?php echo (int) $mailboxCssVersion; ?>">
</head>

<body class="mailbox-page archived-page">
    <header class="mailbox-topbar">
        <a class="mailbox-brand" href="./" aria-label="Adaptive Email Privacy Framework home">
            <img class="mailbox-brand-logo" src="assets/images/brand-logo.webp" alt="" width="31" height="31" aria-hidden="true">
            <span class="mailbox-brand-text">Adaptive Email Privacy Framework</span>
        </a>
        <div class="mailbox-topbar-actions">
            <span class="mailbox-status"><i></i> Private mailbox</span>
            <a href="mailbox">Inbox</a>
            <a class="active" href="archived">Archived</a>
            <a href="admin/manage_domains">Settings</a>
            <button id="darkModeToggle" aria-label="Toggle dark mode" class="dark-toggle-btn"><span id="darkModeIcon">🌙</span></button>
            <a class="sign-out" href="admin/logout">Sign out</a>
        </div>
    </header>

    <div class="archived-shell">
        <section class="archived-list-pane" aria-label="Archived emails">
            <div class="pane-heading">
                <div>
                    <p class="sidebar-label">Archived mailbox</p>
                    <h1>Archived <span id="archivedEmailCount">(0)</span></h1>
                </div>
                <button id="refreshArchivedBtn" class="refresh-button">Refresh</button>
            </div>
            <div id="archivedList" class="email-list">
                <div class="no-emails">
                    <p>No archived emails yet.</p>
                </div>
            </div>
        </section>

        <main id="archivedReaderPanel" class="reader-panel">
            <div class="reader-empty"><span>✦</span>
                <h2>Archived inbox ready</h2>
                <p>Select an archived message to read it here.</p>
            </div>
        </main>
    </div>

    <div id="archivedEmailModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="archivedModalSubject"></h3><button class="close-modal" type="button">&times;</button>
            </div>
            <div class="modal-body">
                <div class="email-details">
                    <p><strong>From:</strong> <span id="archivedModalFrom"></span></p>
                    <p><strong>Date:</strong> <span id="archivedModalDate"></span></p>
                    <p><strong>Archived:</strong> <span id="archivedModalArchivedAt"></span></p>
                </div>
                <div class="email-body" id="archivedModalBody"></div>
            </div>
        </div>
    </div>

    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
    <script src="assets/js/archived.js?v=<?php echo (int) $archivedJsVersion; ?>"></script>
</body>

</html>