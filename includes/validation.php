<?php

/**
 * Validation and sanitization helper functions.
 */

/**
 * Validate an email address format.
 *
 * @param string $email
 * @return bool
 */
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Sanitize a string (e.g., inputs from forms or APIs).
 *
 * @param string $input
 * @return string
 */
function sanitizeString(string $input): string
{
    // Remove HTML tags and encode special characters
    return trim(htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8'));
}

/**
 * Generate a CSRF token and store it in session.
 *
 * @return string
 */
function generateCSRFToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    return $token;
}

/**
 * Validate the given CSRF token.
 *
 * @param string $token
 * @return bool
 */
function validateCSRFToken(string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) {
        return false;
    }

    // Token is valid for 30 minutes
    if (
        hash_equals($_SESSION['csrf_token'], $token) &&
        ($_SESSION['csrf_token_time'] + 1800) >= time()
    ) {
        return true;
    }

    // Invalidate expired or mismatched token
    unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
    return false;
}

/**
 * Validate required POST parameters.
 *
 * @param array $requiredKeys
 * @param array $data Typically $_POST or decoded JSON payload.
 * @return array [bool $allPresent, array $missingKeys]
 */
function validateRequired(array $requiredKeys, array $data): array
{
    $missing = [];
    foreach ($requiredKeys as $key) {
        if (!isset($data[$key]) || $data[$key] === '') {
            $missing[] = $key;
        }
    }
    return [empty($missing), $missing];
}
