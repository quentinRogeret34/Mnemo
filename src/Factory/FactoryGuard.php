<?php

namespace App\Factory;

use App\Dashboard\DashboardPreferences;
use App\Enum\DashboardRole;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gates the Software Factory behind dev mode (or explicit FACTORY_ENABLED=1) and Tech/Super-Admin access.
 */
final class FactoryGuard
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(string:FACTORY_ENABLED)%')]
        private readonly string $factoryEnabled,
        private readonly Security $security,
        private readonly DashboardPreferences $dashboardPreferences,
    ) {
    }

    public function isEnabled(): bool
    {
        $flag = strtolower(trim($this->factoryEnabled));
        if (\in_array($flag, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (\in_array($flag, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return \in_array($this->environment, ['dev', 'test'], true);
    }

    public function canOperate(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        $role = $this->dashboardPreferences->current()['role'];
        if (null === $this->security->getUser()) {
            return DashboardRole::Business !== $role;
        }

        return DashboardRole::Dev === $role;
    }

    public function requireEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new NotFoundHttpException('Software Factory is disabled in this environment.');
        }
    }

    public function requireAccess(): void
    {
        $this->requireEnabled();
        if (!$this->canOperate()) {
            throw new AccessDeniedHttpException('Software Factory is reserved to Tech / Dev mode.');
        }
    }
}
