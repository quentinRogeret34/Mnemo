<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MarketplaceTest extends WebTestCase
{
    public function testMarketplaceListsAddonsFiltersByCategoryAndRendersLockedIcons(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE addon_installation, project RESTART IDENTITY CASCADE');

        $crawler = $client->request('GET', '/marketplace');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Marketplace');
        self::assertCount(5, $crawler->filter('.addon-card'));
        self::assertCount(0, $crawler->filter('svg.icon:not([aria-hidden="true"])'));
        self::assertSelectorExists('.sidebar a[href="/marketplace"][aria-current="page"]');

        // Filter by Factory category
        $crawler = $client->request('GET', '/marketplace?category=factory');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('.addon-card'));
        self::assertSelectorExists('.addon-card[data-addon-id="factory"]');
        self::assertSelectorExists('.addon-card[data-addon-id="studio"]');
        self::assertSelectorExists('.addon-card[data-addon-id="code_graph"]');
    }

    public function testToggleWorkspaceAndProjectScopeUpdatesSidebarVisibility(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE addon_installation, project RESTART IDENTITY CASCADE');

        $project = new Project('billing', 'Facturation', '/tmp/billing');
        $em->persist($project);
        $em->flush();

        // Initially all built-in links are visible in project sidebar
        $crawler = $client->request('GET', '/projects/billing');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar a[href="/projects/billing/studio"]');
        self::assertSelectorExists('.sidebar a[href="/projects/billing/code"]');
        self::assertSelectorExists('.sidebar a[href="/projects/billing/factory"]');
        self::assertSelectorExists('.sidebar a[href="/projects/billing/garden"]');
        self::assertSelectorExists('.sidebar a[href="/marketplace?project=billing"]');

        // Disable Studio for project billing via Marketplace toggle
        $crawler = $client->request('GET', '/marketplace?project=billing');
        self::assertResponseIsSuccessful();
        $studioForm = $crawler->filter('.addon-card[data-addon-id="studio"] form');
        $client->request('POST', (string) $studioForm->attr('action'), [
            '_token' => (string) $studioForm->filter('input[name="_token"]')->attr('value'),
            'enabled' => '0',
        ]);
        self::assertResponseRedirects('/marketplace?project=billing');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.addon-card[data-addon-id="studio"].addon-card--disabled');

        // Studio link is now hidden on /projects/billing while Code & Garden remain visible
        $client->request('GET', '/projects/billing');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.sidebar a[href="/projects/billing/studio"]');
        self::assertSelectorExists('.sidebar a[href="/projects/billing/code"]');
        self::assertSelectorExists('.sidebar a[href="/projects/billing/garden"]');

        // Invalid CSRF token is rejected
        $client->request('POST', '/marketplace/studio/toggle?project=billing', [
            '_token' => 'invalid-token',
            'enabled' => '1',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testApplyingBusinessAndFactoryPresetsHidesAndRestoresSidebarLinks(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE addon_installation, project RESTART IDENTITY CASCADE');

        $project = new Project('demo', 'Demo', '/tmp/demo');
        $em->persist($project);
        $em->flush();

        // Apply "Pilote Métier" preset workspace-wide
        $crawler = $client->request('GET', '/marketplace');
        self::assertResponseIsSuccessful();
        $businessForm = $crawler->filter('form[action^="/marketplace/preset/business"]');
        $client->request('POST', (string) $businessForm->attr('action'), [
            '_token' => (string) $businessForm->filter('input[name="_token"]')->attr('value'),
        ]);
        self::assertResponseRedirects('/marketplace');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Workspace sidebar hides Factory
        self::assertSelectorNotExists('.sidebar a[href="/factory"]');

        // Project sidebar hides Factory, Studio, Code, keeps Garden
        $client->request('GET', '/projects/demo');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.sidebar a[href="/projects/demo/factory"]');
        self::assertSelectorNotExists('.sidebar a[href="/projects/demo/studio"]');
        self::assertSelectorNotExists('.sidebar a[href="/projects/demo/code"]');
        self::assertSelectorExists('.sidebar a[href="/projects/demo/garden"]');

        // Apply "Software Factory" preset workspace-wide
        $crawler = $client->request('GET', '/marketplace');
        $factoryForm = $crawler->filter('form[action^="/marketplace/preset/factory"]');
        $client->request('POST', (string) $factoryForm->attr('action'), [
            '_token' => (string) $factoryForm->filter('input[name="_token"]')->attr('value'),
        ]);
        self::assertResponseRedirects('/marketplace');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar a[href="/factory"]');

        // Project sidebar restores Factory, Studio, Code
        $client->request('GET', '/projects/demo');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar a[href="/projects/demo/factory"]');
        self::assertSelectorExists('.sidebar a[href="/projects/demo/studio"]');
        self::assertSelectorExists('.sidebar a[href="/projects/demo/code"]');
    }
}
