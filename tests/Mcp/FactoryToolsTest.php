<?php

namespace App\Tests\Mcp;

use App\Entity\Project;
use App\Mcp\FactoryTools;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FactoryToolsTest extends KernelTestCase
{
    public function testMcpToolsTrackParentAndWorkerSessions(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE agent_session_event, agent_session, project RESTART IDENTITY CASCADE');

        $project = new Project('mnemo', 'Mnemo', '/tmp/mnemo');
        $em->persist($project);
        $em->flush();

        /** @var FactoryTools $tools */
        $tools = self::getContainer()->get(FactoryTools::class);

        $coordinator = $tools->startSession(
            project: 'mnemo',
            title: 'Ruche — refactor paiement',
            role: 'coordinator',
            goal: 'Coordonner 2 workers parallèles.',
            client: 'antigravity',
            branch: 'feat/payment-swarm',
            ruleCode: 'RG-042',
        );
        self::assertStringStartsWith('sess_', (string) $coordinator['session_id']);

        $worker = $tools->startSession(
            project: 'mnemo',
            title: 'Worker Backend Stripe',
            role: 'worker',
            parentSessionId: (string) $coordinator['session_id'],
            runner: 'claude_cli',
        );

        $logged = $tools->logSessionEvent(
            sessionId: (string) $worker['session_id'],
            kind: 'file_changed',
            message: 'Adapter Stripe implémenté.',
            files: ['src/Payment/StripeAdapter.php'],
        );
        self::assertSame(['src/Payment/StripeAdapter.php'], $logged['files_touched']);

        $ended = $tools->endSession(
            sessionId: (string) $worker['session_id'],
            status: 'succeeded',
            summary: 'Tests Stripe verts.',
        );
        self::assertSame('succeeded', $ended['status']);

        $listed = $tools->listSessions(project: 'mnemo', status: 'running');
        self::assertSame(1, $listed['count']);
        self::assertSame($coordinator['session_id'], $listed['sessions'][0]['session_id']);
    }
}
