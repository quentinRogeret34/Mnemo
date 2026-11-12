<?php

namespace App\Security\OAuth;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Finishes a sign-in started by OAuthController: checks the state, reads the profile, finds or creates the account.
 * The very first account becomes the super admin; the next ones wait for approval.
 */
final class OAuthAuthenticator extends AbstractAuthenticator
{
    public const STATE = 'oauth_state';

    public function __construct(
        private readonly OAuthClient $client,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return 'oauth_check' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $provider = (string) $request->attributes->get('provider');
        $expected = $request->getSession()->remove(self::STATE);
        if (!\is_string($expected) || '' === $expected || !hash_equals($expected, $request->query->getString('state'))) {
            throw new CustomUserMessageAuthenticationException('La connexion a expiré, recommencer.');
        }
        if ('' === $request->query->getString('code')) {
            throw new CustomUserMessageAuthenticationException('Connexion annulée.');
        }

        try {
            $profile = $this->client->profile($provider, $request->query->getString('code'), $this->urlGenerator->generate('oauth_check', ['provider' => $provider], UrlGeneratorInterface::ABSOLUTE_URL));
        } catch (\Throwable $exception) {
            throw new CustomUserMessageAuthenticationException($exception instanceof \RuntimeException && !$exception instanceof HttpExceptionInterface ? $exception->getMessage() : 'Le fournisseur a refusé la connexion.');
        }

        $user = $this->account($profile);

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->urlGenerator->generate('dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urlGenerator->generate('login'));
    }

    private function account(OAuthProfile $profile): User
    {
        $users = $this->entityManager->getRepository(User::class);
        $user = $users->findOneBy(['provider' => $profile->provider, 'providerId' => $profile->id]) ?? $users->findOneBy(['email' => $profile->email]);
        if (null === $user) {
            $user = new User($profile->email, $profile->name, $profile->provider, $profile->id);
            if (0 === $users->count()) {
                $user->setStatus(UserStatus::Active);
                $user->setSuperAdmin(true);
            }
            $this->entityManager->persist($user);
        }
        $user->markLoggedIn($profile->name);
        $this->entityManager->flush();

        return $user;
    }
}
