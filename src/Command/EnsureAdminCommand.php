<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:ensure-admin',
    description: 'Create (or reset) the dashboard admin user from env credentials. Idempotent.',
)]
class EnsureAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%env(string:ADMIN_USERNAME)%')]
        private readonly string $adminUsername,
        #[Autowire('%env(string:ADMIN_PASSWORD)%')]
        private readonly string $adminPassword,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reset-password', null, InputOption::VALUE_NONE, 'Reset the password even if the user already exists.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = '' !== $this->adminUsername ? $this->adminUsername : 'admin';
        $password = '' !== $this->adminPassword ? $this->adminPassword : 'admin';

        $user = $this->userRepository->findOneByUsername($username);

        if (null !== $user && !$input->getOption('reset-password')) {
            $io->info(\sprintf('Admin user "%s" already exists.', $username));

            return Command::SUCCESS;
        }

        if (null === $user) {
            $user = new User();
            $user->setUsername($username);
            $user->setRoles(['ROLE_ADMIN']);
            $action = 'Created';
        } else {
            $action = 'Updated password for';
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('%s admin user "%s".', $action, $username));

        return Command::SUCCESS;
    }
}
