<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AppearanceTest extends WebTestCase
{
    public function testAppearancePageListsEverySetting(): void
    {
        $client = self::createClient();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $crawler = $client->request('GET', '/settings/appearance');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('input[type="radio"][name="theme"]'));
        self::assertCount(5, $crawler->filter('input[type="radio"][name="accent"]'));
        self::assertCount(1, $crawler->filter('input[type="checkbox"][name="contrast"]'));
        self::assertCount(2, $crawler->filter('input[type="radio"][name="text-size"]'));
        self::assertSelectorExists('.sidebar a[href="/settings/appearance"][aria-current="page"]');
    }

    #[DataProvider('pages')]
    public function testPageFollowsTheSystemSchemeWithoutScript(string $url): void
    {
        $client = self::createClient();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('head meta[name="color-scheme"][content="light dark"]'));
        self::assertStringNotContainsString('dataset.theme', (string) $client->getResponse()->getContent());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function pages(): iterable
    {
        yield ['/'];
        yield ['/login'];
        yield ['/pending'];
    }

    public function testThemeToggleNamesBothActions(): void
    {
        $client = self::createClient();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $crawler = $client->request('GET', '/');

        self::assertSame('Passer au thème sombre', trim($crawler->filter('.theme-toggle__option--dark')->text()));
        self::assertSame('Passer au thème clair', trim($crawler->filter('.theme-toggle__option--light')->text()));
    }

    public function testTheLanguagePickedOnTheAppearancePageAppliesToEveryPage(): void
    {
        $client = self::createClient();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $crawler = $client->request('GET', '/settings/appearance');
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        $client->submit($crawler->selectButton('Changer de langue')->form(['locale' => 'en']));
        self::assertResponseRedirects('/settings/appearance');

        $crawler = $client->request('GET', '/wiki');
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextContains('.sidebar', 'All memories');
        self::assertSelectorTextContains('.wiki__body', 'This page explains what each screen is for.');
        self::assertSelectorExists('#memoires.wiki__section');
    }

    public function testAnUnknownLanguageIsIgnored(): void
    {
        $client = self::createClient();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('TRUNCATE project, node, edge, folder RESTART IDENTITY CASCADE');

        $crawler = $client->request('GET', '/settings/appearance');
        $client->request('POST', '/settings/locale', ['locale' => 'de', '_token' => $crawler->filter('input[name="_token"]')->attr('value')]);

        self::assertSame('fr', $client->request('GET', '/')->filter('html')->attr('lang'));
    }
}
