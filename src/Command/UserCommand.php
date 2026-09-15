<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user',
    description: 'Create or update a user account',
)]
class UserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The username')
            ->addArgument('password', InputArgument::OPTIONAL, 'The password (required when creating)')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant administrator access')
            ->addOption('no-admin', null, InputOption::VALUE_NONE, 'Revoke administrator access');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');
        $password = $input->getArgument('password');
        $email = $input->getOption('email');

        $user = $this->userRepository->findByUsername($username);
        $isNew = $user === null;

        if ($isNew) {
            if (!$password) {
                $io->error('Password is required when creating a new user.');
                return Command::FAILURE;
            }
            if (!$email) {
                $io->error('Email is required when creating a new user.');
                return Command::FAILURE;
            }
            $user = new User();
            $user->setUsername($username);
            $this->em->persist($user);
        }

        if ($password) {
            $user->setPassword(password_hash($password, PASSWORD_BCRYPT));
        }

        if ($email !== null) {
            $existing = $this->userRepository->findByEmail($email);
            if ($existing && $existing->getId() !== $user->getId()) {
                $io->error(sprintf('That email address already belongs to "%s".', $existing->getUsername()));
                return Command::FAILURE;
            }
            $user->setEmail($email);
        }

        if ($input->getOption('admin')) {
            $user->setIsAdmin(true);
        }
        if ($input->getOption('no-admin')) {
            $user->setIsAdmin(false);
        }

        $this->em->flush();

        $io->success(sprintf('%s user "%s".', $isNew ? 'Created' : 'Updated', $username));

        return Command::SUCCESS;
    }
}
