<?php

namespace App\Command;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:promote', description: 'Activate an account and make it super admin')]
final class PromoteUserCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Email of an account that already signed in once')]
        string $email,
    ): int {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user) {
            $io->error(\sprintf('No account with the email "%s". The person must sign in once first.', $email));

            return Command::FAILURE;
        }
        $user->setStatus(UserStatus::Active);
        $user->setSuperAdmin(true);
        $this->entityManager->flush();
        $io->success(\sprintf('%s is now an active super admin.', $user->getName()));

        return Command::SUCCESS;
    }
}
