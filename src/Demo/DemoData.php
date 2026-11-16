<?php

namespace App\Demo;

use App\Document\DocumentStorage;
use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Enum\ProposalKind;
use App\Ingest\GraphifyImporter;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fictional business documents, rules, memories and proposals of an online shop, flagged with "demo" so they can be
 * removed in one go. The matching code graph is only imported into a project the demo creates.
 */
final class DemoData
{
    public const PROJECT = 'acme-shop';

    private const DOCUMENTS = [
        'discounts' => 'discount-policy.md',
        'approval' => 'order-approval.md',
        'returns' => 'returns-and-refunds.md',
    ];

    /**
     * code => [title, statement, status, source document, confidence of the source link, implementing files with confidence].
     */
    private const RULES = [
        'RG-001' => ['Discount cap', 'The total discount on an order never exceeds 10 % of the order subtotal, whatever the combination of promo code and loyalty bonus.', 'validated', 'discounts', Confidence::Verified, [
            'src/Pricing/DiscountPolicy.php' => Confidence::Verified,
            'src/Pricing/DiscountCalculator.php' => Confidence::Verified,
        ]],
        'RG-002' => ['One promo code per order', 'An order carries one promo code at most; entering a second code replaces the first one.', 'validated', 'discounts', Confidence::Verified, [
            'src/Pricing/DiscountCalculator.php' => Confidence::Verified,
            'src/Pricing/PromoCode.php' => Confidence::Inferred,
        ]],
        'RG-003' => ['Gold loyalty bonus', 'Gold customers get an extra 2 % on every order, added to the promo code and capped with it.', 'to_review', 'discounts', Confidence::Inferred, [
            'src/Customer/LoyaltyTier.php' => Confidence::Inferred,
            'src/Pricing/DiscountCalculator.php' => Confidence::Inferred,
        ]],
        'RG-004' => ['Approval above €500', 'An order over €500, taxes included, waits for the approval of a manager of the customer\'s company; an order of exactly €500 does not.', 'validated', 'approval', Confidence::Verified, [
            'src/Order/OrderApproval.php' => Confidence::Verified,
            'src/Order/OrderController.php' => Confidence::Inferred,
        ]],
        'RG-005' => ['Approval expires after 48 hours', 'An approval request expires after 48 hours; the order is then cancelled, nothing is charged and the customer is told by email.', 'to_review', 'approval', Confidence::Inferred, [
            'src/Order/ApprovalExpiryHandler.php' => Confidence::Verified,
            'src/Notification/ApprovalMailer.php' => Confidence::Inferred,
        ]],
        'RG-006' => ['30-day return window', 'An item can be returned within 30 days of delivery, except final sale items.', 'validated', 'returns', Confidence::Verified, [
            'src/Return/ReturnPolicy.php' => Confidence::Verified,
        ]],
        'RG-007' => ['Refund at the paid price', 'A returned item is refunded within 14 days of reception, on the original payment method, at the price the customer actually paid.', 'to_review', 'returns', Confidence::Inferred, [
            'src/Return/RefundService.php' => Confidence::Verified,
            'src/Payment/PaymentGateway.php' => Confidence::Inferred,
        ]],
    ];

    /**
     * [text, type, linked code paths, linked document keys, linked rule codes].
     */
    private const MEMORIES = [
        ['The discount cap lives in DiscountPolicy::MAX_RATE so that marketing can change it without touching the calculator.', MemoryType::Decision, ['src/Pricing/DiscountPolicy.php'], ['discounts'], ['RG-001']],
        ['Applying the loyalty bonus after the cap let gold customers reach 12 %: DiscountCalculator always caps last.', MemoryType::Learning, ['src/Pricing/DiscountCalculator.php', 'src/Customer/LoyaltyTier.php'], [], ['RG-001', 'RG-003']],
        ['Every money amount is an integer number of cents wrapped in the Money value object, never a float.', MemoryType::Convention, ['src/Pricing/Money.php', 'src/Return/RefundService.php'], [], []],
        ['Approval requests expire through a delayed Messenger message handled by ApprovalExpiryHandler, not through a cron job.', MemoryType::Fact, ['src/Order/ApprovalExpiryHandler.php', 'src/Order/OrderApproval.php'], ['approval'], ['RG-005']],
        ['OrderApprovalTest covers the €500 edge case (exactly €500 needs no approval): run it after any change to OrderApproval.', MemoryType::Learning, ['tests/Order/OrderApprovalTest.php', 'src/Order/OrderApproval.php'], [], ['RG-004']],
        ['Error messages shown to customers stay short and never mention internal codes.', MemoryType::Preference, [], ['returns'], []],
        ['Refunds go back to the original payment method only: store credit was ruled out for accounting reasons.', MemoryType::Decision, ['src/Return/RefundService.php'], ['returns'], ['RG-007']],
        ['Prices are stored in cents to avoid float rounding errors.', MemoryType::Fact, ['src/Pricing/Money.php'], [], []],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentStorage $documentStorage,
        private readonly MemoryStore $memoryStore,
        private readonly GraphifyImporter $graphifyImporter,
        #[Autowire('%kernel.project_dir%/fixtures/demo')]
        private readonly string $fixturesDir,
    ) {
    }

