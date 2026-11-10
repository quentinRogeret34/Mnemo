<?php

namespace App\Controller;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Form\MemoryFormType;
use App\Graph\KnowledgeReader;
use App\Llm\LlmUnavailable;
use App\Memory\MemoryStore;
use App\Verify\MemoryVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class MemoryController extends AbstractController
{
    public function __construct(
        private readonly KnowledgeReader $knowledgeReader,
        private readonly MemoryStore $memoryStore,
        private readonly EntityManagerInterface $entityManager,
        private readonly MemoryVerifier $memoryVerifier,
    ) {
    }

    #[Route('/memories', name: 'memory_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        #[MapQueryParameter] ?string $project = null,
        #[MapQueryParameter] ?string $type = null,
        #[MapQueryParameter] string $q = '',
        #[MapQueryParameter] bool $archived = false,
    ): Response {
        $projectEntity = null === $project ? null : ($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $project]) ?? throw $this->createNotFoundException(\sprintf('Project "%s" not found.', $project)));
        $memoryType = null === $type ? null : MemoryType::tryFrom($type);

        $form = $this->createForm(MemoryFormType::class, ['type' => $memoryType ?? MemoryType::Decision]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $memory = $this->memoryStore->add($form->get('text')->getData(), $form->get('type')->getData(), 'manual');
            if (null !== $projectEntity) {
                $memory->addProject($projectEntity);
            }
            $this->entityManager->flush();

            return $this->redirectToRoute('memory_show', ['id' => $memory->getId()]);
        }

        return $this->render('memory/index.html.twig', [
            'project' => $projectEntity,
            'type' => $memoryType,
            'q' => trim($q),
            'types' => MemoryType::cases(),
            'archived' => $archived,
            'memories' => $this->knowledgeReader->memories($projectEntity, $memoryType?->value, $q, $archived),
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/memories/{id}', name: 'memory_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $memory = $this->findMemory($id);

        return $this->render('memory/show.html.twig', [
            'memory' => $memory,
            'supersession' => $this->knowledgeReader->supersession($memory),
        ]);
    }

    #[Route('/memories/{id}/delete', name: 'memory_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $memory = $this->findMemory($id);
        if (!$this->isCsrfTokenValid('delete-memory-'.$id, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $this->entityManager->remove($memory);
        $this->entityManager->flush();
        $this->addFlash('success', 'Mémoire supprimée.');

        return $this->redirectToRoute('memory_index');
    }

    #[Route('/memories/{id}/verify', name: 'memory_verify', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function verify(int $id, Request $request): Response
    {
        $memory = $this->findMemory($id);
        if (!$this->isCsrfTokenValid('verify-memory-'.$id, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $project = $memory->getProjects()->first();
        if (false === $project) {
            $this->addFlash('error', 'Rattacher la mémoire à un projet pour la vérifier contre son graphe.');

            return $this->redirectToRoute('memory_show', ['id' => $id]);
        }

        try {
            $verification = $this->memoryVerifier->verify($memory, $project);
            $this->addFlash('success', \sprintf('Vérification terminée : %s.', ['verified' => 'vérifiée', 'refuted' => 'réfutée', 'conflict' => 'sources en conflit', 'unverified' => 'non vérifiable'][$verification['verdict']]));
        } catch (LlmUnavailable|\UnexpectedValueException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('memory_show', ['id' => $id]);
    }

    private function findMemory(int $id): Node
    {
        return $this->entityManager->getRepository(Node::class)->findOneBy(['id' => $id, 'type' => NodeType::Memory])
            ?? throw $this->createNotFoundException(\sprintf('Memory %d not found.', $id));
    }
}
