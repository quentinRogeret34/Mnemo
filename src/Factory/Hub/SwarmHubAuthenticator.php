<?php

namespace App\Factory\Hub;

use App\Entity\SwarmRun;
use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmHubManager;
use App\Repository\SwarmRunRepository;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Authenticates external Swarm Hub HTTP requests via per-swarm Bearer/query token and IP rate limiting.
 */
final class SwarmHubAuthenticator
{
    public function __construct(
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmHubManager $hubManager,
        private readonly FactoryGuard $factoryGuard,
        #[Target('swarm_hub.limiter')]
        private readonly RateLimiterFactoryInterface $swarmHubLimiter,
    ) {
    }

    public function authenticate(Request $request, string $publicId): SwarmRun
    {
        $this->factoryGuard->requireEnabled();

        $limiter = $this->swarmHubLimiter->create($request->getClientIp() ?? 'anon');
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'Too many requests to Swarm Hub.');
        }

        $token = $this->extractToken($request);
        if (null === $token) {
            throw new UnauthorizedHttpException('Bearer', 'Missing Swarm Hub token.');
        }

        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$swarm->getStatus()->isActive() || !$this->hubManager->verifyHubToken($swarm, $token)) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid or expired Swarm Hub token.');
        }

        return $swarm;
    }

    public function extractToken(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');
        if (\is_string($authHeader) && 0 === stripos($authHeader, 'Bearer ')) {
            $bearer = trim(substr($authHeader, 7));
            if ('' !== $bearer) {
                return $bearer;
            }
        }

        $queryToken = $request->query->get('token');
        if (\is_string($queryToken)) {
            $trimmed = trim($queryToken);
            if ('' !== $trimmed) {
                return $trimmed;
            }
        }

        return null;
    }

    public function applySecurityHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
