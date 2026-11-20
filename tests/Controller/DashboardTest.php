<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\ProposalKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardTest extends WebTestCase
{
    public function testFiguresAreComparedWithThePreviousPeriodAndPendingWorkIsListed(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->executeStatement('TRUNCATE project, node, edge, folder, mcp_call, proposal RESTART IDENTITY CASCADE');

        $project = new Project('demo', 'Demo project', '/repos/demo');
        $entityManager->persist($project);
        $entityManager->persist(new Proposal($project, ProposalKind::Duplicate, ['a_id' => 1, 'a_text' => 'Les prix sont en centimes.', 'b_id' => 2, 'b_text' => 'Prix en centimes.', 'reason' => 'même fait']));
        $entityManager->flush();
        $connection->executeStatement("INSERT INTO mcp_call (created_at, tool, results, memories, failed, duration_ms) SELECT NOW() - make_interval(hours => i), 'search', 1, 2, false, 5 FROM generate_series(1, 3) AS i");
        $connection->executeStatement("INSERT INTO mcp_call (created_at, tool, results, memories, failed, duration_ms) SELECT NOW() - make_interval(days => 8, hours => i), 'search', 1, 0, false, 5 FROM generate_series(1, 2) AS i");

        $crawler = $client->request('GET', '/dashboard?period=7d');

        self::assertResponseIsSuccessful();
        $requests = $crawler->filter('.kpi')->eq(2);
        self::assertSame('3', $requests->filter('.kpi__value')->text());
        self::assertStringContainsString('+50,0 %', $requests->filter('.delta--up')->text());
        self::assertSame('6', $crawler->filter('.kpi')->eq(1)->filter('.kpi__value')->text());
        self::assertSelectorTextContains('.attention__item', 'Doublon');
        self::assertSelectorTextContains('.attention__item', 'Les prix sont en centimes.');
        self::assertCount(1, $crawler->filter('.segments__part--duplicate'));

        $series = json_decode((string) $crawler->filter('[data-controller="chart"]')->first()->attr('data-chart-series-value'), true);
        self::assertCount(2, $series);
        self::assertSame(6, array_sum(array_column($series[1]['points'], 'value')));
    }
}
