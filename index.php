<?php
require_once 'includes/config_path.php';
require_once tipConfigDir() . '/config.php';
require_once 'includes/functions.php';

// Define site name if not already defined in config
if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'Adaptive Email Privacy Framework');
}

$siteName = defined('SITE_NAME') ? SITE_NAME : 'Adaptive Email Privacy Framework';
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://example.com';
$canonicalUrl = $siteUrl . '/';
$metaTitle = 'Temporary Email Service | ' . $siteName;
$metaDescription = 'Protect your inbox with a self-hosted temporary email service. Generate disposable addresses for sign-ups, trials, and downloads while keeping your primary inbox private.';

// Send security headers BEFORE starting session
sendSecurityHeaders();

// Start secure session for HTTPS
initSecureSession();

$isAdminLoggedIn = !empty($_SESSION['admin_logged_in']);

// Phase 1 is the public entry point; Phase 2 reuses the established mailbox
// functionality behind the existing administrator authentication.
if (!defined('SHOW_MAILBOX')) {
    require __DIR__ . '/landing.php';
    exit;
}

// Auto-run email fetch (WordPress-style pseudo-cron)
// Comment out temporarily if causing issues
// require_once 'includes/pseudo_cron.php';
?>
<!DOCTYPE html>
<html lang="en-US">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- SEO Meta Tags -->
    <title><?php echo htmlspecialchars($metaTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta name="author" content="<?php echo htmlspecialchars($siteName); ?>">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="theme-color" content="#0F172A">

    <!-- Canonical URL -->
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl); ?>">

    <!-- Open Graph Meta Tags for Social Media -->
    <meta property="og:title" content="<?php echo htmlspecialchars($metaTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($siteUrl . '/assets/images/brand-logo.webp'); ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonicalUrl); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($siteName); ?>">

    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($metaTitle); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($metaDescription); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($siteUrl . '/assets/images/brand-logo.webp'); ?>">

    <!-- Resource Preloading for Performance -->
    <link rel="preload" href="assets/css/style.min.css" as="style">
    <link rel="preload" href="assets/js/script.js" as="script">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="assets/css/style.min.css">
    <?php if (defined('SHOW_MAILBOX')): ?>
        <link rel="stylesheet" href="assets/css/mailbox.css">
    <?php endif; ?>

    <!-- Favicon -->
    <link rel="icon" href="assets/images/brand-logo.webp" type="image/webp">
    <link rel="icon" href="assets/images/favicon.ico" type="image/x-icon" sizes="any">
    <link rel="apple-touch-icon" href="assets/images/apple-touch-icon.png">

    <!-- Structured Data for Rich Snippets -->
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "WebApplication",
            "name": "<?php echo SITE_NAME; ?>",
            "url": "https://localhost/",
            "description": "Free temporary email service for protecting your privacy from spam and unwanted emails",
            "applicationCategory": "UtilityApplication",
            "operatingSystem": "Web",
            "offers": {
                "@type": "Offer",
                "price": "0",
                "priceCurrency": "USD"
            },
            "provider": {
                "@type": "Organization",
                "name": "<?php echo SITE_NAME; ?>"
            },
            "featureList": [
                "Temporary Email Address",
                "Temporary Email Mailbox",
                "Multi-domain Support",
                "Privacy Protection",
                "Spam Prevention",
                "Disposable Email",
                "Disposable Email Mailbox",
                "No Registration Required"
            ],
            "aggregateRating": {
                "@type": "AggregateRating",
                "ratingValue": "4.9",
                "reviewCount": "1247"
            }
        }
    </script>

    <!-- FAQ Schema Markup -->
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "name": "<?php echo SITE_NAME; ?>",
            "@type": "FAQPage",
            "mainEntity": [{
                    "@type": "Question",
                    "name": "Is temporary email safe?",
                    "acceptedAnswer": {
                        "@type": "Answer",
                        "text": "Yes, temporary email is safe when used correctly. It keeps your main inbox private and prevents many forms of spam and tracking. However, avoid using disposable emails for financial or sensitive accounts that require long-term recovery options."
                    }
                },
                {
                    "@type": "Question",
                    "name": "How long does temp mail last?",
                    "acceptedAnswer": {
                        "@type": "Answer",
                        "text": "Lifespan varies by provider. Some addresses persist for minutes or hours, while others last several days. On Temp Mail .FYI, messages are kept temporarily for your convenience and privacy."
                    }
                },
                {
                    "@type": "Question",
                    "name": "Can I forward important messages to my real inbox?",
                    "acceptedAnswer": {
                        "@type": "Answer",
                        "text": "Yes — you can manually forward important emails from the temporary inbox to your real email when needed. Forward only trusted messages to keep risk low."
                    }
                },
                {
                    "@type": "Question",
                    "name": "Are disposable emails legal?",
                    "acceptedAnswer": {
                        "@type": "Answer",
                        "text": "Using a temporary email address is legal and commonly used for privacy and testing. Avoid misuse (fraud, harassment, or evading legal notices) — those actions are illegal regardless of email type."
                    }
                },
                {
                    "@type": "Question",
                    "name": "Will a site block a temporary email address?",
                    "acceptedAnswer": {
                        "@type": "Answer",
                        "text": "Some websites block known disposable domains. Temp Mail .FYI provides multiple domains to reduce blocking, but in rare cases you may need a different domain or a permanent account for full access."
                    }
                },
                {
                    "@type": "Question",
                    "name": "Can disposable email be used to bypass service limits?",
                    "acceptedAnswer": {
                        "@type": "Answer",
                        "text": "Some people use disposable email to create multiple accounts or extend trials. Be mindful of each service's terms — repeated abuse of trials or account limits can violate terms of service."
                    }
                }
            ]
        }
    </script>

    <!-- Organization Schema for Authority -->
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "Organization",
            "name": "<?php echo SITE_NAME; ?>",
            "url": "https://localhost/",
            "logo": "https://localhost/assets/images/brand-logo.webp",
            "description": "Free temporary email service providing disposable email addresses for privacy protection and spam prevention",
            "foundingDate": "2024",
            "contactPoint": {
                "@type": "ContactPoint",
                "contactType": "Customer Support",
                "availableLanguage": ["en"]
            },
            "address": {
                "@type": "PostalAddress",
                "addressCountry": "IN"
            }
        }
    </script>

    <!-- BreadcrumbList Schema -->
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "BreadcrumbList",
            "itemListElement": [{
                "@type": "ListItem",
                "position": 1,
                "name": "Home",
                "item": "https://localhost/"
            }]
        }
    </script>

    <!-- HowTo Schema -->
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "HowTo",
            "name": "How to Use Temporary Email Service",
            "description": "Step-by-step guide to creating and using disposable email addresses for privacy protection",
            "totalTime": "PT2M",
            "tool": [{
                "@type": "HowToTool",
                "name": "Web Browser"
            }],
            "step": [{
                    "@type": "HowToStep",
                    "position": 1,
                    "name": "Visit Website or Install Extension",
                    "text": "Visit Temp Mail .FYI website or install our browser extension (Chrome or Firefox) for instant access from your browser toolbar",
                    "url": "https://localhost/"
                },
                {
                    "@type": "HowToStep",
                    "position": 2,
                    "name": "Generate Email",
                    "text": "Click the 'Generate New Email' button to instantly create a temporary email address. Choose from multiple domains if needed."
                },
                {
                    "@type": "HowToStep",
                    "position": 3,
                    "name": "Copy and Use",
                    "text": "Copy the generated temporary email address and use it for sign-ups, verifications, or any service requiring an email address."
                },
                {
                    "@type": "HowToStep",
                    "position": 4,
                    "name": "Check Inbox",
                    "text": "View incoming messages in real-time in your temporary inbox. Refresh to see new emails as they arrive."
                },
                {
                    "@type": "HowToStep",
                    "position": 5,
                    "name": "Delete When Done",
                    "text": "When finished, delete the address or let it expire automatically to remove all traces and maintain your privacy."
                }
            ]
        }
    </script>
