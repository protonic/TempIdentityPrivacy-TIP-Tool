<?php

/**
 * Resolves the absolute path to the external cron folder that setup.php
 * creates as a sibling of the web root. See config_path.php for the
 * equivalent resolver for the config folder.
 *
 * cron_folder_name.php is deployment-specific (written by setup.php) and
 * is gitignored, so it may not exist - e.g. on a fresh clone before setup
 * has run. Falls back to 'tip-cron' in that case.
 */

if (!function_exists('tipCronDir')) {
    function tipCronDir()
    {
        static $dir = null;

        if ($dir !== null) {
            return $dir;
        }

        $folderName = 'tip-cron';
        $markerFile = __DIR__ . '/cron_folder_name.php';

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
