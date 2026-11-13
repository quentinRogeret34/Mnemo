<?php

namespace App\Twig\Components;

use App\Form\ProjectType;
use App\Project\ProjectResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The "new project" dialog, on every page. A submission that fails comes back with its form, and the dialog open.
 */
#[AsTwigComponent]
final class ProjectDialog
{
    public ?FormView $form = null;

    public function __construct(
        private readonly ProjectResolver $projectResolver,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
    ) {
    }

    public function isAllowed(): bool
    {
        return $this->projectResolver->mayCreate();
    }

    public function getView(): FormView
    {
        return $this->form ?? $this->formFactory->createNamed('new_project', ProjectType::class, null, [
            'create' => true,
            'groups' => $this->security->isGranted('ROLE_SUPER_ADMIN'),
            'action' => $this->urlGenerator->generate('project_new'),
        ])->createView();
    }
}
