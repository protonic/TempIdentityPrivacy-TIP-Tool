<?php

/**
 * Web-based Pseudo-Cron System (like WordPress wp-cron)
 * This runs automatically when users visit your site
 */

// Only run if it's been more than 2 minutes since last fetch
$lastFetchFile = dirname(__DIR__) . '/logs/last_fetch.txt';
$currentTime = time();
$lastFetch = file_exists($lastFetchFile) ? (int)file_get_contents($lastFetchFile) : 0;

// Run every 2 minutes (120 seconds)
$fetchInterval = 120;

if (($currentTime - $lastFetch) >= $fetchInterval) {
    // Update last fetch time first to prevent multiple simultaneous runs
    file_put_contents($lastFetchFile, $currentTime);

    // Run email fetch in the background
    if (function_exists('fastcgi_finish_request')) {
        // Send response to user immediately, continue processing in background
        fastcgi_finish_request();
    }

    try {
        // Use external cron script from the sibling cron folder.
        require_once __DIR__ . '/cron_path.php';
        $fetchScript = tipCronDir() . '/fetch_emails_fixed.php';
        if (!file_exists($fetchScript)) {
            throw new Exception('Missing cron script: ' . $fetchScript);
        }
        require_once $fetchScript;

        // Log successful fetch
        $logMessage = date('Y-m-d H:i:s') . " - Email fetch completed\n";
        file_put_contents(dirname(__DIR__) . '/logs/fetch.log', $logMessage, FILE_APPEND);
    } catch (Exception $e) {
        // Log error
        $logMessage = date('Y-m-d H:i:s') . " - Email fetch error: " . $e->getMessage() . "\n";
        file_put_contents(dirname(__DIR__) . '/logs/fetch.log', $logMessage, FILE_APPEND);
    }
}
