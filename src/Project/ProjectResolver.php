<?php

namespace App\Project;

use App\Entity\Project;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Which project an agent client works on, from what it sends: its slug, else the remote of its repository, else the
 * project with the longest repository path containing its working directory (same machine only).
 * A project out of the caller's reach does not exist for it.
 */
final class ProjectResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectScope $projectScope,
        private readonly LockFactory $lockFactory,
        private readonly SluggerInterface $slugger,
    ) {
    }

    public function find(string $cwd, string $slug = '', string $repository = ''): ?Project
    {
        $projects = $this->entityManager->getRepository(Project::class);
        if ('' !== $slug) {
            return $this->reachable($projects->findOneBy(['slug' => $slug]));
        }

        $repository = $this->normalize($repository);
        if ('' !== $repository && null !== $project = $projects->findOneBy(['repository' => $repository])) {
            return $this->reachable($project);
        }

        $cwd = rtrim($cwd, '/');
        if ('' === $cwd) {
            return null;
        }

        $found = null;
        foreach ($projects->findAll() as $project) {
            $root = rtrim($project->getRepositoryPath(), '/');
            if ($this->projectScope->allows($project) && ('' !== $root && ($cwd === $root || str_starts_with($cwd, $root.'/'))) && \strlen($root) > \strlen((string) $found?->getRepositoryPath())) {
                $found = $project;
            }
        }

        return $found;
    }

    /**
     * An unknown project is created, named after the slug sent, else the repository, else the directory. It joins the
     * groups of the caller, who reaches it at once. A key limited to some projects creates nothing.
     *
     * @return array{0: ?Project, 1: bool} the project, and whether this call created it
     */
    public function findOrCreate(string $cwd, string $slug = '', string $repository = ''): array
    {
        $repository = $this->normalize($repository);
        $projects = $this->entityManager->getRepository(Project::class);
        $known = '' !== $repository && null !== $projects->findOneBy(['repository' => $repository]);
        if (null !== $project = $this->find($cwd, $slug, $repository)) {
            // Only a project named by its slug takes the repository: one found by its path may be a part of a monorepo.
            if ('' !== $slug && '' !== $repository && !$known && null === $project->getRepository()) {
                $project->setRepository($repository);
                $this->entityManager->flush();
            }

            return [$project, false];
        }
        if ($known || !$this->mayCreate()) {
            return [null, false];
        }

        $lock = $this->lockFactory->createLock('project-create');
        $lock->acquire(true);
        try {
            if (null !== $project = $this->find($cwd, $slug, $repository)) {
                return [$project, false];
            }
            foreach ($this->candidates($cwd, $slug, $repository) as $candidate) {
                if (null !== $projects->findOneBy(['slug' => $candidate])) {
                    continue;
                }
                $project = new Project($candidate, $candidate, mb_substr($cwd, 0, 1024));
                $project->setRepository('' === $repository ? null : $repository);
                $this->entityManager->persist($project);
                foreach ($this->projectScope->viewer()?->getGroups() ?? [] as $group) {
                    $group->getProjects()->add($project);
                }
                $this->entityManager->flush();

                return [$project, true];
            }
        } finally {
            $lock->release();
        }

        return [null, false];
    }

    private function reachable(?Project $project): ?Project
    {
        return null !== $project && $this->projectScope->allows($project) ? $project : null;
    }

    private function mayCreate(): bool
    {
        if (false === $this->projectScope->key()?->getProjects()->isEmpty()) {
            return false;
        }
        $viewer = $this->projectScope->viewer();

        return null === $viewer || $viewer->isSuperAdmin() || !$viewer->getGroups()->isEmpty();
    }

    /**
     * @return list<string> slugs to try, in order
     */
    private function candidates(string $cwd, string $slug, string $repository): array
    {
        if ('' !== $slug) {
            return $slug === $this->slug($slug) ? [$slug] : [];
        }
        if ('' !== $repository) {
            $segments = explode('/', $repository);
            $names = [$this->slug((string) end($segments)), $this->slug(implode('-', \array_slice($segments, -2)))];
        } else {
            $names = [$this->slug(basename($cwd))];
        }

        return array_values(array_unique(array_filter($names, static fn (string $name): bool => '' !== $name)));
    }

    private function slug(string $name): string
    {
        return $this->slugger->slug($name)->lower()->truncate(100)->toString();
    }

    private function normalize(string $repository): string
    {
        $repository = strtolower(trim($repository, " \t\n\r/"));

        return \strlen($repository) > 255 ? '' : (str_ends_with($repository, '.git') ? substr($repository, 0, -4) : $repository);
    }
}
