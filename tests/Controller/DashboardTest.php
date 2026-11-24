<?php

namespace App\Tests\Controller;

use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\NodeType;
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
        $connection->executeStatement('TRUNCATE project, node, edge, folder, mcp_call, proposal, user_preference RESTART IDENTITY CASCADE');

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

    public function testRoleSwitchAndCustomWidgetConfigurationPersistPerUser(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, mcp_call, proposal, user_preference RESTART IDENTITY CASCADE');

        $project = new Project('shop', 'Acme Shop', '/repos/shop');
        $entityManager->persist($project);
        $file = new Node($project, NodeType::CodeFile, 'src/Discount.php', 'Discount.php', 'src/Discount.php');
        $rule = new Node($project, NodeType::BusinessRule, 'RG-001', 'Remise max', null, null, [
            'statement' => 'Une remise ne dépasse pas 10 %.',
            'status' => 'validated',
            'shadow_areas' => [[
                'category' => 'edge_case',
                'gap' => 'Cumul avec les soldes non précisé.',
                'question' => 'La remise 10 % se cumule-t-elle avec les soldes ?',
                'severity' => 'blocker',
            ]],
        ]);
        $doc = new Node(null, NodeType::Document, 'doc-1', 'Politique de remises', 'remises.md', null, ['format' => 'md']);
        $doc->addProject($project);
        $entityManager->persist($file);
        $entityManager->persist($rule);
        $entityManager->persist($doc);
        $entityManager->flush();
        $entityManager->persist(new Edge($rule, $file, 'implemented_by', Confidence::Extracted, EdgeOrigin::Human));
        $entityManager->flush();

        // Switch to Business role via the role switcher form.
        $crawler = $client->request('GET', '/dashboard?period=7d');
        $client->submit($crawler->filter('.dash-role-btn[value="business"]')->form());
        self::assertResponseRedirects('/dashboard?period=7d');

        $businessCrawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('true', $businessCrawler->filter('.dash-role-btn[value="business"]')->attr('aria-pressed'));
        self::assertCount(1, $businessCrawler->filter('#panel-business-overview'));
        self::assertCount(1, $businessCrawler->filter('#panel-shadow-questions'));
        self::assertCount(1, $businessCrawler->filter('#panel-recent-documents'));
        self::assertCount(0, $businessCrawler->filter('#recent-title'));
        self::assertStringContainsString('La remise 10 % se cumule-t-elle avec les soldes ?', $businessCrawler->filter('.shadow-list')->text());
        self::assertStringContainsString('Politique de remises', $businessCrawler->filter('#panel-recent-documents')->closest('section')->text());

        // Customize widgets: hide shadow_questions and keep only business_overview + ai_quality.
        $customForm = $businessCrawler->filter('.dash-customize-form')->form();
        $token = (string) $customForm['_token']->getValue();
        $client->request('POST', '/dashboard/preferences?period=7d', [
            '_token' => $token,
            'mode' => 'custom',
            'role' => 'business',
            'widgets' => ['business_overview', 'ai_quality'],
        ]);
        self::assertResponseRedirects('/dashboard?period=7d');

        $customCrawler = $client->followRedirect();
        self::assertCount(1, $customCrawler->filter('#panel-business-overview'));
        self::assertCount(1, $customCrawler->filter('#quality-title'));
        self::assertCount(0, $customCrawler->filter('#panel-shadow-questions'));
        self::assertCount(0, $customCrawler->filter('#panel-recent-documents'));
        self::assertCount(0, $customCrawler->filter('#attention-title'));

        // Switch to Dev role: restores Dev defaults (MCP tools + recent calls visible, business panels hidden).
        $client->submit($customCrawler->filter('.dash-role-btn[value="dev"]')->form());
        $devCrawler = $client->followRedirect();
        self::assertSame('true', $devCrawler->filter('.dash-role-btn[value="dev"]')->attr('aria-pressed'));
        self::assertCount(1, $devCrawler->filter('#recent-title'));
        self::assertCount(0, $devCrawler->filter('#panel-business-overview'));
    }
}
