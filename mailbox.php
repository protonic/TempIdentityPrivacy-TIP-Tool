<?php
require_once __DIR__ . '/includes/config_path.php';
require_once tipConfigDir() . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/database_class.php';

initSecureSession();

// This personal, single-owner mailbox uses the existing administrator account.
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
    error_log('Mailbox authentication error: ' . $exception->getMessage());
    header('Location: admin/login');
    exit;
}

define('SHOW_MAILBOX', true);
sendSecurityHeaders();

$siteName = defined('SITE_NAME') ? SITE_NAME : 'Adaptive Email Privacy Framework';
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://example.com';
$canonicalUrl = $siteUrl . '/mailbox';
$metaTitle = 'Private Mailbox | ' . $siteName;
$metaDescription = 'Access your private mailbox, generate temporary addresses, and view messages without exposing your primary inbox.';
$mailboxCssVersion = @filemtime(__DIR__ . '/assets/css/mailbox.css') ?: time();
$mailboxJsVersion = @filemtime(__DIR__ . '/assets/js/script.js') ?: time();
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

<body class="mailbox-page">
    <header class="mailbox-topbar">
        <a class="mailbox-brand" href="./" aria-label="Adaptive Email Privacy Framework home">
            <img class="mailbox-brand-logo" src="assets/images/brand-logo.webp" alt="" width="31" height="31" aria-hidden="true">
            <span class="mailbox-brand-text">Adaptive Email Privacy Framework</span>
        </a>
        <div class="mailbox-topbar-actions">
            <span class="mailbox-status"><i></i> Private mailbox</span>
            <a href="archived">Archived</a>
            <a href="admin/manage_domains">Settings</a>
            <button id="darkModeToggle" aria-label="Toggle dark mode" class="dark-toggle-btn"><span id="darkModeIcon">🌙</span></button>
            <a class="sign-out" href="admin/logout">Sign out</a>
        </div>
    </header>

    <div class="mailbox-shell">
        <aside class="mailbox-sidebar">
            <p class="sidebar-label">Temporary address</p>
            <label class="visually-hidden" for="domainSelect">Choose a domain</label>
            <select id="domainSelect" class="domain-select">
                <option value="">Loading domains...</option>
            </select>
            <button id="generateBtn" class="generate-address-btn">+ Generate address</button>
            <section id="currentEmail" class="active-address" style="display: none;">
                <p class="sidebar-label">Active address</p>
                <strong id="emailAddress"></strong>
                <span>Expires <span id="expiryTime"></span></span>
                <div class="address-actions">
                    <button id="copyBtn" class="compact-button">Copy</button>
                    <button id="deleteBtn" class="compact-button danger">Delete</button>
                </div>
            </section>

            <div class="sidebar-footer">
                <p>This private portal stores only addresses and messages created in your session.</p><a href="admin/manage_domains">Manage domains</a>
            </div>
        </aside>

        <section class="mail-list-pane" aria-label="Inbox">
            <div class="pane-heading">
                <div>
                    <p class="sidebar-label">Mailbox</p>
                    <h1>Inbox <span id="emailCount">(0)</span></h1>
                </div><button id="refreshBtn" class="refresh-button">Refresh</button>
            </div>
            <div id="bulkActionsBar" class="bulk-actions-bar" style="display: none;">
                <label class="bulk-select-label" for="selectAllEmails">
                    <input id="selectAllEmails" type="checkbox">
                    <span>Select all</span>
                </label>
                <span id="selectedEmailCount" class="selected-email-count">0 selected</span>
                <button id="archiveSelectedBtn" type="button" class="archive-selected-button" disabled>Archive selected</button>
                <button id="deleteSelectedBtn" type="button" class="delete-selected-button" disabled>Delete selected</button>
            </div>
            <div id="emailList" class="email-list">
                <div class="no-emails">
                    <p>Generate an address to start receiving messages.</p>
                </div>
            </div>
        </section>

        <main id="readerPanel" class="reader-panel">
            <div class="reader-empty"><span>✦</span>
                <h2>Your mailbox is ready</h2>
                <p>Generate a temporary address, then select a message from your inbox to read it here.</p>
            </div>
        </main>
    </div>

    <div id="emailModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalSubject"></h3><button class="close-modal" type="button">&times;</button>
            </div>
            <div class="modal-body">
                <div class="email-details">
                    <p><strong>From:</strong> <span id="modalFrom"></span></p>
                    <p><strong>Date:</strong> <span id="modalDate"></span></p>
                </div>
                <div class="email-body" id="modalBody"></div>
            </div>
        </div>
    </div>
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js"></script>
    <script src="assets/js/script.js?v=<?php echo (int) $mailboxJsVersion; ?>"></script>
</body>

</html>