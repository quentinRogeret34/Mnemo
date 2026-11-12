<?php

namespace App\Security;

use App\Entity\ApiKey;
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
        if (null === $key || $key->isRevoked()) {
            throw new BadCredentialsException('Invalid API key.');
        }
        $key->markUsed();
        $this->entityManager->flush();

        return new UserBadge($key->getUserIdentifier(), static fn (): ApiKey => $key);
    }
}
