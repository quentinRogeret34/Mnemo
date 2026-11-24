<?php

namespace App\Entity;

use App\Enum\DashboardRole;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user workspace preferences (dashboard role preset and enabled widgets), keyed like ChatConversation
 * so OAuth users, the built-in admin account and local visitors each keep their own layout.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_preference')]
#[ORM\UniqueConstraint(name: 'user_preference_owner_unique', columns: ['owner_key'])]
class UserPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: DashboardRole::class)]
    private DashboardRole $dashboardRole = DashboardRole::All;

    /** @var list<string>|null null means "use the defaults of dashboardRole" */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $dashboardWidgets = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $ownerKey,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }

    public function getDashboardRole(): DashboardRole
    {
        return $this->dashboardRole;
    }

    public function setDashboardRole(DashboardRole $role): void
    {
        $this->dashboardRole = $role;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return list<string>|null
     */
    public function getDashboardWidgets(): ?array
    {
        return $this->dashboardWidgets;
    }

    /**
     * @param list<string>|null $widgets
     */
    public function setDashboardWidgets(?array $widgets): void
    {
        $this->dashboardWidgets = null === $widgets ? null : array_values(array_unique($widgets));
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
