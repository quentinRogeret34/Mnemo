<?php

namespace App\Demo;

use App\Document\DocumentStorage;
use App\Memory\MemoryStore;
use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fictional business documents and rules, flagged with metadata "demo" so they can be removed in one go.
 */
final class DemoData
{
    private const DOCUMENTS = [
        'catalogue' => 'specification-catalogue-cotes.md',
        'communaute' => 'charte-communaute.md',
        'generation' => 'generation-parcours.md',
    ];

    /**
     * code => [title, statement, status, source document, confidence of the source link, implementing files with confidence].
     */
    private const RULES = [
        'RG-001' => ['Seuils de détection d\'une côte', 'Une portion de trace est une côte candidate si sa pente moyenne est d\'au moins 4 %, son dénivelé d\'au moins 40 m et sa longueur d\'au moins 300 m.', 'validated', 'catalogue', Confidence::Verified, [
            'apps/lab/api/src/domain/catalog.ts' => Confidence::Verified,
            'apps/lab/api/src/domain/hills.ts' => Confidence::Inferred,
        ]],
        'RG-002' => ['Fusion des traces d\'une même montée', 'Deux traces sont fusionnées en une seule côte si leurs départs et arrivées sont à moins de 50 m et que leur dénivelé diffère de moins de 10 %.', 'validated', 'catalogue', Confidence::Verified, [
            'apps/lab/api/src/domain/catalog.ts' => Confidence::Verified,
            'apps/gpx-lab/lib/catalogue.mjs' => Confidence::Inferred,
        ]],
        'RG-003' => ['Masquage après trois signalements', 'Un parcours ou un commentaire signalé par 3 utilisateurs distincts est masqué jusqu\'à décision de modération.', 'to_review', 'communaute', Confidence::Inferred, [
            'apps/lab/api/src/routes/moderation.ts' => Confidence::Inferred,
            'apps/lab/web/src/components/community/ReportDialog.svelte' => Confidence::Inferred,
        ]],
        'RG-004' => ['Suppression réservée à l\'auteur', 'Seul l\'auteur d\'un parcours, ou un modérateur, peut le modifier ou le supprimer.', 'validated', 'communaute', Confidence::Verified, [
            'apps/lab/api/src/routes/community.ts' => Confidence::Verified,
            'apps/lab/api/src/domain/community.ts' => Confidence::Inferred,
        ]],
        'RG-005' => ['Une note par utilisateur et par parcours', 'Une note va de 1 à 5 ; un utilisateur ne note qu\'une fois un parcours et une nouvelle note remplace l\'ancienne.', 'to_review', 'communaute', Confidence::Inferred, [
            'apps/lab/web/src/components/community/RatingPicker.svelte' => Confidence::Inferred,
            'apps/lab/api/src/domain/community.ts' => Confidence::Inferred,
        ]],
        'RG-006' => ['Une génération en cours par utilisateur', 'Une demande de génération est refusée tant qu\'une génération du même utilisateur est en cours.', 'to_review', 'generation', Confidence::Inferred, [
            'apps/lab/api/src/routes/generation.ts' => Confidence::Inferred,
            'apps/lab/api/src/workers/generation-worker.ts' => Confidence::Inferred,
        ]],
        'RG-007' => ['Expiration de session à 30 jours', 'Une session expire après 30 jours sans activité ; publier, noter ou commenter demande alors de se reconnecter.', 'validated', 'communaute', Confidence::Verified, [
            'apps/lab/api/src/middleware/auth.ts' => Confidence::Verified,
            'apps/lab/web/src/lib/session.svelte.ts' => Confidence::Inferred,
        ]],
    ];

    /**
     * [text, type, linked code paths, linked document keys, linked rule codes].
     */
    private const MEMORIES = [
        ['Les seuils de détection des côtes sont centralisés dans catalog.ts pour être ajustés sans redéployer le moteur.', MemoryType::Decision, ['apps/lab/api/src/domain/catalog.ts'], ['catalogue'], ['RG-001']],
        ['Fusionner deux traces avant de lisser l\'altitude crée des doublons dans le catalogue : toujours lisser d\'abord.', MemoryType::Learning, ['apps/lab/api/src/domain/catalog.ts', 'apps/gpx-lab/lib/catalogue.mjs'], [], ['RG-002']],
        ['Les composants communautaires Svelte passent tous par api.ts pour appeler le serveur, jamais par fetch directement.', MemoryType::Convention, ['apps/lab/web/src/lib/api.ts', 'apps/lab/web/src/components/community/Comments.svelte'], [], []],
        ['Une session expirée renvoie 401 depuis le middleware auth.ts ; le front redirige alors vers la connexion.', MemoryType::Fact, ['apps/lab/api/src/middleware/auth.ts', 'apps/lab/web/src/lib/session.svelte.ts'], ['communaute'], ['RG-007']],
        ['Le refus d\'une seconde génération en cours est couvert par charge.test.ts ; le relancer après toute modification du worker.', MemoryType::Learning, ['apps/lab/api/test/charge.test.ts', 'apps/lab/api/src/workers/generation-worker.ts'], ['generation'], ['RG-006']],
        ['Les messages d\'erreur montrés aux utilisateurs sont en français, sans jargon technique.', MemoryType::Preference, [], ['communaute'], []],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentStorage $documentStorage,
        private readonly MemoryStore $memoryStore,
        #[Autowire('%kernel.project_dir%/fixtures/demo')]
        private readonly string $fixturesDir,
    ) {
    }