</head>

<body class="<?php echo defined('SHOW_MAILBOX') ? 'mailbox-page' : ''; ?>">
    <div class="container">
        <header>
            <div class="header-top">
                <div class="logo-section">
                    <img
                        src="assets/images/brand-logo105x105.webp"
                        srcset="assets/images/brand-logo105x105.webp 1x, assets/images/brand-logo105x105.webp 2x"
                        sizes="52px"
                        width="52"
                        height="52"
                        alt="<?php echo SITE_NAME; ?>"
                        class="site-logo">
                    <h1><?php echo defined('SHOW_MAILBOX') ? 'Personal Mailbox' : SITE_NAME; ?></h1>
                </div>
                <button class="hamburger-menu" aria-label="Toggle navigation">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <nav class="main-nav">
                    <?php if ($isAdminLoggedIn): ?>
                        <?php if (defined('SHOW_MAILBOX')): ?><a href="admin/manage_domains" class="nav-link">Mailbox settings</a><?php endif; ?>
                        <a href="admin/logout" class="nav-link">Sign out</a>
                    <?php else: ?>
                        <a href="admin/login" class="nav-link">Admin Login</a>
                    <?php endif; ?>
                    <button id="darkModeToggle" aria-label="Toggle dark mode" class="dark-toggle-btn">
                        <span id="darkModeIcon">🌙</span>
                    </button>
                </nav>
            </div>
        </header>

        <div class="hero">
            <h1>Disposable Email. Instant Privacy.</h1>
            <p>
                Say Goodbye to Spam and Unwanted Emails! Protect your privacy with <?php echo SITE_NAME; ?>. Generate temporary email addresses instantly and protect your real inbox from spam, bots, and unwanted sign-ups. No registration required—completely free and anonymous.
            </p>
        </div>

        <main>
            <div id="emailGenerator" class="email-generator">
                <div class="generate-section">
                    <div class="domain-selection">
                        <label for="domainSelect">Choose Your Domain:</label>
                        <select id="domainSelect" class="domain-select">
                            <option value="">Loading domains...</option>
                        </select>
                    </div>
                    <button id="generateBtn" class="btn btn-primary">Generate New Email</button>
                    <div id="currentEmail" class="email-display" style="display: none;">
                        <div class="email-info">
                            <span id="emailAddress"></span>
                            <div class="email-actions-row">
                                <button id="copyBtn" class="btn btn-cta btn-small">Copy</button>
                                <button id="deleteBtn" class="btn btn-danger btn-small">Delete</button>
                            </div>
                        </div>
                        <div class="email-meta">
                            <span>Expires: <span id="expiryTime"></span></span>
                        </div>
                    </div>
                </div>

                <div class="email-actions">
                    <button id="refreshBtn" class="btn btn-secondary btn-small">Refresh Inbox</button>
                </div>
            </div>

            <div class="inbox-section">
                <h2>Inbox <span id="emailCount" class="email-counter">(0 emails)</span></h2>
                <div id="emailList" class="email-list">
                    <div class="no-emails">
                        <p>Generate an email address to start receiving messages</p>
                    </div>
                </div>

                <div id="emailModal" class="modal" style="display: none;">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3 id="modalSubject"></h3>
                            <button class="close-modal">&times;</button>
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
            </div>
        </main>

        <!-- Browser Extensions Section -->
        <section class="extension-section">
            <div class="section-header">
                <h2>Browser Extensions</h2>
                <p>Take Temp Mail .FYI with you everywhere—available for Chrome and Firefox</p>
            </div>
            <div class="extension-content">
                <div class="extension-card">
                    <div class="extension-icon">⚡</div>
                    <h3>Instant Access</h3>
                    <p>Generate temporary emails directly from your browser toolbar—no need to visit the website!</p>
                </div>
                <div class="extension-card">
                    <div class="extension-icon">🔄</div>
                    <h3>Auto-Refresh</h3>
                    <p>Your inbox updates automatically in real-time, so you never miss important messages.</p>
                </div>
                <div class="extension-card">
                    <div class="extension-icon">🛡️</div>
                    <h3>Same Security</h3>
                    <p>All the privacy protection and anonymity of our web service, now in your browser.</p>
                </div>
                <div class="extension-card">
                    <div class="extension-icon">📱</div>
                    <h3>Cross-Browser</h3>
                    <p>Works on Chrome, Firefox, Edge, Brave, and all Chromium-based browsers.</p>
                </div>
            </div>
            <div class="extension-cta">
                <div class="extension-buttons">
                    <a href="https://chromewebstore.google.com/detail/temp-mail-fyi/pglafcmmodnjmppmacmkfklioablnjpa?hl=en-US" class="extension-install-btn chrome" target="_blank" rel="noopener">
                        <span class="btn-icon">🌐</span>
                        Install for Chrome
                    </a>
                    <a href="https://addons.mozilla.org/en-US/firefox/addon/temp-mail-fyi/" class="extension-install-btn firefox" target="_blank" rel="noopener">
                        <span class="btn-icon">🦊</span>
                        Install for Firefox
                    </a>
                </div>
                <p class="extension-note">Free • No registration • Instant setup</p>
            </div>
        </section>

        <!-- Why Choose Section -->
        <section class="why-choose-section">
            <div class="section-header">
                <h2>Why Choose localhost?</h2>
                <p>The most trusted temporary email service for privacy-conscious users worldwide</p>
            </div>

            <div class="why-choose-content">
                <div class="why-intro">
                    <p>
                        In a world where your email address is constantly requested—for newsletters, free trials, app downloads, and online shopping—maintaining inbox privacy has never been more critical. <strong>localhost</strong> stands out as the premier temporary email service, combining cutting-edge privacy protection with unmatched ease of use.
                    </p>
                </div>

                <div class="why-features-grid">
                    <div class="why-feature">
                        <div class="feature-icon">⚡</div>
                        <h3>Instant, Zero-Registration Access</h3>
                        <p>
                            Unlike many competitors that require account creation or invasive tracking, localhost generates your temporary email address the moment you visit our site. No sign-ups, no personal information, no hidden fees—just instant, anonymous email protection. Our one-click generation system means you can create unlimited disposable addresses in seconds, perfect for when you need quick email access without compromise.
                        </p>
                    </div>

                    <div class="why-feature">
                        <div class="feature-icon">🔒</div>
                        <h3>Enterprise Grade Privacy Protection</h3>
                        <p>
                            Your privacy is our top priority. We implement zero-logging policies, meaning we don't track, store, or sell your data. All temporary emails are automatically purged from our servers after expiration, leaving no digital footprint. Our infrastructure is designed with privacy-first architecture, ensuring that your online activities remain completely anonymous. We don't use cookies for tracking, don't require JavaScript permissions, and never ask for personal information.
                        </p>
                    </div>

                    <div class="why-feature">
                        <div class="feature-icon">🌐</div>
                        <h3>Multiple Premium Domains</h3>
                        <p>
                            Many websites block common disposable email domains, rendering some temporary email services useless. localhost solves this with a rotating selection of premium domains that bypass most filters. Our multi-domain system gives you flexibility when certain services reject disposable emails—simply switch to another domain and continue. This strategic approach ensures maximum compatibility across the web, from social media platforms to e-commerce sites.
                        </p>
                    </div>

                    <div class="why-feature">
                        <div class="feature-icon">🚀</div>
                        <h3>Browser Extensions for Power Users</h3>
                        <p>
                            Our browser extensions bring temporary email directly to your toolbar, eliminating the need to open new tabs or interrupt your workflow. With auto-refresh functionality, real-time notifications, and cross-device synchronization, managing multiple disposable emails has never been easier. Available for Chrome, Firefox, Edge, Brave, and all Chromium-based browsers, providing consistent privacy protection wherever you browse.
                        </p>
                    </div>

                    <div class="why-feature">
                        <div class="feature-icon">📱</div>
                        <h3>Fully Responsive Mobile Experience</h3>
                        <p>
                            Over 60% of web traffic comes from mobile devices, which is why localhost is optimized for smartphones and tablets. Our mobile-first design ensures fast loading times, touch-friendly interfaces, and seamless functionality on any screen size. Whether you're shopping on your phone or signing up for services on the go, you'll enjoy the same robust privacy protection and instant email access.
                        </p>
                    </div>

                    <div class="why-feature">
                        <div class="feature-icon">⚙️</div>
                        <h3>Developer-Friendly & API-Ready</h3>
                        <p>
                            For developers, QA testers, and automation professionals, localhost offers reliable API endpoints for programmatic email generation. Our service supports bulk email creation for testing environments, allowing you to simulate user registrations, test email workflows, and verify notification systems without cluttering real inboxes. With 99.9% uptime and fast response times, we're the trusted choice for development teams worldwide.
                        </p>
                    </div>
                </div>

                <div class="stats-highlight">
                    <div class="stat-item">
                        <div class="stat-number">10M+</div>
                        <div class="stat-label">Emails Generated</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">500K+</div>
                        <div class="stat-label">Monthly Users</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">99.9%</div>
                        <div class="stat-label">Uptime Guarantee</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">4.9/5</div>
                        <div class="stat-label">User Rating</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Common Use Cases Section -->
        <section class="use-cases-section">
            <div class="section-header">
                <h2>Common Use Cases for Temporary Email</h2>
                <p>Real-world scenarios where disposable email protects your privacy</p>
            </div>

            <div class="use-cases-grid">
                <div class="use-case">
                    <div class="use-case-icon">🛍️</div>
                    <h3>Online Shopping & Free Trials</h3>
                    <p>
                        Retailers often require email addresses for checkout or to offer first-time discounts. Instead of exposing your primary inbox to marketing campaigns, use a temporary email to complete your purchase and receive order confirmations. Similarly, when testing free trials for streaming services, SaaS products, or subscription boxes, disposable emails let you evaluate services without commitment or spam follow-ups.
                    </p>
                </div>

                <div class="use-case">
                    <div class="use-case-icon">📱</div>
                    <h3>App Testing & Development</h3>
                    <p>
                        Developers and QA testers use localhost to create hundreds of test accounts quickly. Whether you're testing user registration flows, email verification systems, or password reset functionality, temporary emails provide realistic testing environments without cluttering your personal inbox. Automation teams integrate our service for continuous integration pipelines and automated testing suites.
                    </p>
                </div>

                <div class="use-case">
                    <div class="use-case-icon">📝</div>
                    <h3>Forum Registrations & Community Sites</h3>
                    <p>
                        Many forums, discussion boards, and community platforms require email verification but may later spam you with notifications or sell your data to third parties. Using a disposable email allows you to participate in discussions, access content, and engage with communities while keeping your real identity private. Perfect for one-time questions, research, or casual browsing.
                    </p>
                </div>

                <div class="use-case">
                    <div class="use-case-icon">🎓</div>
                    <h3>Educational Resources & Webinars</h3>
                    <p>
                        Accessing whitepapers, e-books, educational courses, and webinars often requires email registration. Marketers use these as lead generation tools, flooding your inbox with promotional content. A temporary email lets you download resources, attend online events, and expand your knowledge without long-term marketing consequences. Ideal for students, researchers, and lifelong learners.
                    </p>
                </div>

                <div class="use-case">
                    <div class="use-case-icon">🎮</div>
                    <h3>Gaming & Social Media Accounts</h3>
                    <p>
                        Gamers often create multiple accounts for different servers, testing characters, or managing alt profiles. Social media marketers, influencers, and content creators also maintain separate accounts for various purposes. Temporary emails make multi-account management effortless while protecting your main email from potential security breaches or data leaks associated with gaming platforms.
                    </p>
                </div>

                <div class="use-case">
                    <div class="use-case-icon">🔐</div>
                    <h3>Privacy-Conscious Communications</h3>
                    <p>
                        When you need to contact someone but don't want to reveal your real email address—such as buying/selling on classified sites, participating in surveys, or responding to Craigslist ads—temporary email provides a safe intermediary. It's also useful for whistleblowing, anonymous tips, or sensitive communications where privacy is paramount.
                    </p>
                </div>
            </div>
        </section>

        <!-- How It Protects You Section -->
        <section class="protection-section">
            <div class="section-header">
                <h2>How Temporary Email Protects Your Privacy</h2>
                <p>Understanding the security benefits of disposable email addresses</p>
            </div>

            <div class="protection-content">
                <div class="protection-intro">
                    <p>
                        In the digital age, your email address is more than just a communication tool—it's a valuable piece of personal data that can be exploited, sold, and used to track your online behavior. <strong>localhost</strong> acts as a protective barrier between your real identity and the countless websites requesting your information.
                    </p>
                </div>

                <div class="protection-points">
                    <div class="protection-point">
                        <h3>🚫 Prevents Spam and Marketing Overload</h3>
                        <p>
                            Every time you provide your real email to a website, you risk being added to marketing lists, third-party databases, and automated email campaigns. Once your email is in these systems, stopping the flood of promotional messages becomes nearly impossible. Temporary email addresses act as a firewall—they receive verification emails and important communications, but when the spam inevitably starts, you simply discard the address. Your real inbox remains pristine and productive.
                        </p>
                    </div>

                    <div class="protection-point">
                        <h3>🔐 Reduces Data Breach Exposure</h3>
                        <p>
                            Major data breaches expose millions of email addresses annually, leading to targeted phishing attacks, credential stuffing, and identity theft. By using temporary emails for non-critical services, you limit the damage if a company's database is compromised. Even if hackers obtain your disposable address, they can't access your primary accounts, financial information, or personal communications. This compartmentalization strategy significantly reduces your attack surface.
                        </p>
                    </div>

                    <div class="protection-point">
                        <h3>👁️ Blocks Online Tracking and Profiling</h3>
                        <p>
                            Data brokers and advertising networks use email addresses as unique identifiers to track your online behavior across websites, building detailed profiles of your interests, purchases, and habits. These profiles are sold to marketers, used for targeted advertising, and sometimes acquired by more sinister actors. Temporary emails break this tracking chain, preventing companies from linking your various online activities to a single, identifiable person. Each disposable address is isolated, making comprehensive profiling virtually impossible.
                        </p>
                    </div>

                    <div class="protection-point">
                        <h3>⚡ Maintains Anonymity on Untrusted Sites</h3>
                        <p>
                            Not all websites deserve your trust. When exploring new services, downloading free resources, or participating in online communities where you're uncertain about privacy practices, using your real email is a risk. Temporary emails let you engage with these platforms anonymously. If the site later proves untrustworthy, harvests data aggressively, or gets hacked, your real identity remains protected. You've given nothing valuable away.
                        </p>
                    </div>
                </div>

                <div class="protection-best-practices">
                    <h3>🎯 Best Practices for Maximum Protection</h3>
                    <ul>
                        <li><strong>Never use temporary email for financial accounts:</strong> Banks, payment processors, and investment platforms require permanent email for security recovery and legal communications.</li>
                        <li><strong>Avoid using for password resets:</strong> If you lose access to a critical account, a deleted temporary email means permanent lockout.</li>
                        <li><strong>Rotate disposable addresses regularly:</strong> Use different temporary emails for different services to compartmentalize your online presence.</li>
                        <li><strong>Combine with password managers:</strong> Pair temporary emails with unique, strong passwords for each account to maximize security.</li>
                        <li><strong>Check emails promptly:</strong> Since temporary inboxes expire, retrieve verification codes and important messages quickly.</li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Educational Information Section -->
        <section class="educational-section">
            <div class="educational-content">
                <div class="educational-header">
                    <div class="educational-intro">
                        <h2>Learn About Temporary Email</h2>
                        <p class="lead">
                            Email is your online identity — used for work, shopping, streaming, social media, and nearly every app you sign up for.
                            But with each new registration, your real inbox is exposed to spam, unwanted ads, and even data breaches.
                            At <strong>Temp Mail .FYI</strong> we provide a fast, free, and anonymous <strong>temporary email</strong> service that protects your privacy and keeps your inbox spam-free.
                        </p>
                    </div>
                </div>

                <div class="educational-grid">
                    <article class="educational-card">
                        <h3>🤔 What Is a Temporary Email Address?</h3>
                        <p>
                            A <strong>temporary email</strong> — also called a <strong>disposable email</strong> or <strong>fake email</strong> — is a short-lived inbox you create instantly to receive verification messages, sign up for services, or test apps without exposing your primary email address.
                            Once you no longer need it, the temporary address can expire or be deleted, leaving your real inbox clean and private.
                        </p>
                        <p>
                            With <strong>Temp Mail .FYI</strong>, you can generate multiple temporary email addresses, choose from several domains, and enjoy complete anonymity. If a disposable address is compromised, discard it and create another — no account setup required.
                        </p>
                    </article>

                    <article class="educational-card">
                        <h3>🛡️ Why Use Disposable Email?</h3>
                        <p>
                            Disposable email addresses solve everyday online problems. They protect you from spam, stop marketing lists from flooding your inbox, and prevent exposure in the event of a data breach.
                        </p>
                        <ul class="benefits-list">
                            <li><strong>Extend free trials:</strong> Create a new <em>temp mail</em> address to try services again without using your personal email.</li>
                            <li><strong>Sign up for store loyalty cards without spam:</strong> Get discounts without promotional mail clutter.</li>
                            <li><strong>Test apps and websites:</strong> Developers can create many test accounts quickly and safely.</li>
                            <li><strong>Run multiple online accounts:</strong> Great for social media marketers, automation, or separate profiles.</li>
                            <li><strong>Block spam from forms and forums:</strong> Use a disposable address whenever a service requires an email.</li>
                        </ul>
                    </article>

                    <article class="educational-card">
                        <h3>✅ How to Choose the Best Temporary Email Service</h3>
                        <p>
                            Not all disposable email services are equal. Look for immediate creation, no registration, multiple domains, temporary message storage, and a lightweight interface that works on mobile.
                        </p>
                        <div class="features-grid">
                            <div class="feature-item"><strong>⚡ Instant Creation</strong>
                                <p>Create addresses with one click without sign-up.</p>
                            </div>
                            <div class="feature-item"><strong>🔒 Anonymity</strong>
                                <p>No personal data collected or stored.</p>
                            </div>
                            <div class="feature-item"><strong>🌐 Multiple Domains</strong>
                                <p>Switch domains to avoid domain-based filters.</p>
                            </div>
                            <div class="feature-item"><strong>⏰ Temporary Inbox</strong>
                                <p>Messages are kept only for a limited time.</p>
                            </div>
                            <div class="feature-item"><strong>📱 Mobile Friendly</strong>
                                <p>Easy to use on phones and tablets.</p>
                            </div>
                            <div class="feature-item"><strong>♾️ Unlimited Addresses</strong>
                                <p>Create as many disposable emails as you need.</p>
                            </div>
                        </div>
                        <p class="highlight">Temp Mail .FYI meets all these expectations and is designed for privacy-first users.</p>
                    </article>

                    <article class="educational-card">
                        <h3>🚀 How to Use Temp Mail .FYI</h3>
                        <ol class="steps-list">
                            <li><strong>Option 1 - Website:</strong> Visit <strong>Temp Mail .FYI</strong> — a temporary email address is generated instantly.</li>
                            <li><strong>Option 2 - Browser Extension:</strong> Install our <a href="https://chromewebstore.google.com/detail/temp-mail-fyi/pglafcmmodnjmppmacmkfklioablnjpa?hl=en-US" target="_blank" rel="noopener" style="color: #0ea5e9; text-decoration: underline;">Chrome extension</a> or <a href="https://addons.mozilla.org/en-US/firefox/addon/temp-mail-fyi/" target="_blank" rel="noopener" style="color: #0ea5e9; text-decoration: underline;">Firefox add-on</a> for instant access from your browser toolbar!</li>
                            <li>Copy the disposable email and use it to sign up or verify a service.</li>
                            <li>View incoming messages in real time in your temporary inbox.</li>
                            <li>When finished, delete the address or let it expire to remove traces.</li>
                        </ol>
                        <p class="note">
                            If you need important emails forwarded to your real account, you can forward them manually — and block any suspicious senders quickly.
                        </p>
                        <div class="pro-tip-box">
                            <strong>💡 Pro Tip:</strong> Our browser extensions (Chrome & Firefox) make temporary email creation even faster — no need to open a new tab!
                        </div>
                    </article>
                </div>

                <!-- FAQ -->
                <div class="faq-section">
                    <h3>Frequently Asked Questions</h3>
                    <div class="faq-grid">
                        <div class="faq-item">
                            <h4>Is temporary email safe?</h4>
                            <p>
                                Yes, temporary email is safe when used correctly. It keeps your main inbox private and prevents many forms of spam and tracking. However, avoid using disposable emails for financial or sensitive accounts that require long-term recovery options.
                            </p>
                        </div>

                        <div class="faq-item">
                            <h4>How long does temp mail last?</h4>
                            <p>
                                Lifespan varies by provider. Some addresses persist for minutes or hours, while others last several days. On Temp Mail .FYI, messages are kept temporarily for your convenience and privacy.
                            </p>
                        </div>

                        <div class="faq-item">
                            <h4>Can I forward important messages to my real inbox?</h4>
                            <p>
                                Yes — you can manually forward important emails from the temporary inbox to your real email when needed. Forward only trusted messages to keep risk low.
                            </p>
                        </div>

                        <div class="faq-item">
                            <h4>Are disposable emails legal?</h4>
                            <p>
                                Using a temporary email address is legal and commonly used for privacy and testing. Avoid misuse (fraud, harassment, or evading legal notices) — those actions are illegal regardless of email type.
                            </p>
                        </div>

                        <div class="faq-item">
                            <h4>Will a site block a temporary email address?</h4>
                            <p>
                                Some websites block known disposable domains. Temp Mail .FYI provides multiple domains to reduce blocking, but in rare cases you may need a different domain or a permanent account for full access.
                            </p>
                        </div>

                        <div class="faq-item">
                            <h4>Can disposable email be used to bypass service limits?</h4>
                            <p>
                                Some people use disposable email to create multiple accounts or extend trials. Be mindful of each service's terms — repeated abuse of trials or account limits can violate terms of service.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- CTA -->
                <div class="educational-cta">
                    <h3>🎯 Get Started — Protect Your Inbox Today</h3>
                    <p>
                        Whether you want to stop spam, test an app, or sign up for a one-time offer, <strong>Temp Mail .FYI</strong> makes it easy and anonymous. Generate a <strong>temporary email</strong> in seconds and stay in control of your inbox.
                    </p>
                    <a href="#emailGenerator" onclick="event.preventDefault(); document.getElementById('emailGenerator').scrollIntoView({behavior: 'smooth', block: 'center'}); setTimeout(() => document.getElementById('generateBtn').click(), 600);" class="cta-button">Create a Free Disposable Email Now</a>
                </div>
            </div>
        </section>

        <?php
        // Footer path configuration for root directory
        $footer_paths = array(
            'home' => '/',
            'script' => 'assets/js/script.js'
        );
        include 'includes/footer.php';
        ?>
    </div>

    <script>
        // Hamburger menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const hamburger = document.querySelector('.hamburger-menu');

            if (hamburger) {
                const body = document.body;
                const mainNav = document.querySelector('.main-nav');
                const navLinks = document.querySelectorAll('.main-nav a');

                // Calculate dynamic max-height based on number of nav items
                const navItemCount = navLinks.length;
                document.documentElement.style.setProperty('--nav-items', navItemCount);

                // Toggle menu
                hamburger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    body.classList.toggle('nav-open');
                });

                // Close on nav link click
                navLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        body.classList.remove('nav-open');
                    });
                });

                // Close on outside click
                document.addEventListener('click', function(event) {
                    const isClickInside = event.target.closest('.main-nav') ||
                        event.target.closest('.hamburger-menu');
                    if (!isClickInside && body.classList.contains('nav-open')) {
                        body.classList.remove('nav-open');
                    }
                });
            }
        });
    </script>

    <input type="hidden" id="csrfToken" value="<?php echo generateCSRFToken(); ?>">
</body>

</html>