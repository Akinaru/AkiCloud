<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Crée un utilisateur administrateur',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Création d\'un administrateur AkiCloud');

        $email = $io->ask('Email', null, function (?string $value) {
            if (empty($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('L\'email est invalide.');
            }
            return $value;
        });

        $firstName = $io->ask('Prénom', null, function (?string $value) {
            if (empty($value)) {
                throw new \RuntimeException('Le prénom est obligatoire.');
            }
            return $value;
        });

        $lastName = $io->ask('Nom', null, function (?string $value) {
            if (empty($value)) {
                throw new \RuntimeException('Le nom est obligatoire.');
            }
            return $value;
        });

        $plainPassword = $io->askHidden('Mot de passe (min 8 caractères)', function (?string $value) {
            if (empty($value) || mb_strlen($value) < 8) {
                throw new \RuntimeException('Le mot de passe doit faire au moins 8 caractères.');
            }
            return $value;
        });

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $io->error($error->getPropertyPath() . ': ' . $error->getMessage());
            }
            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Administrateur "%s" (%s) créé avec succès !', $user->getFullName(), $email));

        return Command::SUCCESS;
    }
}
