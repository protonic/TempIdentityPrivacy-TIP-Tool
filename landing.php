<?php
$siteName = defined('SITE_NAME') ? SITE_NAME : 'Adaptive Email Privacy Framework';
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://example.com';
$canonicalUrl = $siteUrl . '/';
$metaTitle = 'Temporary Email Service | ' . $siteName;
$metaDescription = 'Protect your inbox with a self-hosted temporary email service. Generate disposable addresses for sign-ups, trials, and downloads while keeping your primary inbox private.';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0d1117">
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title><?php echo htmlspecialchars($metaTitle); ?></title>
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($metaTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonicalUrl); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($siteName); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($siteUrl . '/assets/images/brand-logo.webp'); ?>">
    <meta property="og:image:alt" content="Adaptive Email Privacy Framework logo">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($metaTitle); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($siteUrl . '/assets/images/brand-logo.webp'); ?>">
    <meta name="twitter:image:alt" content="Adaptive Email Privacy Framework logo">
    <link rel="icon" href="assets/images/brand-logo.webp" type="image/webp">
    <link rel="icon" href="assets/images/favicon.ico" type="image/x-icon" sizes="any">
    <link rel="apple-touch-icon" href="assets/images/apple-touch-icon.png">
    <link rel="stylesheet" href="assets/css/landing.css">
</head>

<body class="aepf-page">
    <header class="site-header">
        <a class="brand" href="./" aria-label="Adaptive Email Privacy Framework home">
            <img class="brand-logo" src="assets/images/brand-logo.webp" alt="" width="30" height="30" aria-hidden="true">
            <span>Adaptive Email Privacy Framework</span>
        </a>

        <nav class="header-actions" aria-label="Primary navigation">
            <a href="#how-it-works">How it works</a>
            <a href="#why-it-matters">Why it matters</a>
            <?php if (!empty($isAdminLoggedIn)): ?>
                <a class="signed-in" href="mailbox">Signed in</a>
            <?php else: ?>
                <details class="sign-in-menu">
                    <summary>Sign in</summary>
                    <form class="sign-in-card" method="post" action="admin/login">
                        <div class="sign-in-heading">
                            <strong>Sign in to your mailbox</strong>
                            <span>Use the administrator account created during setup.</span>
                        </div>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                        <label for="landing-username">Username</label>
                        <input id="landing-username" name="username" type="text" autocomplete="username" required>
                        <label for="landing-password">Password</label>
                        <input id="landing-password" name="password" type="password" autocomplete="current-password" required>
                        <button type="submit">Sign in</button>
                    </form>
                </details>
            <?php endif; ?>
        </nav>
    </header>

    <main>
        <section class="hero" aria-labelledby="hero-title">
            <div class="hero-copy">
                <p class="eyebrow">Open-source • Self-hosted • Personal control</p>
                <h1 id="hero-title">Keep sign-ups out of the inbox that matters.</h1>
                <p class="hero-lead">Adaptive Email Privacy Framework gives you a private, self-hosted temporary-mail portal. Create addresses for trials, downloads, and unfamiliar services without handing over the inbox tied to your life.</p>
                <div class="hero-actions">
                    <?php if (!empty($isAdminLoggedIn)): ?>
                        <a class="button button-primary" href="mailbox">Open your workspace</a>
                    <?php else: ?>
                        <a class="button button-primary" href="admin/login">Sign in to your mailbox</a>
                    <?php endif; ?>
                    <a class="button button-secondary" href="#how-it-works">Explore the framework</a>
                </div>
                <p class="setup-note">Built for one owner: clone it, configure your own domain and IMAP mailbox, then keep control of the data.</p>
            </div>

            <div class="hero-panel" aria-label="Email privacy illustration">
                <div class="panel-status"><span></span> Your mail boundary</div>
                <div class="email-route">
                    <div class="route-node source">Sites &amp;<br>sign-ups</div>
                    <div class="route-line" aria-hidden="true"></div>
                    <div class="route-node shield">✦</div>
                    <div class="route-line" aria-hidden="true"></div>
                    <div class="route-node inbox">Personal<br>inbox</div>
                </div>
                <div class="panel-caption">Use a temporary address as the boundary between unknown services and your permanent identity.</div>
            </div>
        </section>

        <section class="principles" aria-label="Framework principles">
            <article><span>01</span>
                <h2>Host it yourself</h2>
                <p>Your domain, your mailbox provider, and your database stay under your control.</p>
            </article>
            <article><span>02</span>
                <h2>Use with intention</h2>
                <p>Give each service an address that can be discarded when it becomes noisy or risky.</p>
            </article>
            <article><span>03</span>
                <h2>Reduce exposure</h2>
                <p>Keep personal, recovery, banking, and other critical email accounts separate from casual sign-ups.</p>
            </article>
        </section>

        <section class="content-section" id="why-it-matters">
            <div class="section-heading">
                <p class="eyebrow">Why it matters</p>
                <h2>Email is often the first link in a phishing chain.</h2>
            </div>
            <div class="two-column">
                <p>Spam and impersonation attempts can reach anyone: younger people, older relatives, and busy professionals. A disposable address is not a cure for fraud, but it is a practical way to limit where unfamiliar organisations can contact you.</p>
                <p>By isolating sign-ups, verification messages, and one-time downloads, you can reduce unwanted mail in your permanent inbox and make unexpected messages easier to question.</p>
            </div>
            <aside class="safety-note"><strong>A privacy tool, not a replacement for caution.</strong> Never use a temporary address for banking, government, healthcare, password recovery, or any account you may need to recover later.</aside>
        </section>

        <section class="content-section workflow" id="how-it-works">
            <div class="section-heading">
                <p class="eyebrow">How it works</p>
                <h2>One personal portal. A clearer email boundary.</h2>
            </div>
            <ol>
                <li><span>1</span>
                    <div>
                        <h3>Set up your private instance</h3>
                        <p>Deploy the framework, connect your database, and configure an IMAP-backed domain that you own.</p>
                    </div>
                </li>
                <li><span>2</span>
                    <div>
                        <h3>Generate a temporary address</h3>
                        <p>Create a short-lived address whenever a website needs an email but does not need your permanent one.</p>
                    </div>
                </li>
                <li><span>3</span>
                    <div>
                        <h3>Read and discard safely</h3>
                        <p>Use the mailbox for expected verification messages, then delete the address or let it expire.</p>
                    </div>
                </li>
            </ol>
        </section>

        <section class="owner-card">
            <div>
                <p class="eyebrow">SERVING A PURPOSE</p>
                <h2>A calm, personal mailbox—not another public data service.</h2>
                <p>Adaptive Email Privacy Framework is designed to be copied from GitHub, configured with your own settings, and used as a private layer around your online life.</p>
            </div>
            <?php if (!empty($isAdminLoggedIn)): ?>
                <a class="button button-primary" href="mailbox">Open your workspace</a>
            <?php else: ?>
                <a class="button button-primary" href="admin/login">Sign in</a>
            <?php endif; ?>
        </section>
    </main>

    <footer>Adaptive Email Privacy Framework <span>•</span> Open-source self-hosted email privacy</footer>
</body>

</html>