    /**
     * @return array{documents: int, rules: int, memories: int, code_links: int, missing_files: list<string>}
     */
    public function load(Project $project): array
    {
        $this->clear($project);

        $documents = [];
        foreach (self::DOCUMENTS as $key => $file) {
            $documents[$key] = $this->documentStorage->store(null, new \SplFileInfo($this->fixturesDir.'/'.$file), $file, ['demo' => true]);
            $documents[$key]->addProject($project);
        }

        $nodeRepository = $this->entityManager->getRepository(Node::class);
        $codeLinks = 0;
        $missingFiles = [];

        foreach (self::RULES as $code => [$title, $statement, $status, $documentKey, $sourceConfidence, $files]) {
            $rule = new Node($project, NodeType::BusinessRule, $code, $title, null, null, [
                'statement' => $statement,
                'status' => $status,
                'demo' => true,
            ]);
            $this->entityManager->persist($rule);
            $this->entityManager->persist($this->link($documents[$documentKey], $rule, 'defines', $sourceConfidence));

            foreach ($files as $path => $confidence) {
                $file = $nodeRepository->findOneBy(['project' => $project, 'type' => NodeType::CodeFile, 'path' => $path]);
                if (null === $file) {
                    $missingFiles[] = $path;
                    continue;
                }
                $this->entityManager->persist($this->link($rule, $file, 'implemented_by', $confidence));
                ++$codeLinks;
            }
        }

        $memories = [];
        foreach (self::MEMORIES as [$text, $type]) {
            $memory = $this->memoryStore->add($text, $type, 'demo', ['demo' => true]);
            $memory->addProject($project);
            $memories[] = $memory;
        }
        $this->entityManager->flush();

        foreach (self::MEMORIES as $index => [, , $paths, $documentKeys, $ruleCodes]) {
            $targets = [
                ...array_map(fn (string $path): ?Node => $nodeRepository->findOneBy(['project' => $project, 'type' => NodeType::CodeFile, 'path' => $path]), $paths),
                ...array_map(static fn (string $key): Node => $documents[$key], $documentKeys),
                ...array_map(fn (string $code): ?Node => $nodeRepository->findOneBy(['project' => $project, 'type' => NodeType::BusinessRule, 'externalId' => $code]), $ruleCodes),
            ];
            foreach (array_filter($targets) as $target) {
                $this->memoryStore->link($memories[$index], $target);
            }
        }
        $this->entityManager->flush();

        return ['documents' => \count($documents), 'rules' => \count(self::RULES), 'memories' => \count($memories), 'code_links' => $codeLinks, 'missing_files' => array_values(array_unique($missingFiles))];
    }

    public function clear(Project $project): int
    {
        $removed = 0;
        $nodes = [
            ...$this->entityManager->getRepository(Node::class)->findBy(['project' => $project, 'type' => NodeType::BusinessRule]),
            ...array_filter(
                $this->entityManager->getRepository(Node::class)->findBy(['type' => [NodeType::Document, NodeType::Memory]]),
                static fn (Node $node): bool => $node->getProjects()->contains($project),
            ),
        ];

        foreach ($nodes as $node) {
            if (!($node->getMetadata()['demo'] ?? false)) {
                continue;
            }
            NodeType::Document === $node->getType() ? $this->documentStorage->delete($node) : $this->entityManager->remove($node);
            ++$removed;
        }

        $this->entityManager->flush();

        return $removed;
    }

    private function link(Node $source, Node $target, string $relation, Confidence $confidence): Edge
    {
        return new Edge($source, $target, $relation, $confidence, Confidence::Verified === $confidence ? EdgeOrigin::Human : EdgeOrigin::Llm, 'démo');
    }
}
