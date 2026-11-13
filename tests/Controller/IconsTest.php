<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IconsTest extends WebTestCase
{
    #[DataProvider('pages')]
    public function testPageRendersLockedIcons(string $url): void
    {
        $client = self::createClient();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.sidebar svg.icon[aria-hidden="true"]')->count());
        // CssSelector lowercases attribute names in HTML mode, so viewBox needs XPath.
        self::assertCount(0, $crawler->filter('.sidebar')->filterXPath('descendant::*[local-name()="svg"][not(@viewBox)]'));
        self::assertCount(0, $crawler->filter('svg.icon:not([aria-hidden="true"])'));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function pages(): iterable
    {
        yield ['/'];
        yield ['/dashboard'];
        yield ['/memories'];
        yield ['/connectors'];
        yield ['/settings/keys'];
        yield ['/wiki'];
    }
}
