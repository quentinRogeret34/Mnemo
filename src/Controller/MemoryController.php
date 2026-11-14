<?php

namespace App\Controller;

use App\Entity\Node;
use App\Entity\Project;
use App\Entity\UserGroup;
use App\Enum\MemoryType;
use App\Enum\MemoryVisibility;
use App\Enum\NodeType;
use App\Form\MemoryFormType;
use App\Graph\KnowledgeReader;
use App\Graph\MemoryGalaxy;
use App\Llm\LlmUnavailable;
use App\Memory\MemoryStore;
use App\Pagination\Page;
use App\Security\MemoryAccess;
use App\Security\ProjectScope;
use App\Verify\MemoryVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class MemoryController extends AbstractController
{
    private const PAGE_SIZE = 50;
    private const VERDICTS = ['verified', 'refuted', 'conflict', 'unverified', 'none'];

    public function __construct(
        private readonly KnowledgeReader $knowledgeReader,
        private readonly MemoryStore $memoryStore,
        private readonly EntityManagerInterface $entityManager,
        private readonly MemoryVerifier $memoryVerifier,
        private readonly MemoryGalaxy $memoryGalaxy,
        private readonly ProjectScope $projectScope,
        private readonly MemoryAccess $memoryAccess,
    ) {
    }

    #[Route('/memories', name: 'memory_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        #[MapQueryParameter] ?string $project = null,
        #[MapQueryParameter] ?string $type = null,
        #[MapQueryParameter] string $q = '',
        #[MapQueryParameter] bool $archived = false,
        #[MapQueryParameter] ?string $verdict = null,
        #[MapQueryParameter] ?string $source = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $page = max(1, $page);
        $projectEntity = $this->findProject($project);
        $verdict = \in_array($verdict, self::VERDICTS, true) ? $verdict : null;
        $source = '' === (string) $source ? null : $source;
        $memoryType = null === $type ? null : MemoryType::tryFrom($type);

        $form = $this->createForm(MemoryFormType::class, ['type' => $memoryType ?? MemoryType::Decision, 'visibility' => MemoryVisibility::Project->value], ['visibilities' => $this->visibilities()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $visibility = (string) $form->get('visibility')->getData();
            // The field only offers what the caller may choose, so metadataFor() cannot refuse it.
            $memory = $this->memoryStore->add($form->get('text')->getData(), $form->get('type')->getData(), 'manual', $this->memoryAccess->metadataFor(
                str_starts_with($visibility, 'group:') ? MemoryVisibility::Group : (MemoryVisibility::tryFrom($visibility) ?? MemoryVisibility::Project),
                str_starts_with($visibility, 'group:') ? (int) substr($visibility, 6) : null,
            ));
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
            'verdict' => $verdict,
            'source' => $source,
            'sources' => $this->knowledgeReader->memorySources(),
            'projects' => $this->projectScope->projects(),
            'memories' => Page::ofRows($this->knowledgeReader->memories($projectEntity, $memoryType?->value, $q, $archived, $verdict, $source, self::PAGE_SIZE, Page::offset($page, self::PAGE_SIZE)), $page, self::PAGE_SIZE),
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/memories/graph', name: 'memory_graph', methods: ['GET'])]
    public function graph(#[MapQueryParameter] ?string $project = null): Response
    {
        return $this->render('memory/graph.html.twig', ['project' => $this->findProject($project)]);
    }

    #[Route('/memories/graph/data', name: 'memory_graph_data', methods: ['GET'], format: 'json')]
    public function graphData(#[MapQueryParameter] ?string $project = null): JsonResponse
    {
        return $this->json($this->memoryGalaxy->build($this->findProject($project)));
    }

    #[Route('/memories/{id}', name: 'memory_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $memory = $this->findMemory($id);

        return $this->render('memory/show.html.twig', [
            'memory' => $memory,
            'visibilities' => $this->visibilities(),
            'group' => isset($memory->getMetadata()['group_id']) ? $this->entityManager->find(UserGroup::class, $memory->getMetadata()['group_id']) : null,
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

    #[Route('/memories/{id}/visibility', name: 'memory_visibility', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function visibility(int $id, Request $request): Response
    {
        $memory = $this->findMemory($id);
        if (!$this->isCsrfTokenValid('visibility-memory-'.$id, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $viewer = $this->projectScope->viewer();
        $owner = $memory->getMetadata()['owner_id'] ?? null;
        if (null !== $viewer && !$viewer->isSuperAdmin() && $owner !== $viewer->getId()) {
            $this->addFlash('error', 'Seul l\'auteur de la mémoire ou un super admin change sa visibilité.');

            return $this->redirectToRoute('memory_show', ['id' => $id]);
        }

        $choice = $request->getPayload()->getString('visibility');
        try {
            $metadata = $this->memoryAccess->metadataFor(
                str_starts_with($choice, 'group:') ? MemoryVisibility::Group : (MemoryVisibility::tryFrom($choice) ?? MemoryVisibility::Project),
                str_starts_with($choice, 'group:') ? (int) substr($choice, 6) : null,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('memory_show', ['id' => $id]);
        }
        $memory->annotate(['owner_id' => $owner ?? ($metadata['owner_id'] ?? null)] + $metadata + array_diff_key($memory->getMetadata(), ['visibility' => 1, 'group_id' => 1, 'owner_id' => 1]));
        $this->entityManager->flush();
        $this->addFlash('success', 'Visibilité modifiée.');

        return $this->redirectToRoute('memory_show', ['id' => $id]);
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

    private function findProject(?string $slug): ?Project
    {
        return $this->projectScope->bySlug($slug);
    }

    /**
     * Choices of the visibility field: project, the caller's groups, and private when there is an author.
     *
     * @return array<string, string> label => value
     */
    private function visibilities(): array
    {
        $viewer = $this->projectScope->viewer();
        $groups = null === $viewer || $viewer->isSuperAdmin() ? $this->entityManager->getRepository(UserGroup::class)->findBy([], ['name' => 'ASC']) : $viewer->getGroups()->getValues();
        $choices = ['Visible par le projet' => MemoryVisibility::Project->value];
        foreach ($groups as $group) {
            $choices['Groupe '.$group->getName()] = 'group:'.$group->getId();
        }

        return $choices + (null === $viewer ? [] : ['Privée (moi seul)' => MemoryVisibility::Personal->value]);
    }

    private function findMemory(int $id): Node
    {
        $memory = $this->entityManager->getRepository(Node::class)->findOneBy(['id' => $id, 'type' => NodeType::Memory]);

        return null !== $memory && $this->memoryAccess->canSee($memory) ? $memory : throw $this->createNotFoundException(\sprintf('Memory %d not found.', $id));
    }
}
