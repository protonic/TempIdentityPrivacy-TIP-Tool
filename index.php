<?php
require_once 'includes/config_path.php';
require_once tipConfigDir() . '/config.php';
require_once 'includes/functions.php';

// Define site name if not already defined in config
if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'TIP TOOL');
}

$siteName = defined('SITE_NAME') ? SITE_NAME : 'TIP TOOL';
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://example.com';
$canonicalUrl = $siteUrl . '/';
$metaTitle = 'Temporary Email Service | ' . $siteName;
$metaDescription = 'Protect your inbox with a self-hosted temporary email service. Generate disposable addresses for sign-ups, trials, and downloads while keeping your primary inbox private.';

// Send security headers BEFORE starting session
sendSecurityHeaders();

// Start secure session for HTTPS
initSecureSession();

$isAdminLoggedIn = !empty($_SESSION['admin_logged_in']);

// The mailbox itself lives in mailbox.php; this is only the public landing page.
require __DIR__ . '/landing.php';
