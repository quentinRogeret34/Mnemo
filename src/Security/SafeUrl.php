<?php

namespace App\Security;

/**
 * Validates external URLs against SSRF (private/loopback/link-local targets) and unsafe link schemes (javascript:, data:).
 */
final class SafeUrl
{
    /**
     * Checks that a URL uses https:// and does not point to localhost or a private/reserved IP address.
     *
     * @throws \RuntimeException when the URL is not a safe HTTPS target
     */
    public static function requireHttps(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? '')) || !isset($parts['host']) || '' === $parts['host']) {
            throw new \RuntimeException(\sprintf('URL HTTPS invalide ou refusée : %s', $url));
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ('localhost' === $host || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new \RuntimeException(\sprintf('Hôte non autorisé : %s', $host));
        }

        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            if (!self::isPublicIp($host)) {
                throw new \RuntimeException(\sprintf('Adresse IP privée ou réservée refusée : %s', $host));
            }

            return $url;
        }

        // Resolve DNS when available; if records exist, every resolved address must be public.
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        if (\is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (\is_string($ip) && !self::isPublicIp($ip)) {
                    throw new \RuntimeException(\sprintf('L\'hôte "%s" pointe vers une adresse IP privée ou réservée.', $host));
                }
            }
        }

        return $url;
    }

    /**
     * Keeps only http(s) URLs for external links rendered in href attributes; returns null otherwise.
     */
    public static function http(?string $url): ?string
    {
        if (null === $url) {
            return null;
        }
        $url = trim($url);
        if ('' === $url) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        $host = (string) parse_url($url, \PHP_URL_HOST);

        return \in_array($scheme, ['http', 'https'], true) && '' !== $host ? $url : null;
    }

    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function isPublicIp(string $ip): bool
    {
        return false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
    }
}
