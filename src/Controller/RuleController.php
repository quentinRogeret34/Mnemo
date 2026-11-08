<?php

namespace App\Controller;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\NodeType;
use App\Graph\KnowledgeReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class RuleController extends AbstractController
{
    public function __construct(
        private readonly KnowledgeReader $knowledgeReader,
        private readonly EntityManagerInterface $entityManager,
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
        $rule = $this->entityManager->getRepository(Node::class)->findOneBy(['project' => $project, 'type' => NodeType::BusinessRule, 'externalId' => $code])
            ?? throw $this->createNotFoundException(\sprintf('Rule "%s" not found.', $code));

        return $this->render('rule/show.html.twig', [
            'project' => $project,
            'rule' => $rule,
            'sources' => $this->knowledgeReader->ruleSources($rule),
            'implementations' => $this->knowledgeReader->ruleImplementations($rule),
            'memories' => $this->knowledgeReader->memoriesAbout($rule->getId()),
        ]);
    }
}
