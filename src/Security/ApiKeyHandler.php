<?php

namespace App\Security;

use App\Entity\ApiKey;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class ApiKeyHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $key = $this->entityManager->getRepository(ApiKey::class)->findOneBy(['tokenHash' => ApiKey::hash($accessToken)]);
        if (null === $key || $key->isRevoked() || (null !== $key->getOwner() && !$key->getOwner()->isActive())) {
            throw new BadCredentialsException('Invalid API key.');
        }

        // Throttle last_used_at writes (at most once every 5 minutes) via DBAL to avoid row lock contention and stray ORM flushes.
        $now = new \DateTimeImmutable();
        $threshold = $now->modify('-5 minutes');
        if (null === $key->getLastUsedAt() || $key->getLastUsedAt() < $threshold) {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE api_key SET last_used_at = :now WHERE id = :id AND (last_used_at IS NULL OR last_used_at < :threshold)',
                ['now' => $now, 'id' => $key->getId(), 'threshold' => $threshold],
                ['now' => Types::DATETIME_IMMUTABLE, 'threshold' => Types::DATETIME_IMMUTABLE],
            );
        }

        return new UserBadge($key->getUserIdentifier(), static fn (): ApiKey => $key);
    }
}
