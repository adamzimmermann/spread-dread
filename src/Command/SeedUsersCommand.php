<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-users',
    description: 'Create default user accounts',
)]
class SeedUsersCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $users = [
            ['username' => 'Adam', 'password' => 'march'],
            ['username' => 'Aaron', 'password' => 'march'],
        ];

        foreach ($users as $data) {
            $existing = $this->userRepository->findByUsername($data['username']);
            if ($existing) {
                $io->note(sprintf('User "%s" already exists, skipping.', $data['username']));
                continue;
            }

            $user = new User();
            $user->setUsername($data['username']);
            $user->setPassword(password_hash($data['password'], PASSWORD_BCRYPT));
            $this->em->persist($user);
            $io->success(sprintf('Created user "%s".', $data['username']));
        }

        $this->em->flush();

        return Command::SUCCESS;
    }
}
