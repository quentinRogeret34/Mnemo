<?php

namespace App\Command;

use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmOrchestrator;
use App\Repository\SwarmRunRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:factory:run-swarm',
    description: 'Synchronously advances a Software Factory SwarmRun (plan, workers or review) without needing messenger:consume.',
)]
final class RunSwarmCommand extends Command
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('publicId', InputArgument::REQUIRED, 'Public ID of the swarm (e.g. swarm_a1b2c3d4e5f6)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->factoryGuard->isEnabled()) {
            $io->error('Software Factory is disabled in this environment (FACTORY_ENABLED).');

            return Command::FAILURE;
        }

        $publicId = (string) $input->getArgument('publicId');
        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm) {
            $io->error(\sprintf('Swarm "%s" not found.', $publicId));

            return Command::FAILURE;
        }

        $this->orchestrator->advanceSynchronously($swarm);
        $io->success(\sprintf('Swarm %s is now in status "%s".', $swarm->getPublicId(), $swarm->getStatus()->value));

        return Command::SUCCESS;
    }
}
