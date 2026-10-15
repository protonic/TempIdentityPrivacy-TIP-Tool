<?php

/**
 * Authenticated encryption for secrets stored in the database (IMAP passwords).
 *
 * Current format ("v2"):  "v2:" . base64( iv[12] . tag[16] . ciphertext )
 *   - AES-256-GCM, so tampering or a wrong key is detected at decrypt time.
 *   - The 32-byte key is derived from CRON_SECRET_KEY with HKDF-SHA256.
 *
 * Legacy formats are decrypt-only so existing rows keep working until they are
 * re-saved or migrated:
 *   - random-IV AES-256-CBC: base64( iv[16] . base64(ciphertext) )
 *   - deterministic-IV AES-256-CBC: base64( base64(ciphertext) )
 */

if (!defined('TIP_SECRET_V2_PREFIX')) {
    define('TIP_SECRET_V2_PREFIX', 'v2:');
}

function tipDeriveSecretKey(string $masterKey): string
{
    if ($masterKey === '') {
        throw new RuntimeException('CRON_SECRET_KEY is not configured');
    }

    return hash_hkdf('sha256', $masterKey, 32, 'tip-tool/imap-credential/v2');
}

function tipEncryptSecret(string $plaintext, string $masterKey): string
{
    $key = tipDeriveSecretKey($masterKey);
    $iv = random_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'tip:imap_password',
        16
    );

    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Secret encryption failed');
    }

    return TIP_SECRET_V2_PREFIX . base64_encode($iv . $tag . $ciphertext);
}

function tipSecretIsLegacy(string $stored): bool
{
    return strncmp($stored, TIP_SECRET_V2_PREFIX, strlen(TIP_SECRET_V2_PREFIX)) !== 0;
}

/**
 * Returns the plaintext, or false if the value is malformed, was tampered with,
 * or was encrypted under a different key.
 */
function tipDecryptSecret(string $stored, string $masterKey)
{
    if ($stored === '' || $masterKey === '') {
        return false;
    }

    if (!tipSecretIsLegacy($stored)) {
        $raw = base64_decode(substr($stored, strlen(TIP_SECRET_V2_PREFIX)), true);
        if ($raw === false || strlen($raw) <= 28) {
            return false;
        }

        $plaintext = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            tipDeriveSecretKey($masterKey),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            'tip:imap_password'
        );

        return $plaintext === false ? false : $plaintext;
    }

    $raw = base64_decode($stored);

    // Legacy: random 16-byte IV prepended to the ciphertext.
    if (strlen($raw) > 16) {
        $plaintext = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $masterKey, 0, substr($raw, 0, 16));
        if ($plaintext !== false && $plaintext !== '') {
            return $plaintext;
        }
    }

    // Legacy: deterministic IV derived from the key.
    $plaintext = openssl_decrypt($raw, 'AES-256-CBC', $masterKey, 0, substr(hash('sha256', $masterKey), 0, 16));

    return ($plaintext === false || $plaintext === '') ? false : $plaintext;
}
