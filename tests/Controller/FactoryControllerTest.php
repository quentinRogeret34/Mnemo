<?php

namespace App\Tests\Controller;

use App\Dashboard\DashboardPreferences;
use App\Entity\AgentSession;
use App\Entity\Project;
use App\Enum\AgentSessionStatus;
use App\Enum\DashboardRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FactoryControllerTest extends WebTestCase
{
    public function testFactoryLifecycleThroughHttpUi(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE agent_session_event, agent_session, user_preference, project RESTART IDENTITY CASCADE');

        $project = new Project('billing', 'Facturation', '/tmp/billing');
        $em->persist($project);
        $em->flush();

        // 1. Open project factory page
        $crawler = $client->request('GET', '/projects/billing/factory');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Software Factory');
        self::assertSelectorTextContains('.factory-hooks', 'start_session');

        // 2. Start a tracked session from the modal form
        $token = (string) $crawler->filter('#factory-new-session input[name="_token"]')->attr('value');
        $client->request('POST', '/projects/billing/factory/sessions', [
            '_token' => $token,
            'title' => 'Implémenter RG-012 facturation prorata',
            'goal' => 'Ajouter le calcul au prorata temporis.',
            'role' => 'developer',
            'runner' => 'claude_cli',
            'branch' => 'feat/rg-012',
            'rule_code' => 'RG-012',
            'client' => 'claude-code',
        ]);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Implémenter RG-012 facturation prorata');
        self::assertSelectorTextContains('.factory-detail-aside', 'feat/rg-012');
        self::assertSelectorTextContains('.factory-detail-aside', 'RG-012');

        $session = $em->getRepository(AgentSession::class)->findOneBy(['title' => 'Implémenter RG-012 facturation prorata']);
        self::assertNotNull($session);

        // 3. Log a timeline event with touched files
        $eventToken = (string) $crawler->filter('form[action$="/events"] input[name="_token"]')->attr('value');
        $client->request('POST', '/factory/sessions/'.$session->getPublicId().'/events', [
            '_token' => $eventToken,
            'kind' => 'file_changed',
            'message' => 'Ajout du calculateur prorata et de son test unitaire.',
            'files' => 'src/Billing/ProrataCalculator.php, tests/Billing/ProrataCalculatorTest.php',
        ]);
        self::assertResponseRedirects('/factory/sessions/'.$session->getPublicId());
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.factory-timeline', 'Ajout du calculateur prorata');
        self::assertSelectorTextContains('.factory-file-list', 'src/Billing/ProrataCalculator.php');

        // 4. Close the session
        $finishToken = (string) $crawler->filter('form[action$="/finish"] input[name="_token"]')->attr('value');
        $client->request('POST', '/factory/sessions/'.$session->getPublicId().'/finish', [
            '_token' => $finishToken,
            'status' => 'succeeded',
            'summary' => 'Prorata implémenté et tests verts.',
        ]);
        self::assertResponseRedirects('/factory/sessions/'.$session->getPublicId());
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.callout--info', 'Prorata implémenté et tests verts.');

        $em->clear();
        $reloaded = $em->getRepository(AgentSession::class)->findOneBy(['publicId' => $session->getPublicId()]);
        self::assertNotNull($reloaded);
        self::assertSame(AgentSessionStatus::Succeeded, $reloaded->getStatus());
        self::assertCount(2, $reloaded->getFilesTouched());
    }

    public function testFactoryHookEndpointTracksSessionStartEventAndEnd(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE agent_session_event, agent_session, user_preference, project RESTART IDENTITY CASCADE');

        $project = new Project('core', 'Core API', '/tmp/core');
        $em->persist($project);
        $em->flush();

        // Start via JSON hook
        $client->jsonRequest('POST', '/api/factory/sessions/hook', [
            'action' => 'start',
            'project' => 'core',
            'title' => 'Claude Code hook run',
            'client' => 'claude-code',
            'runner' => 'claude_cli',
            'branch' => 'fix/token-refresh',
        ]);
        self::assertResponseStatusCodeSame(201);
        $started = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($started);
        self::assertIsString($started['session_id'] ?? null);
        $sessionId = $started['session_id'];

        // Append event via JSON hook
        $client->jsonRequest('POST', '/api/factory/sessions/hook', [
            'action' => 'event',
            'sessionId' => $sessionId,
            'kind' => 'decision',
            'message' => 'Rotation des refresh tokens activée.',
            'files' => ['src/Auth/TokenRotator.php'],
        ]);
        self::assertResponseIsSuccessful();

        // End via JSON hook
        $client->jsonRequest('POST', '/api/factory/sessions/hook', [
            'action' => 'end',
            'sessionId' => $sessionId,
            'status' => 'succeeded',
            'summary' => 'Correction vérifiée par PHPUnit.',
        ]);
        self::assertResponseIsSuccessful();
        $ended = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('succeeded', $ended['status']);
        self::assertSame(['src/Auth/TokenRotator.php'], $ended['files_touched']);
    }

    public function testFactoryIsDeniedInBusinessOnlyMode(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE agent_session_event, agent_session, user_preference, project RESTART IDENTITY CASCADE');

        try {
            self::getContainer()->get(DashboardPreferences::class)->selectRole(DashboardRole::Business);

            $client->request('GET', '/factory');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $em->getConnection()->executeStatement('TRUNCATE user_preference RESTART IDENTITY CASCADE');
        }
    }
}
