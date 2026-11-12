<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A team: its members reach the group's projects and share the memories limited to the group.
 */
#[ORM\Entity]
class UserGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'groups')]
    private Collection $users;

    /**
     * @var Collection<int, Project>
     */
    #[ORM\ManyToMany(targetEntity: Project::class)]
    private Collection $projects;

    public function __construct(
        #[ORM\Column(length: 100, unique: true)]
        private string $name,
    ) {
        $this->users = new ArrayCollection();
        $this->projects = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    /** @return Collection<int, Project> */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    /**
     * @param iterable<User> $users
     */
    public function setUsers(iterable $users): void
    {
        $this->users->clear();
        foreach ($users as $user) {
            $this->users->add($user);
        }
    }

    /**
     * @param iterable<Project> $projects
     */
    public function setProjects(iterable $projects): void
    {
        $this->projects->clear();
        foreach ($projects as $project) {
            $this->projects->add($project);
        }
    }
}