    public function createProject(): Project
    {
        $project = new Project(self::PROJECT, 'Acme Shop', '');
        $project->setRepository('github.com/acme/shop');
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        $this->graphifyImporter->import($project, $this->fixturesDir.'/graph.json');

        return $project;
    }

    /**
     * @return array{documents: int, rules: int, memories: int, proposals: int, code_links: int, missing_files: list<string>}
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

        $proposals = [
            new Proposal($project, ProposalKind::Learning, [
                'lesson' => 'A partially returned order is refunded at the price paid per item: the order discount is spread across its lines first.',
                'evidence' => 'We refund the paid price, so the discount has to be spread across the lines before computing a partial refund.',
                'type' => MemoryType::Learning->value,
                'score' => 8.3,
                'axes' => ['durability' => 9, 'reuse' => 8, 'project_fit' => 9, 'risk' => 7],
                'files' => ['src/Return/RefundService.php'],
                'rules' => ['RG-007'],
                'adr' => [],
                'reconciliation' => 'new',
                'target_id' => null,
                'target_text' => null,
                'reason' => '',
                'source' => ['kind' => 'review', 'label' => 'PR #42 · Partial refunds'],
                'demo' => true,
            ]),
            new Proposal($project, ProposalKind::Duplicate, [
                'a_id' => $memories[2]->getId(),
                'a_text' => $memories[2]->getLabel(),
                'b_id' => $memories[7]->getId(),
                'b_text' => $memories[7]->getLabel(),
                'reason' => 'Both say that money amounts are stored as integer cents; the first one also names the Money value object.',
                'demo' => true,
            ]),
        ];
        foreach ($proposals as $proposal) {
            $this->entityManager->persist($proposal);
        }
        $this->entityManager->flush();

        return ['documents' => \count($documents), 'rules' => \count(self::RULES), 'memories' => \count($memories), 'proposals' => \count($proposals), 'code_links' => $codeLinks, 'missing_files' => array_values(array_unique($missingFiles))];
    }

    public function clear(Project $project): int
    {
        $removed = 0;
        $nodes = [
            ...$this->entityManager->getRepository(Node::class)->findBy(['project' => $project, 'type' => NodeType::BusinessRule]),
            ...$this->entityManager->createQuery('SELECT n FROM App\Entity\Node n JOIN n.projects p WHERE n.type IN (:types) AND p = :project')
                ->setParameters(['types' => [NodeType::Document, NodeType::Memory], 'project' => $project])
                ->getResult(),
        ];

        foreach ($nodes as $node) {
            if (!($node->getMetadata()['demo'] ?? false)) {
                continue;
            }
            NodeType::Document === $node->getType() ? $this->documentStorage->delete($node) : $this->entityManager->remove($node);
            ++$removed;
        }
        foreach ($this->entityManager->getRepository(Proposal::class)->findBy(['project' => $project]) as $proposal) {
            if ($proposal->getPayload()['demo'] ?? false) {
                $this->entityManager->remove($proposal);
                ++$removed;
            }
        }

        $this->entityManager->flush();

        return $removed;
    }

    private function link(Node $source, Node $target, string $relation, Confidence $confidence): Edge
    {
        return new Edge($source, $target, $relation, $confidence, Confidence::Verified === $confidence ? EdgeOrigin::Human : EdgeOrigin::Llm, 'demo');
    }
}
