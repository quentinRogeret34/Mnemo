<?php

namespace App\Studio;

use App\Document\DocumentStorage;
use App\Entity\AgentAsset;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\AgentAssetKind;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Collects every project definition file (declarative project.yaml, AgentAssets and Markdown Documents)
 * for the Warp-style Studio file tree & editor.
 */
final class ProjectStudioLoader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentStorage $documentStorage,
    ) {
    }

    /**
     * @return array{
     *     definition: StudioFile,
     *     groups: array<string, list<StudioFile>>,
     *     all: array<string, StudioFile>,
     * }
     */
    public function load(Project $project): array
    {
        $assets = $this->entityManager->getRepository(AgentAsset::class)->findBy(
            ['project' => $project],
            ['kind' => 'ASC', 'name' => 'ASC'],
        );

        $definition = $this->buildProjectYaml($project, $assets);
        $groups = [
            'agents' => [],
            'skills' => [],
            'commands' => [],
            'documents' => [],
        ];
        $all = [$definition->ref => $definition];

        foreach ($assets as $asset) {
            $group = match ($asset->getKind()) {
                AgentAssetKind::Agent => 'agents',
                AgentAssetKind::Skill => 'skills',
                AgentAssetKind::Command => 'commands',
            };
            $ref = 'asset:'.$asset->getId();
            $file = new StudioFile(
                ref: $ref,
                group: $group,
                name: $asset->getName().'.md',
                path: $asset->path(),
                language: 'markdown',
                editable: true,
                kind: $asset->getKind()->value,
                entityId: $asset->getId(),
                description: $asset->getDescription(),
                content: $asset->getContent(),
                updatedAt: $asset->getUpdatedAt(),
            );
            $groups[$group][] = $file;
            $all[$ref] = $file;
        }

        /** @var list<Node> $documents */
        $documents = $this->entityManager->createQueryBuilder()
            ->select('n', 'f')
            ->from(Node::class, 'n')
            ->leftJoin('n.folder', 'f')
            ->innerJoin('n.projects', 'p')
            ->where('p = :project')
            ->andWhere('n.type = :type')
            ->setParameter('project', $project)
            ->setParameter('type', NodeType::Document)
            ->orderBy('n.label', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($documents as $document) {
            if ('md' !== ($document->getMetadata()['format'] ?? null)) {
                continue;
            }
            $storagePath = $this->documentStorage->absolutePath($document);
            $content = is_file($storagePath) ? (string) (@file_get_contents($storagePath) ?: '') : '';
            $folderName = $document->getFolder()?->getName();
            $filename = $document->getPath() ?: ($document->getLabel().'.md');
            $displayPath = null !== $folderName ? 'documents/'.$folderName.'/'.$filename : 'documents/'.$filename;
            $ref = 'doc:'.$document->getId();

            $file = new StudioFile(
                ref: $ref,
                group: 'documents',
                name: $document->getLabel(),
                path: $displayPath,
                language: 'markdown',
                editable: true,
                kind: 'document',
                entityId: $document->getId(),
                description: $folderName ? 'Dossier : '.$folderName : '',
                content: $content,
                updatedAt: $document->getUpdatedAt(),
            );
            $groups['documents'][] = $file;
            $all[$ref] = $file;
        }

        return [
            'definition' => $definition,
            'groups' => $groups,
            'all' => $all,
        ];
    }

    /**
     * @param list<AgentAsset> $assets
     */
    private function buildProjectYaml(Project $project, array $assets): StudioFile
    {
        $yaml = [
            'schemaVersion' => 'mnemo/v1',
            'project' => [
                'slug' => $project->getSlug(),
                'name' => $project->getName(),
                'repository' => $project->getRepository() ?: null,
                'repositoryPath' => $project->getRepositoryPath() ?: null,
                'archived' => $project->isArchived(),
            ],
            'models' => [
                'chat' => $project->getChatModel() ?: 'instance-default',
                'embedding' => $project->getEmbeddingModel() ?: 'instance-default',
            ],
            'automation' => [
                'autoGarden' => $project->isAutoGarden(),
                'webhookConfigured' => $project->hasWebhook(),
                'webhookFormat' => $project->getWebhookFormat()->value,
                'webhookEvents' => $project->getWebhookEvents(),
            ],
            'agentAssets' => array_map(
                static fn (AgentAsset $asset): array => [
                    'kind' => $asset->getKind()->value,
                    'name' => $asset->getName(),
                    'path' => $asset->path(),
                ],
                $assets,
            ),
        ];

        return new StudioFile(
            ref: 'project.yaml',
            group: 'root',
            name: 'project.yaml',
            path: 'project.yaml',
            language: 'yaml',
            editable: false,
            kind: 'definition',
            entityId: $project->getId(),
            description: 'Manifeste déclaratif en lecture seule (modifiable depuis Réglages du projet).',
            content: Yaml::dump($yaml, 4, 2),
            updatedAt: $project->getCreatedAt(),
        );
    }
}
