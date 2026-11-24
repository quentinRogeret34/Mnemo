<?php

namespace App\Tests\Architecture;

use App\Enum\ProposalKind;
use App\Proposal\Handler\ProposalHandlerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

/**
 * Automated architecture guardrails enforcing SOLID, ADR Action boundaries, Repository separation and code hygiene.
 */
final class ArchitectureTest extends KernelTestCase
{
    public function testControllersAndTwigComponentsDoNotExecuteRawSql(): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $finder = (new Finder())
            ->files()
            ->name('*.php')
            ->in([$projectDir.'/src/Controller', $projectDir.'/src/Twig/Components']);

        $violations = [];
        foreach ($finder as $file) {
            $contents = $file->getContents();
            if (preg_match('/use\s+Doctrine\\\\DBAL\\\\Connection\b|->getConnection\s*\(/', $contents)) {
                $violations[] = $file->getRelativePathname();
            }
        }

        self::assertSame([], $violations, 'Controllers and Twig components must delegate SQL queries to dedicated Repositories.');
    }

    public function testEntitiesStayIsolatedFromApplicationAndPresentationLayers(): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $finder = (new Finder())->files()->name('*.php')->in($projectDir.'/src/Entity');

        $violations = [];
        foreach ($finder as $file) {
            $contents = $file->getContents();
            if (preg_match('/use\s+(App\\\\(Controller|Repository|Twig|Form)|Doctrine\\\\ORM\\\\EntityManagerInterface)\b/', $contents)) {
                $violations[] = $file->getRelativePathname();
            }
        }

        self::assertSame([], $violations, 'Doctrine entities must not depend on controllers, repositories, forms, Twig components or the EntityManager.');
    }

    public function testInvokableActionsHaveSinglePublicEntrypoint(): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $finder = (new Finder())->files()->name('*Action.php')->in($projectDir.'/src/Controller');

        $actionsChecked = 0;
        $violations = [];
        foreach ($finder as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $className = 'App\\Controller\\'.$relative;
            self::assertTrue(class_exists($className), \sprintf('Action class %s should be autoloadable.', $className));

            $reflection = new \ReflectionClass($className);
            ++$actionsChecked;

            if (!$reflection->isFinal()) {
                $violations[] = $className.' must be final.';
            }
            if (!$reflection->hasMethod('__invoke')) {
                $violations[] = $className.' must define __invoke().';
            }

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $className) {
                    continue;
                }
                if (!\in_array($method->getName(), ['__construct', '__invoke'], true)) {
                    $violations[] = \sprintf('%s exposes extra public method %s().', $className, $method->getName());
                }
            }
        }

        self::assertGreaterThan(0, $actionsChecked);
        self::assertSame([], $violations, 'Every *Action class must be final and expose only __invoke().');
    }

    public function testEveryProposalKindHasExactlyOneTaggedHandler(): void
    {
        self::bootKernel();

        $projectDir = \dirname(__DIR__, 2);
        $finder = (new Finder())->files()->name('*ProposalHandler.php')->in($projectDir.'/src/Proposal/Handler');

        $handlers = [];
        foreach ($finder as $file) {
            $className = 'App\\Proposal\\Handler\\'.$file->getFilenameWithoutExtension();
            $reflection = new \ReflectionClass($className);
            if ($reflection->isInstantiable() && $reflection->implementsInterface(ProposalHandlerInterface::class)) {
                /** @var ProposalHandlerInterface $instance */
                $instance = $reflection->newInstanceWithoutConstructor();
                $handlers[] = $instance;
            }
        }

        foreach (ProposalKind::cases() as $kind) {
            $matching = array_values(array_filter(
                $handlers,
                static fn (ProposalHandlerInterface $handler): bool => $handler->supports($kind),
            ));
            self::assertCount(1, $matching, \sprintf('ProposalKind::%s must be handled by exactly one ProposalHandlerInterface.', $kind->name));
        }
    }

    public function testSourceCodeContainsNoDebugCalls(): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $finder = (new Finder())->files()->name('*.php')->in($projectDir.'/src');

        $violations = [];
        foreach ($finder as $file) {
            if (preg_match('/(?<!::)(?<!->)\b(dd|dump|var_dump|print_r)\s*\(/', $file->getContents())) {
                $violations[] = $file->getRelativePathname();
            }
        }

        self::assertSame([], $violations, 'Debug calls (dd, dump, var_dump, print_r) must not remain in src/.');
    }
}
