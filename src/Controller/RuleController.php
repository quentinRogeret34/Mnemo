<?php

namespace App\Controller;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\NodeType;
use App\Graph\KnowledgeReader;
use App\Llm\LlmUnavailable;
use App\Pagination\Page;
use App\Rule\ShadowAreaDetector;
use App\Rule\ShadowCategory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

final class RuleController extends AbstractController
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly KnowledgeReader $knowledgeReader,
        private readonly EntityManagerInterface $entityManager,
        private readonly ShadowAreaDetector $shadowAreaDetector,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/projects/{slug}/rules', name: 'rule_index', methods: ['GET'])]
    public function index(
        #[MapEntity(mapping: ['slug' => 'slug'])] Project $project,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, options: ['regexp' => '/^(validated|to_review)$/'])] ?string $status = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $page = max(1, $page);

        return $this->render('rule/index.html.twig', [
            'project' => $project,
            'status' => $status,
            'rules' => Page::ofRows($this->knowledgeReader->rules($project, $status, self::PAGE_SIZE, Page::offset($page, self::PAGE_SIZE)), $page, self::PAGE_SIZE),
        ]);
    }

    #[Route('/projects/{slug}/rules/{code}', name: 'rule_show', methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, string $code): Response
    {
        $rule = $this->findRule($project, $code);

        return $this->render('rule/show.html.twig', [
            'project' => $project,
            'rule' => $rule,
            'categories' => array_column(ShadowCategory::cases(), null, 'value'),
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
            $this->addFlash('success', new TranslatableMessage('flash.rule.shadow_areas', ['count' => \count($areas)]));
        } catch (LlmUnavailable|\UnexpectedValueException $exception) {
            $this->addFlash('error', $exception instanceof LlmUnavailable ? $exception->translatable() : $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error('Shadow area analysis failed for rule {code}: {message}', ['code' => $code, 'message' => $exception->getMessage(), 'exception' => $exception]);
            $this->addFlash('error', new TranslatableMessage('flash.llm.error'));
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

    #[Route('/projects/{slug}/rules/{code}/status', name: 'rule_status', methods: ['POST'])]
    public function status(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, string $code, Request $request): Response
    {
        $rule = $this->findRule($project, $code);
        $this->checkToken($rule, $request);
        $validated = 'validated' === $request->getPayload()->getString('status');
        $rule->update($rule->getLabel(), $rule->getPath(), $rule->getLine(), ['status' => $validated ? 'validated' : 'to_review'] + $rule->getMetadata());
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage($validated ? 'flash.rule.validated' : 'flash.rule.unvalidated'));

        return $this->redirectToRoute('rule_show', ['slug' => $project->getSlug(), 'code' => $code]);
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
