<?php

namespace App\Command;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds (or updates) one admin_users row. Used once during initial local setup to create the
 * single test admin account -- see README.md/DATABASE.md status notes for the exact credentials.
 */
#[AsCommand(name: 'app:create-admin-user', description: 'Create or update an admin_users row with a hashed password')]
class CreateAdminUserCommand extends Command
{
    public function __construct(
        private readonly AdminUserRepository $adminUsers,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED)
            ->addArgument('displayName', InputArgument::OPTIONAL, 'Display name', 'Admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $password = $input->getArgument('password');
        $displayName = $input->getArgument('displayName');

        $user = $this->adminUsers->findOneByEmail($email);
        if ($user === null) {
            $user = new AdminUser();
            $user->setEmail($email);
            $this->em->persist($user);
        }

        $user->setDisplayName($displayName)
            ->setRole('Redaktion')
            ->setIsActive(true)
            ->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

        $this->em->flush();

        $io->success(sprintf('Admin user ready: %s', $email));

        return Command::SUCCESS;
    }
}
