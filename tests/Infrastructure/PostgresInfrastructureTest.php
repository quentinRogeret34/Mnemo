<?php

namespace App\Tests\Infrastructure;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;

final class PostgresInfrastructureTest extends KernelTestCase
{
    public function testSessionsCacheAndLocksWorkAgainstPostgresTables(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE sessions, cache_items, lock_keys');

        // 1. PostgreSQL sessions via PdoSessionHandler (using the test DB's native PDO connection)
        $pdo = $connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);
        $handler = new PdoSessionHandler($pdo);
        self::assertTrue($handler->open('', 'PHPSESSID'));
        self::assertSame('', $handler->read('sess_pg_test_1'));
        self::assertTrue($handler->write('sess_pg_test_1', 'payload-ok'));
        self::assertTrue($handler->close());

        self::assertTrue($handler->open('', 'PHPSESSID'));
        self::assertSame('payload-ok', $handler->read('sess_pg_test_1'));
        self::assertTrue($handler->destroy('sess_pg_test_1'));
        self::assertTrue($handler->close());

        // 2. PostgreSQL application cache via DoctrineDbalAdapter
        $cache = new DoctrineDbalAdapter($connection, 'infra-test');
        $item = $cache->getItem('github_token_sample');
        $item->set('ghs_cached_value');
        $item->expiresAfter(60);
        self::assertTrue($cache->save($item));
        self::assertSame('ghs_cached_value', $cache->getItem('github_token_sample')->get());

        // 3. PostgreSQL distributed locks via DoctrineDbalStore
        $lockFactory = new LockFactory(new DoctrineDbalStore($connection));
        $lock = $lockFactory->createLock('pg-infra-lock-test', 30);
        self::assertTrue($lock->acquire());
        $lock->release();
    }
}
