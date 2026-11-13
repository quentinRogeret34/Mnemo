<?php

namespace App\Twig\Components;

use App\Entity\Project;
use App\Project\ProjectResolver;
use App\Security\OAuth\OAuthClient;
use App\Security\ProjectScope;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ⌘K command palette: projects, pages and actions are rendered here and filtered in the browser,
 * memories and files come from the palette endpoint as the user types.
 */
#[AsTwigComponent]
final class Palette
{
    public ?Project $project = null;

    public function __construct(
        private readonly ProjectScope $projectScope,
        private readonly ProjectResolver $projectResolver,
        private readonly OAuthClient $oauthClient,
    ) {
    }

    /**
     * @return list<Project>
     */
    public function getProjects(): array
    {
        return $this->projectScope->projects();
    }

    public function isScoped(): bool
    {
        return null !== $this->projectScope->slugs();
    }

    public function mayCreate(): bool
    {
        return $this->projectResolver->mayCreate();
    }

    public function hasGithub(): bool
    {
        return isset($this->oauthClient->enabled()['github']);
    }
}
