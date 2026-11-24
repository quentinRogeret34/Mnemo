<?php

namespace App\Security\OAuth;

/**
 * OAuth access token with optional refresh token and expiration timestamp.
 * Serializes back to a plain token string when non-expiring (backwards compatible with existing stored tokens),
 * or JSON when a refresh token / expiration is present (e.g. GitHub App user-to-server tokens `ghu_...`).
 */
final readonly class OAuthTokenBundle
{
    private const REFRESH_MARGIN_SECONDS = 300;

    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        #[\SensitiveParameter]
        public ?string $refreshToken = null,
        public ?int $expiresAt = null,
    ) {
    }

    public function isExpiringSoon(?int $now = null): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return ($now ?? time()) >= ($this->expiresAt - self::REFRESH_MARGIN_SECONDS);
    }

    public function isExpired(?int $now = null): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return ($now ?? time()) >= $this->expiresAt;
    }

    public function toStoragePayload(): string
    {
        if (null === $this->refreshToken && null === $this->expiresAt) {
            return $this->accessToken;
        }

        return json_encode(array_filter([
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
        ], static fn (mixed $v): bool => null !== $v), \JSON_THROW_ON_ERROR);
    }

    public static function fromStoragePayload(#[\SensitiveParameter] string $raw): self
    {
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (\is_array($decoded) && \is_string($decoded['access_token'] ?? null) && '' !== $decoded['access_token']) {
                return new self(
                    accessToken: $decoded['access_token'],
                    refreshToken: \is_string($decoded['refresh_token'] ?? null) && '' !== $decoded['refresh_token'] ? $decoded['refresh_token'] : null,
                    expiresAt: \is_int($decoded['expires_at'] ?? null) ? $decoded['expires_at'] : null,
                );
            }
        }

        return new self($trimmed);
    }
}
