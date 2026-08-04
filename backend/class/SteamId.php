<?php

function wp_parse_steamid64(?string $raw): ?string
{
    if ($raw === null || !preg_match('/^[0-9]{17,20}$/D', $raw)) {
        return null;
    }

    $normalized = ltrim($raw, '0');
    if ($normalized === '') {
        return null;
    }

    $maxUint64 = '18446744073709551615';
    if (strlen($normalized) > strlen($maxUint64)) {
        return null;
    }
    if (strlen($normalized) === strlen($maxUint64) && strcmp($normalized, $maxUint64) > 0) {
        return null;
    }

    return $raw;
}
