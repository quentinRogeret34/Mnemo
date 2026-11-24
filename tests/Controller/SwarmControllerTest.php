<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\SwarmRun;
use App\Enum\SwarmStatus;
use App\Mcp\FactoryTools;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SwarmControllerTest extends WebTestCase
{
    public function testFullSwarmLifecycleFromLaunchToReviewAndMcpBoard(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE swarm_message, swarm_run, agent_session_event, agent_session, user_preference, project RESTART IDENTITY CASCADE');

        $project = new Project('shop', 'Boutique', '/tmp/no-git-shop');
        $em->persist($project);
        $em->flush();

        // 1. Open Factory page and launch a pipeline swarm with internal LLM runner (sync plan)
        $crawler = $client->request('GET', '/projects/shop/factory');
        self::assertResponseIsSuccessful();
        $launchToken = (string) $crawler->filter('#factory-new-swarm input[name="_token"]')->attr('value');

        $client->request('POST', '/projects/shop/factory/swarms', [
            '_token' => $launchToken,
            'title' => 'Refonte remise panier RG-020',
            'goal' => 'Implémenter la remise par palier et couvrir tous les cas limites.',
            'strategy' => 'pipeline',
            'worker_runner' => 'internal_llm',
            'rule_code' => 'RG-020',
            'require_plan_approval' => '1',
            'run_sync' => '1',
        ]);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Refonte remise panier RG-020');

        $swarm = $em->getRepository(SwarmRun::class)->findOneBy(['title' => 'Refonte remise panier RG-020']);
        self::assertNotNull($swarm);
        self::assertSame(SwarmStatus::AwaitingApproval, $swarm->getStatus());
        self::assertCount(2, $swarm->getPlan());

        // 2. Post a human guidance message on the shared board
        $msgToken = (string) $crawler->filter('form[action$="/messages"] input[name="_token"]')->attr('value');
        $client->request('POST', '/factory/swarms/'.$swarm->getPublicId().'/messages', [
            '_token' => $msgToken,
            'body' => 'Pensez à plafonner la remise cumulée à 15 %.',
        ]);
        self::assertResponseRedirects('/factory/swarms/'.$swarm->getPublicId());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.factory-timeline', 'Pensez à plafonner la remise cumulée à 15 %.');

        // 3. Verify MCP swarm board tools read & write cleanly
        /** @var FactoryTools $mcpTools */
        $mcpTools = self::getContainer()->get(FactoryTools::class);
        $posted = $mcpTools->postSwarmMessage($swarm->getPublicId(), 'Contrainte 15 % bien prise en compte.', 'worker-1', 'progress');
        self::assertSame($swarm->getPublicId(), $posted['swarm_id']);
        $board = $mcpTools->getSwarmBoard($swarm->getPublicId());
        self::assertSame('awaiting_approval', $board['status']);
        self::assertGreaterThanOrEqual(4, \count($board['messages']));

        // 4. Approve plan and execute all workers + review synchronously
        $approveToken = (string) $crawler->filter('form[action$="/approve"] input[name="_token"]')->attr('value');
        $client->request('POST', '/factory/swarms/'.$swarm->getPublicId().'/approve', [
            '_token' => $approveToken,
            'run_sync' => '1',
        ]);
        self::assertResponseRedirects('/factory/swarms/'.$swarm->getPublicId());
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.callout--info', 'Synthèse automatique');

        $em->clear();
        $completed = $em->getRepository(SwarmRun::class)->findOneBy(['publicId' => $swarm->getPublicId()]);
        self::assertNotNull($completed);
        self::assertSame(SwarmStatus::Completed, $completed->getStatus());
        self::assertNotNull($completed->getFinishedAt());
    }

    public function testSwarmCanBePausedAndCancelled(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE swarm_message, swarm_run, agent_session_event, agent_session, user_preference, project RESTART IDENTITY CASCADE');

        $project = new Project('ops', 'Ops', '/tmp/ops');
        $em->persist($project);
        $em->flush();

        $crawler = $client->request('GET', '/projects/ops/factory');
        $launchToken = (string) $crawler->filter('#factory-new-swarm input[name="_token"]')->attr('value');

        $client->request('POST', '/projects/ops/factory/swarms', [
            '_token' => $launchToken,
            'title' => 'Audit sécurité',
            'goal' => 'Vérifier les headers HTTP.',
            'strategy' => 'parallel',
            'worker_runner' => 'internal_llm',
            'require_plan_approval' => '1',
        ]);
        $crawler = $client->followRedirect();
        $swarm = $em->getRepository(SwarmRun::class)->findOneBy(['title' => 'Audit sécurité']);
        self::assertNotNull($swarm);

        $controlToken = (string) $crawler->filter('form[action$="/control"] input[name="_token"]')->first()->attr('value');
        $client->request('POST', '/factory/swarms/'.$swarm->getPublicId().'/control', [
            '_token' => $controlToken,
            'action' => 'pause',
        ]);
        $em->clear();
        self::assertSame(SwarmStatus::Paused, $em->getRepository(SwarmRun::class)->findOneBy(['publicId' => $swarm->getPublicId()])?->getStatus());

        $client->request('POST', '/factory/swarms/'.$swarm->getPublicId().'/control', [
            '_token' => $controlToken,
            'action' => 'cancel',
        ]);
        $em->clear();
        self::assertSame(SwarmStatus::Cancelled, $em->getRepository(SwarmRun::class)->findOneBy(['publicId' => $swarm->getPublicId()])?->getStatus());
    }
}
