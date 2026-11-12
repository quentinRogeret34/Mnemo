<?php

namespace App\Controller;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\NodeType;
use App\Graph\KnowledgeReader;
use App\Llm\LlmUnavailable;
use App\Rule\ShadowAreaDetector;
use App\Rule\ShadowCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class RuleController extends AbstractController
{
    public function __construct(
        private readonly KnowledgeReader $knowledgeReader,
        private readonly EntityManagerInterface $entityManager,
        private readonly ShadowAreaDetector $shadowAreaDetector,
    ) {
    }

    #[Route('/projects/{slug}/rules', name: 'rule_index', methods: ['GET'])]
    public function index(
        #[MapEntity(mapping: ['slug' => 'slug'])] Project $project,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, options: ['regexp' => '/^(validated|to_review)$/'])] ?string $status = null,
    ): Response {
        return $this->render('rule/index.html.twig', [
            'project' => $project,
            'status' => $status,
            'rules' => $this->knowledgeReader->rules($project, $status),
        ]);
    }

    #[Route('/projects/{slug}/rules/{code}', name: 'rule_show', methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, string $code): Response
    {
        $rule = $this->findRule($project, $code);

        return $this->render('rule/show.html.twig', [
            'project' => $project,
            'rule' => $rule,
            'categories' => array_combine(array_column(ShadowCategory::cases(), 'value'), array_map(static fn (ShadowCategory $category): string => $category->label(), ShadowCategory::cases())),
            'sources' => $this->knowledgeReader->ruleSources($rule),
            'mentions' => $this->knowledgeReader->ruleMentions($rule),
            'implementations' => $this->knowledgeReader->ruleImplementations($rule),
            'memories' => $this->knowledgeReader->memoriesAbout($rule->getId()),
        ]);
    }

    #[Route('/projects/{slug}/rules/{code}/shadow', name: 'rule_shadow', methods: ['POST'])]
    public function analyzeShadowAreas(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, string $code, Request $request): Response
    {
        $rule = $this->findRule($project, $code);
        $this->checkToken($rule, $request);

        try {
            $areas = $this->shadowAreaDetector->analyze($rule);
            $this->addFlash('success', [] === $areas ? 'Aucune zone d\'ombre trouvée.' : \sprintf('%d zones d\'ombre trouvées.', \count($areas)));
        } catch (LlmUnavailable|\UnexpectedValueException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('rule_show', ['slug' => $project->getSlug(), 'code' => $code, '_fragment' => 'shadow-title']);
    }

    #[Route('/projects/{slug}/rules/{code}/shadow/{index}/resolve', name: 'rule_shadow_resolve', requirements: ['index' => '\d+'], methods: ['POST'])]
    public function resolveShadowArea(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, string $code, int $index, Request $request): Response
    {
        $rule = $this->findRule($project, $code);
        $this->checkToken($rule, $request);
        $this->shadowAreaDetector->resolve($rule, $index);

        return $this->redirectToRoute('rule_show', ['slug' => $project->getSlug(), 'code' => $code, '_fragment' => 'shadow-title']);
    }

    private function findRule(Project $project, string $code): Node
    {
        return $this->entityManager->getRepository(Node::class)->findOneBy(['project' => $project, 'type' => NodeType::BusinessRule, 'externalId' => $code])
            ?? throw $this->createNotFoundException(\sprintf('Rule "%s" not found.', $code));
    }

    private function checkToken(Node $rule, Request $request): void
    {
        if (!$this->isCsrfTokenValid('rule-shadow-'.$rule->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
