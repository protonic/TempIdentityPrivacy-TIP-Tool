<?php

/**
 * Shared Footer Component
 *
 * Usage: include this file at the bottom of your pages before closing </body>
 * Make sure to define $footer_paths before including this file
 */

// Default paths - these can be overridden by setting $footer_paths before including this file
if (!isset($footer_paths)) {
    $footer_paths = [
        'home' => '../',
        'script' => '../assets/js/script.js'
    ];
}

// For pages that might be in different directory levels, allow path customization
if (!isset($script_path)) {
    $script_path = $footer_paths['script'];
}
?>

<!-- Shared Footer -->
<footer class="site-footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-section footer-brand">
                <h3><?php echo defined('SITE_NAME') ? SITE_NAME : 'TempEmail Service'; ?></h3>
                <p>Your trusted temporary email service for enhanced privacy and security.</p>
            </div>
            <div class="footer-section">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="<?php echo $footer_paths['home']; ?>">Home</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> <?php echo defined('SITE_NAME') ? SITE_NAME : 'TempEmail Service'; ?>. All rights reserved.</p>
            <p class="footer-tagline">Protecting your privacy, one temporary email at a time.</p>
        </div>
    </div>
</footer>

<?php if (!empty($script_path)): ?>
    <script src="<?php echo $script_path; ?>"></script>
<?php endif; ?>