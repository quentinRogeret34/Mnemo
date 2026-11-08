<?php

namespace App\Command;

use App\Connector\ConnectorRunner;
use App\Entity\Connector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:connector:sync', description: 'Import the content of one MCP connector, or of all of them')]
final class ConnectorSyncCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectorRunner $runner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Connector id; all connectors when omitted')]
        ?int $id = null,
    ): int {
        $repository = $this->entityManager->getRepository(Connector::class);
        $connectors = null === $id ? $repository->findBy([], ['name' => 'ASC']) : array_filter([$repository->find($id)]);
        if ([] === $connectors) {
            $io->warning(null === $id ? 'No connector configured.' : \sprintf('Connector %d not found.', $id));

            return null === $id ? Command::SUCCESS : Command::FAILURE;
        }

        $failed = false;
        foreach ($connectors as $connector) {
            $report = $this->runner->sync($connector);
            if (null !== $report['error']) {
                $io->error(\sprintf('%s: %s', $connector->getName(), $report['error']));
                $failed = true;
                continue;
            }
            $io->success(\sprintf('%s: %d created, %d updated, %d unchanged, %d skipped.', $connector->getName(), $report['created'], $report['updated'], $report['unchanged'], \count($report['skipped'])));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
