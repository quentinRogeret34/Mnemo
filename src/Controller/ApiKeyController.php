<?php

namespace App\Controller;

use App\Entity\ApiKey;
use App\Entity\Project;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

final class ApiKeyController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectScope $projectScope,
    ) {
    }

    /**
     * A user manages their own keys; a super admin, or a server without login, manages them all.
     */
    private function ownKeysOnly(): bool
    {
        $viewer = $this->projectScope->viewer();

        return null !== $viewer && !$viewer->isSuperAdmin();
    }

    #[Route('/settings/keys', name: 'api_key_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $token = null;
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('api-key-create', $request->getPayload()->getString('_token'))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            $name = trim($request->getPayload()->getString('name'));
            if ('' === $name || mb_strlen($name) > 100) {
                $error = 'Donner un nom à la clé (100 caractères au plus).';
            } else {
                [$key, $token] = ApiKey::generate($name);
                $key->setOwner($this->projectScope->viewer());
                $slugs = $request->getPayload()->all('projects');
                foreach (array_filter($this->projectScope->projects(), static fn (Project $project): bool => \in_array($project->getSlug(), $slugs, true)) as $project) {
                    $key->addProject($project);
                }
                $this->entityManager->persist($key);
                $this->entityManager->flush();
            }
        }

        return $this->render('settings/keys.html.twig', [
            'keys' => $this->entityManager->getRepository(ApiKey::class)->findBy($this->ownKeysOnly() ? ['owner' => $this->projectScope->viewer()] : [], ['createdAt' => 'DESC']),
            'projects' => $this->projectScope->projects(),
            'token' => $token,
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/settings/keys/{id}/revoke', name: 'api_key_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(ApiKey $key, Request $request): Response
    {
        if ($this->ownKeysOnly() && $key->getOwner() !== $this->projectScope->viewer()) {
            throw $this->createNotFoundException('Key not found.');
        }
        if (!$this->isCsrfTokenValid('api-key-revoke-'.$key->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $key->revoke();
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('flash.api_key.revoked', ['name' => $key->getName()]));

        return $this->redirectToRoute('api_key_index');
    }
}
