<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:add',
    description: 'Add a new admin user by email so they can sign in with Google',
)]
class AddUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address of the admin user to add');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getArgument('email'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('"%s" is not a valid email address.', $email));

            return Command::FAILURE;
        }

        if ($this->userRepository->findOneBy(['email' => $email])) {
            $io->warning(sprintf('A user with email "%s" already exists.', $email));

            return Command::SUCCESS;
        }

        $user = (new User())
            ->setEmail($email)
            ->setRoles(['ROLE_ADMIN']);

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Admin user "%s" has been added.', $email));

        return Command::SUCCESS;
    }
}
