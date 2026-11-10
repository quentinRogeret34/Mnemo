<?php

namespace App\Tests\Graph;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\NodeType;
use App\Graph\Capability;
use App\Graph\CapabilityDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CapabilityDetectorTest extends WebTestCase
{
    public function testDetectsCapabilitiesFromCodePathsWithEvidence(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');
        $project = new Project('demo', 'Demo project', '/repos/demo');
        $entityManager->persist($project);
        foreach (['src/Controller/CartController.php', 'src/Entity/Cart.php', 'migrations/Version1.php', 'src/Command/ImportCommand.php', 'src/Service/Pricing.php', 'templates/cart.html.twig'] as $path) {
            $entityManager->persist(new Node($project, NodeType::CodeFile, $path, basename($path), $path));
        }
        $entityManager->flush();

        $detected = self::getContainer()->get(CapabilityDetector::class)->detect($project);

        self::assertSame([Capability::Api, Capability::Ui, Capability::Database, Capability::Cli], array_column($detected, 'capability'));
        self::assertSame(['migrations/Version1.php', 'src/Entity/Cart.php'], $detected[2]['evidence']);

        $crawler = $client->request('GET', '/projects/demo/code');
        self::assertSame(['API HTTP', 'Interface', 'Base de données', 'Ligne de commande'], $crawler->filter('.capabilities .tag')->each(static fn ($node): string => $node->text()));
    }
}
