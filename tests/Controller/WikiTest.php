<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WikiTest extends WebTestCase
{
    public function testEveryHelpLinkPointsToAWikiSection(): void
    {
        $client = self::createClient();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $crawler = $client->request('GET', '/wiki');
        self::assertResponseIsSuccessful();
        $sections = $crawler->filter('.wiki__section')->each(static fn ($section): string => $section->attr('id'));

        foreach (['/', '/dashboard', '/memories', '/memories/graph', '/proposals', '/documents', '/connectors'] as $url) {
            $help = $client->request('GET', $url)->filter('.header__actions a[href^="/wiki#"]');
            self::assertCount(1, $help, $url);
            self::assertContains(substr($help->attr('href'), 6), $sections, $url);
        }
    }
}
