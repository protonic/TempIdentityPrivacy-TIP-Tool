<?php

/**
 * Resolves the absolute path to the external config folder (config.php +
 * database.php) that setup.php creates as a sibling of the web root.
 *
 * The folder name comes from config_folder_name.php so every entry point
 * in the app (index.php, admin/*, includes/handlers/*, scripts/*) agrees
 * on the same location, even when a deployment picked a custom name
 * instead of the default 'tip-config'.
 *
 * config_folder_name.php is deployment-specific (written by setup.php) and
 * is gitignored, so it may not exist - e.g. on a fresh clone before setup
 * has run. Falls back to 'tip-config' in that case.
 */

if (!function_exists('tipConfigDir')) {
    function tipConfigDir()
    {
        static $dir = null;

        if ($dir !== null) {
            return $dir;
        }

        $folderName = 'tip-config';
        $markerFile = __DIR__ . '/config_folder_name.php';

        if (is_file($markerFile)) {
            $loaded = require $markerFile;
            if (is_string($loaded) && preg_match('/^[A-Za-z0-9_-]+$/', $loaded) === 1) {
                $folderName = $loaded;
            }
        }

        $webRoot = dirname(__DIR__);
        $parentRoot = dirname($webRoot);

        $dir = $parentRoot . '/' . $folderName;

        return $dir;
    }
}
