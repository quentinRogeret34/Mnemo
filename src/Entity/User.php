<?php

namespace App\Entity;

use App\Enum\UserStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Someone who signed in through GitHub, GitLab, Google or email/password. A new account waits for a super admin to approve it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'app_user_provider_unique', columns: ['provider', 'provider_id'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10, enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::Pending;

    #[ORM\Column]
    private bool $superAdmin = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordHash = null;

    /**
     * @var Collection<int, UserGroup>
     */
    #[ORM\ManyToMany(targetEntity: UserGroup::class, mappedBy: 'users')]
    private Collection $groups;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct(
        #[ORM\Column(length: 180, unique: true)]
        private string $email,
        #[ORM\Column(length: 150)]
        private string $name,
        #[ORM\Column(length: 20)]
        private string $provider,
        #[ORM\Column(length: 100)]
        private string $providerId,
    ) {
        $this->email = strtolower(trim($email));
        $this->groups = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): void
    {
        $this->status = $status;
    }

    public function isActive(): bool
    {
        return UserStatus::Active === $this->status;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin && $this->isActive();
    }

    public function setSuperAdmin(bool $superAdmin): void
    {
        $this->superAdmin = $superAdmin;
    }

    /** @return Collection<int, UserGroup> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function markLoggedIn(string $name): void
    {
        $this->name = $name;
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    public function getRoles(): array
    {
        return match (true) {
            $this->isSuperAdmin() => ['ROLE_USER', 'ROLE_SUPER_ADMIN'],
            $this->isActive() => ['ROLE_USER'],
            default => ['ROLE_PENDING'],
        };
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function setPassword(?string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }
}
