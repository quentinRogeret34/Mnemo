<?php

namespace App\Tests\Controller;

use App\Connector\CredentialResolver;
use App\Entity\Connector;
use App\Entity\Node;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\ConnectorMode;
use App\Enum\NodeType;
use App\Enum\UserStatus;
use App\Forge\RepositoryCheckout;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, connector, api_key, app_user, user_group RESTART IDENTITY CASCADE');
        $demo = new Project('demo', 'Demo project', '/repos/demo');
        $demo->setRepository('github.com/acme/demo');
        $this->entityManager->persist($demo);
        $this->entityManager->persist(new Project('other', 'Other project', '/repos/other'));
        $this->entityManager->flush();
    }

    public function testAProjectIsCreatedFromTheDialogWithASlugDeducedFromItsName(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorExists('.sidebar button[commandfor="project-new"][command="show-modal"]');
        self::assertCount(0, $crawler->filter('dialog#project-new[open]'));

        $this->client->submit($crawler->selectButton('Créer le projet')->form([
            'new_project[name]' => 'Ma Boutique',
            'new_project[repository]' => 'GitHub.com/Acme/Shop.git',
        ]));
        self::assertResponseRedirects('/projects/ma-boutique/settings');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toasts .flash[role="status"]', 'Projet « Ma Boutique » créé.');
        self::assertSelectorExists('.sidebar a[href="/projects/ma-boutique/settings"]');

        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'ma-boutique']);
        self::assertSame(['Ma Boutique', 'github.com/acme/shop', ''], [$project->getName(), $project->getRepository(), $project->getRepositoryPath()]);

        $crawler = $this->client->request('GET', '/projects/ma-boutique/settings');
        $this->client->submit($crawler->selectButton('Créer le projet')->form(['new_project[name]' => 'Facturation', 'new_project[slug]' => 'billing', 'new_project[repositoryPath]' => '/repos/billing']));
        self::assertResponseRedirects('/projects/billing/settings');
        self::assertSame('/repos/billing', $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'billing'])->getRepositoryPath());
    }

    public function testNewIsNotReadAsAProjectSlugAndAnInvalidProjectComesBackInTheOpenDialog(): void
    {
        $crawler = $this->client->request('GET', '/projects/new');
        self::assertResponseIsSuccessful();
        self::assertSame('project_new', $this->client->getRequest()->attributes->get('_route'));
        self::assertCount(1, $crawler->filter('dialog#project-new[open] input[name="new_project[name]"][autofocus]'));

        $invalid = [
            'Cet identifiant est déjà pris.' => ['new_project[name]' => 'Demo'],
            'Ce dépôt est déjà rattaché à un autre projet.' => ['new_project[name]' => 'Copie', 'new_project[repository]' => 'GitHub.com/Acme/Demo.git'],
            'Cet identifiant est réservé.' => ['new_project[name]' => 'New'],
            'Minuscules, chiffres et tirets seulement' => ['new_project[name]' => 'Copie', 'new_project[slug]' => 'Pas Un Slug'],
            'Donner un nom au projet.' => ['new_project[name]' => '', 'new_project[slug]' => 'sans-nom'],
        ];
        foreach ($invalid as $message => $values) {
            $crawler = $this->client->submit($crawler->selectButton('Créer le projet')->form($values + ['new_project[name]' => '', 'new_project[slug]' => '', 'new_project[repository]' => '']));
            self::assertResponseStatusCodeSame(422, $message);
            self::assertStringContainsString($message, $crawler->filter('dialog#project-new[open]')->text());
        }
        self::assertSame(2, $this->entityManager->getRepository(Project::class)->count([]));
    }

    public function testAMemberCreatesInTheirGroupsASuperAdminChoosesThemAndAnAccountWithoutGroupCreatesNothing(): void
    {
        $bob = $this->user('bob@example.com');
        $carol = $this->user('carol@example.com');
        $admin = $this->user('admin@example.com', superAdmin: true);
        $back = new UserGroup('Back');
        $back->setUsers([$bob]);
        $front = new UserGroup('Front');
        $this->entityManager->persist($back);
        $this->entityManager->persist($front);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $groups = fn (string $slug): array => array_values(array_filter(array_map(
            static fn (UserGroup $group): ?string => $group->getProjects()->exists(static fn (int $key, Project $project): bool => $slug === $project->getSlug()) ? $group->getName() : null,
            $this->entityManager->getRepository(UserGroup::class)->findBy([], ['name' => 'ASC']),
        )));

        $this->client->loginUser($this->entityManager->find(User::class, $carol->getId()));
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('#project-new, [commandfor="project-new"]'));
        $this->client->request('GET', '/projects/new');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/projects/new', ['new_project' => ['name' => 'Interdit']]);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->entityManager->find(User::class, $bob->getId()));
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('input[name="new_project[groups][]"]'));
        $this->client->submit($crawler->selectButton('Créer le projet')->form(['new_project[name]' => 'Shop']));
        self::assertResponseRedirects('/projects/shop/settings');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        self::assertSame(['Back'], $groups('shop'));

        $this->client->loginUser($this->entityManager->find(User::class, $admin->getId()));
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->selectButton('Créer le projet')->form(['new_project[name]' => 'Blog']);
        $form['new_project[groups]'][1]->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/projects/blog/settings');
        $this->entityManager->clear();
        self::assertSame(['Front'], $groups('blog'));

        $crawler = $this->client->followRedirect();
        $form = $crawler->selectButton('Enregistrer les réglages')->form();
        $form['project[groups]'][0]->tick();
        $form['project[groups]'][1]->untick();
        $this->client->submit($form);
        $this->entityManager->clear();
        self::assertSame(['Back'], $groups('blog'));
    }

    public function testSettingsRenameTheProjectAndNormaliseItsRepository(): void
    {
        $crawler = $this->client->request('GET', '/projects/other/settings');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="project[slug]"]'));

        $this->client->submit($crawler->selectButton('Enregistrer les réglages')->form([
            'project[name]' => 'Autre projet',
            'project[repository]' => ' GitLab.com/Acme/Other.git/ ',
            'project[repositoryPath]' => '/srv/other',
        ]));
        self::assertResponseRedirects('/projects/other/settings');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Réglages du projet mis à jour.');
        $this->entityManager->clear();
        $other = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'other']);
        self::assertSame(['Autre projet', 'gitlab.com/acme/other', '/srv/other'], [$other->getName(), $other->getRepository(), $other->getRepositoryPath()]);

        $crawler = $this->client->submit($crawler->selectButton('Enregistrer les réglages')->form(['project[name]' => '', 'project[repository]' => 'github.com/acme/demo']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Ce dépôt est déjà rattaché à un autre projet.', $crawler->filter('.project-settings')->text());
        self::assertStringContainsString('Donner un nom au projet.', $crawler->filter('.project-settings')->text());
        self::assertSame('Autre projet', $crawler->filter('.crumbs a')->text());
        $this->entityManager->clear();
        self::assertSame('gitlab.com/acme/other', $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'other'])->getRepository());

        $crawler = $this->client->submit($crawler->selectButton('Enregistrer les réglages')->form(['project[name]' => 'Autre projet', 'project[repository]' => '']));
        self::assertResponseRedirects('/projects/other/settings');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'other'])->getRepository());
    }

    public function testAProjectOutOfReachHasNoSettings(): void
    {
        $bob = $this->user('bob@example.com');
        $back = new UserGroup('Back');
        $back->setUsers([$bob]);
        $back->setProjects([$this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'demo'])]);
        $this->entityManager->persist($back);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->loginUser($this->entityManager->find(User::class, $bob->getId()));
        $this->client->request('GET', '/projects/demo/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="project[groups][]"]');
        $this->client->request('GET', '/projects/other/settings');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnArchivedProjectLeavesTheListsStaysReadableAndIsRestored(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('.tabs'));
        $archive = $crawler->filter('form[action="/projects/other/archive"]')->selectButton('Archiver');
        self::assertNotNull($archive->attr('data-confirm'));
        $this->client->submit($archive->form());
        self::assertResponseRedirects('/');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Projet « Other project » archivé.');

        self::assertSame(['Demo projectdemo'], $crawler->filter('.content .project-row__title')->each(static fn ($title): string => $title->text()));
        self::assertSame(['Tous les projets', 'Demo project'], $crawler->filter('#project-menu a.menu__item')->each(static fn ($item): string => $item->text()));
        self::assertSame(['Actifs', 'Archivés'], $crawler->filter('.tabs a')->each(static fn ($tab): string => $tab->text()));
        $this->client->request('GET', '/api/context?project=other');
        self::assertNull(json_decode($this->client->getResponse()->getContent(), true)['project']);

        $this->client->request('GET', '/projects/other');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sidebar .nav-group__label .tag', 'Archivé');
        $this->client->request('GET', '/projects/other/settings');
        self::assertSelectorTextContains('.archived-banner', 'Projet archivé');
        self::assertSelectorNotExists('form[action="/projects/other/archive"]');

        $crawler = $this->client->request('GET', '/?archived=1');
        self::assertSame(['Other projectother'], $crawler->filter('.content .project-row__title')->each(static fn ($title): string => $title->text()));
        self::assertSame('Archivés', $crawler->filter('.tabs a[aria-current="page"]')->text());
        $this->client->submit($crawler->selectButton('Restaurer')->form());
        self::assertResponseRedirects('/projects/other/settings');
        $this->client->followRedirect();
        self::assertSelectorNotExists('.archived-banner');
        $crawler = $this->client->request('GET', '/');
        self::assertCount(2, $crawler->filter('.content .project-row'));
        self::assertCount(0, $crawler->filter('.tabs'));

        $this->client->request('POST', '/projects/other/archive', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $this->entityManager->clear();
        self::assertFalse($this->entityManager->getRepository(Project::class)->findOneBy(['slug' => 'other'])->isArchived());
    }

    public function testDeletingAProjectTakesTheRetypedSlugAndRemovesItsConnectorsWithTheirClones(): void
    {
        $clones = sys_get_temp_dir().'/project-remover-'.bin2hex(random_bytes(4));
        self::getContainer()->set(RepositoryCheckout::class, new RepositoryCheckout(self::getContainer()->get(CredentialResolver::class), $clones));
        $projects = $this->entityManager->getRepository(Project::class);
        $other = $projects->findOneBy(['slug' => 'other']);
        $connectors = [];
        foreach (['acme/other' => $other, 'acme/demo' => $projects->findOneBy(['slug' => 'demo'])] as $repository => $project) {
            $connector = new Connector();
            $connector->setName($repository);
            $connector->setMode(ConnectorMode::GitHub);
            $connector->setEndpoint('https://api.github.com');
            $connector->setArguments(['repository' => $repository]);
            $connector->setProject($project);
            $this->entityManager->persist($connector);
            $connectors[] = $connector;
        }
        $this->entityManager->persist(new Node($other, NodeType::CodeFile, 'src/a.ts', 'a.ts', 'src/a.ts'));
        $this->entityManager->flush();
        foreach ($connectors as $connector) {
            (new Filesystem())->mkdir([$clones.'/'.$connector->getId(), $clones.'/'.$connector->getId().'-graph']);
        }

        $crawler = $this->client->request('GET', '/projects/other/settings');
        self::assertSame('other', $crawler->filter('dialog#project-delete input[name="confirm"]')->attr('pattern'));
        self::assertCount(1, $crawler->filter('#danger button[commandfor="project-delete"][command="show-modal"]'));
        self::assertCount(1, $crawler->filter('dialog#project-delete button[command="close"][autofocus]'));
        $form = $crawler->selectButton('Supprimer définitivement')->form();

        $this->client->request('POST', '/projects/other/delete', ['_token' => 'forged', 'confirm' => 'other']);
        self::assertResponseStatusCodeSame(403);
        $this->client->submit($form, ['confirm' => 'demo']);
        self::assertResponseRedirects('/projects/other/settings#danger');
        self::assertSelectorTextContains('.flash--error', 'ne correspond pas', $this->client->followRedirect()->html());
        self::assertSame(2, $projects->count([]));

        $this->client->submit($form, ['confirm' => 'other']);
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Projet « Other project » supprimé');
        $this->entityManager->clear();
        self::assertSame(['demo'], array_map(static fn (Project $project): string => $project->getSlug(), $projects->findAll()));
        self::assertSame(['acme/demo'], array_map(static fn (Connector $connector): string => $connector->getName(), $this->entityManager->getRepository(Connector::class)->findAll()));
        self::assertSame(0, $this->entityManager->getRepository(Node::class)->count([]));
        self::assertSame([(string) $connectors[1]->getId(), $connectors[1]->getId().'-graph'], array_values(array_diff(scandir($clones), ['.', '..'])));
        (new Filesystem())->remove($clones);

        $this->client->request('GET', '/projects/other/settings');
        self::assertResponseStatusCodeSame(404);
    }

    private function user(string $email, bool $superAdmin = false): User
    {
        $user = new User($email, $email, 'github', $email);
        $user->setStatus(UserStatus::Active);
        $user->setSuperAdmin($superAdmin);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
