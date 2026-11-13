<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class FeedbackTest extends WebTestCase
{
    public function testRevokingAKeyAsksForConfirmationThenShowsAToast(): void
    {
        $client = self::createClient();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, api_key RESTART IDENTITY CASCADE');

        $crawler = $client->request('GET', '/settings/keys');
        self::assertSelectorExists('dialog#confirm[aria-labelledby="confirm-title"] #confirm-title');
        self::assertSelectorNotExists('.toasts .flash');
        $crawler = $client->submit($crawler->selectButton('Créer la clé')->form(['name' => 'laptop']));

        $revoke = $crawler->selectButton('Révoquer');
        self::assertSame('Révoquer la clé laptop ?', $revoke->attr('data-confirm'));
        self::assertSame('Révoquer la clé', $revoke->attr('data-confirm-label'));

        $client->submit($revoke->form());
        $crawler = $client->followRedirect();

        $toast = $crawler->filter('body > .toasts[role="region"][aria-label="Notifications"] .flash[role="status"]');
        self::assertCount(1, $toast);
        self::assertNotSame('', trim($toast->text()));
        self::assertCount(1, $toast->filter('button[aria-label="Fermer la notification"]'));
        self::assertCount(0, $crawler->filter('main .flash'));
        self::assertNull($crawler->filter('.toasts')->attr('popover'));
    }

    public function testDestructiveButtonsCarryAConfirmation(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement('TRUNCATE project, node, edge, folder, connector, user_group RESTART IDENTITY CASCADE');
        $entityManager->persist(new Project('demo', 'Demo project', '/repos/demo'));
        $entityManager->flush();
        $client->loginUser(new InMemoryUser('admin', '', ['ROLE_USER', 'ROLE_SUPER_ADMIN']));

        $crawler = $client->request('GET', '/admin/users');
        $client->submit($crawler->selectButton('Créer le groupe')->form(['name' => 'Back']));
        $delete = $client->followRedirect()->filter('form[action="/admin/groups/1"] button[name="delete"]');
        self::assertSame('Supprimer le groupe Back ?', $delete->attr('data-confirm'));
        self::assertSame('Supprimer', $delete->attr('data-confirm-label'));

        $crawler = $client->request('GET', '/connectors/new');
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'connector[name]' => 'mem0',
            'connector[transport]' => 'stdio',
            'connector[endpoint]' => 'php tests/Fixtures/mcp/server.php',
            'connector[mode]' => 'tool_memories',
            'connector[tool]' => 'get_memories',
        ]));
        $delete = $client->followRedirect()->selectButton('Supprimer le connecteur');
        self::assertSame('Supprimer le connecteur mem0 ?', $delete->attr('data-confirm'));
        self::assertSame('form', $delete->ancestors()->first()->nodeName());

        $crawler = $client->request('GET', '/memories?project=demo');
        $client->submit($crawler->selectButton('Ajouter la mémoire')->form(['memory_form[text]' => 'Les remises sont plafonnées à 10 %.', 'memory_form[type]' => 'convention']));
        $delete = $client->followRedirect()->selectButton('Supprimer la mémoire');
        self::assertSame('Supprimer cette mémoire et ses liens ?', $delete->attr('data-confirm'));
        self::assertSame('Supprimer', $delete->attr('data-confirm-label'));
    }
}
