<?php
// CIDR and IP utility functions

/**
 * Validate if a string is a valid IP address or CIDR block
 */
function isValidIPOrCIDR($input)
{
    // Check if it's a plain IP address
    if (filter_var($input, FILTER_VALIDATE_IP)) {
        return true;
    }

    // Check if it's a CIDR block
    if (preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}\/([0-9]|[1-2][0-9]|3[0-2])$/', $input)) {
        // IPv4 CIDR validation
        $parts = explode('/', $input);
        $ip = $parts[0];
        $prefix = (int)$parts[1];

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        return $prefix >= 0 && $prefix <= 32;
    }

    // Check if it's an IPv6 CIDR block
    if (preg_match('/^([0-9a-fA-F:]+)\/([0-9]|[1-9][0-9]|1[0-1][0-9]|12[0-8])$/', $input)) {
        // IPv6 CIDR validation
        $parts = explode('/', $input);
        $ip = $parts[0];
        $prefix = (int)$parts[1];

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        return $prefix >= 0 && $prefix <= 128;
    }

    return false;
}

/**
 * Check if an IP address falls within a CIDR block
 */
function ipInCIDR($ip, $cidr)
{
    // If it's just an IP (no CIDR), do exact match
    if (filter_var($cidr, FILTER_VALIDATE_IP)) {
        return $ip === $cidr;
    }

    // Handle CIDR blocks
    list($subnet, $mask) = explode('/', $cidr);

    if (
        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
        filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
    ) {
        // IPv4 CIDR check
        return ipv4InCIDR($ip, $cidr);
    } elseif (
        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) &&
        filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
    ) {
        // IPv6 CIDR check
        return ipv6InCIDR($ip, $cidr);
    }

    return false;
}

/**
 * Check if IPv4 address is in CIDR range
 */
function ipv4InCIDR($ip, $cidr)
{
    list($subnet, $mask) = explode('/', $cidr);

    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    $mask_long = -1 << (32 - $mask);

    return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
}

/**
 * Check if IPv6 address is in CIDR range
 */
function ipv6InCIDR($ip, $cidr)
{
    list($subnet, $mask) = explode('/', $cidr);

    // Convert IPv6 to binary
    $ip_bin = inet_pton($ip);
    $subnet_bin = inet_pton($subnet);

    if (!$ip_bin || !$subnet_bin) {
        return false;
    }

    // Convert to string of bits
    $ip_bits = '';
    $subnet_bits = '';

    for ($i = 0; $i < strlen($ip_bin); $i++) {
        $ip_bits .= str_pad(decbin(ord($ip_bin[$i])), 8, '0', STR_PAD_LEFT);
        $subnet_bits .= str_pad(decbin(ord($subnet_bin[$i])), 8, '0', STR_PAD_LEFT);
    }

    // Compare the first $mask bits
    return substr($ip_bits, 0, $mask) === substr($subnet_bits, 0, $mask);
}

/**
 * Get the type of IP/CIDR input
 */
function getIPType($input)
{
    if (filter_var($input, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return 'IPv4';
    }

    if (filter_var($input, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return 'IPv6';
    }

    if (preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}\/([0-9]|[1-2][0-9]|3[0-2])$/', $input)) {
        return 'IPv4 CIDR';
    }

    if (preg_match('/^([0-9a-fA-F:]+)\/([0-9]|[1-9][0-9]|1[0-1][0-9]|12[0-8])$/', $input)) {
        return 'IPv6 CIDR';
    }

    return 'Invalid';
}
