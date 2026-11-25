<?php

namespace App\Tests\Addon;

use App\Addon\AddonCategory;
use App\Addon\AddonInterface;
use App\Addon\AddonManager;
use App\Addon\AddonManifest;
use App\Addon\AddonNavItem;
use App\Addon\AddonRegistry;
use App\Addon\AddonScope;
use App\Addon\Builtin\CodeGraphAddon;
use App\Addon\Builtin\FactoryAddon;
use App\Addon\Builtin\GardenAddon;
use App\Addon\Builtin\GroomingAddon;
use App\Addon\Builtin\SlackWebhookAddon;
use App\Addon\Builtin\StudioAddon;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AddonManagerTest extends KernelTestCase
{
    public function testBuiltinsAreRegisteredAndDefaultEnabled(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE addon_installation, project RESTART IDENTITY CASCADE');

        $manager = self::getContainer()->get(AddonManager::class);

        $ids = array_map(static fn (AddonInterface $a): string => $a->manifest()->id, $manager->all());
        self::assertContains(FactoryAddon::ID, $ids);
        self::assertContains(StudioAddon::ID, $ids);
        self::assertContains(CodeGraphAddon::ID, $ids);
        self::assertContains(GardenAddon::ID, $ids);
        self::assertContains(GroomingAddon::ID, $ids);
        self::assertContains(SlackWebhookAddon::ID, $ids);

        foreach ($ids as $id) {
            self::assertTrue($manager->isEnabled($id), \sprintf('Addon %s should default to enabled.', $id));
        }

        self::assertFalse($manager->isEnabled('nonexistent_addon'));
        self::assertCount(3, $manager->byCategory(AddonCategory::Factory));
        self::assertGreaterThanOrEqual(1, \count($manager->byCategory(AddonCategory::Knowledge)));
        self::assertGreaterThanOrEqual(1, \count($manager->byCategory(AddonCategory::Integration)));
    }

    public function testProjectOverrideTakesPrecedenceOverWorkspaceSetting(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE addon_installation, project RESTART IDENTITY CASCADE');

        $alpha = new Project('alpha', 'Alpha', '/tmp/alpha');
        $beta = new Project('beta', 'Beta', '/tmp/beta');
        $em->persist($alpha);
        $em->persist($beta);
        $em->flush();

        $manager = self::getContainer()->get(AddonManager::class);

        // Disable Studio workspace-wide
        $manager->setEnabled(StudioAddon::ID, false);
        self::assertFalse($manager->isEnabled(StudioAddon::ID));
        self::assertFalse($manager->isEnabled(StudioAddon::ID, $alpha));
        self::assertFalse($manager->isEnabled(StudioAddon::ID, $beta));

        // Re-enable Studio only on project Alpha
        $manager->setEnabled(StudioAddon::ID, true, $alpha);
        self::assertFalse($manager->isEnabled(StudioAddon::ID));
        self::assertTrue($manager->isEnabled(StudioAddon::ID, $alpha));
        self::assertTrue($manager->hasProjectOverride(StudioAddon::ID, $alpha));
        self::assertFalse($manager->isEnabled(StudioAddon::ID, $beta));
        self::assertFalse($manager->hasProjectOverride(StudioAddon::ID, $beta));

        // Updating existing installation does not duplicate rows
        $manager->setEnabled(StudioAddon::ID, false, $alpha);
        self::assertFalse($manager->isEnabled(StudioAddon::ID, $alpha));
    }

    public function testPresetsToggleFactorySuiteAndRestoreAll(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('TRUNCATE addon_installation, project RESTART IDENTITY CASCADE');

        $project = new Project('demo', 'Demo', '/tmp/demo');
        $em->persist($project);
        $em->flush();

        $manager = self::getContainer()->get(AddonManager::class);

        $manager->applyPreset(AddonManager::PRESET_BUSINESS);
        self::assertFalse($manager->isEnabled(FactoryAddon::ID));
        self::assertFalse($manager->isEnabled(StudioAddon::ID, $project));
        self::assertFalse($manager->isEnabled(CodeGraphAddon::ID, $project));
        self::assertTrue($manager->isEnabled(GardenAddon::ID, $project));
        self::assertTrue($manager->isEnabled(GroomingAddon::ID, $project));
        self::assertTrue($manager->isEnabled(SlackWebhookAddon::ID, $project));

        // Nav items reflect disabled addons
        $studioNavRoutes = array_map(
            static fn (AddonNavItem $i): string => $i->route,
            $manager->navItemsFor('studio_agents', $project),
        );
        self::assertNotContains('project_factory', $studioNavRoutes);
        self::assertNotContains('project_studio', $studioNavRoutes);
        self::assertContains('garden_show', $studioNavRoutes);
        self::assertContains('project_grooming', $studioNavRoutes);
        self::assertSame([], $manager->navItemsFor('knowledge', $project));

        // Software Factory preset restores everything
        $manager->applyPreset(AddonManager::PRESET_FACTORY);
        self::assertTrue($manager->isEnabled(FactoryAddon::ID));
        self::assertTrue($manager->isEnabled(StudioAddon::ID, $project));
        self::assertTrue($manager->isEnabled(CodeGraphAddon::ID, $project));
        self::assertTrue($manager->isEnabled(GardenAddon::ID, $project));
        self::assertTrue($manager->isEnabled(GroomingAddon::ID, $project));
        self::assertTrue($manager->isEnabled(SlackWebhookAddon::ID, $project));
    }

    public function testRegistryRejectsDuplicateIdsAndCustomNavItemsResolve(): void
    {
        $customAddon = new class implements AddonInterface {
            public function manifest(): AddonManifest
            {
                return new AddonManifest(
                    id: 'custom_grooming',
                    name: 'Grooming',
                    description: 'Custom grooming swarm.',
                    category: AddonCategory::Knowledge,
                    scope: AddonScope::Project,
                    icon: 'lucide:wand-sparkles',
                    color: 'accent',
                    defaultEnabled: true,
                    presets: ['business', 'factory'],
                    navItems: [
                        new AddonNavItem(
                            route: 'project_grooming',
                            label: 'Grooming',
                            icon: 'lucide:wand-sparkles',
                            group: 'studio_agents',
                            projectScoped: true,
                            activeRoutes: ['project_grooming', 'project_grooming_show'],
                        ),
                    ],
                );
            }
        };

        $registry = new AddonRegistry([$customAddon]);
        self::assertSame($customAddon, $registry->get('custom_grooming'));
        self::assertTrue($customAddon->manifest()->navItems[0]->isActive('project_grooming_show'));
        self::assertFalse($customAddon->manifest()->navItems[0]->isActive('dashboard'));

        $this->expectException(\LogicException::class);
        new AddonRegistry([$customAddon, $customAddon]);
    }
}
