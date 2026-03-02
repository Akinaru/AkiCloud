<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\Admin\UserCreateType;
use App\Form\Admin\UserEditType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Service\EventLoggerService;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    #[Route(name: 'admin_user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository, \App\Repository\LoginAttemptRepository $loginAttemptRepo): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'users' => $userRepository->findAll(),
            'loginAttemptRepo' => $loginAttemptRepo,
        ]);
    }

    #[Route('/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, EventLoggerService $logger): Response
    {
        $user = new User();
        $form = $this->createForm(UserCreateType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            $user->setRoles([$form->get('role')->getData()]);

            $entityManager->persist($user);
            $entityManager->flush();

            $logger->info(sprintf('Nouvel utilisateur créé : %s (%s).', $user->getFullName(), $user->getEmail()));

            $this->addFlash('success', sprintf('Utilisateur "%s" créé avec succès.', $user->getFullName()));

            return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/new.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'admin_user_show', methods: ['GET'])]
    public function show(User $user, \App\Repository\LoginAttemptRepository $loginAttemptRepo): Response
    {
        return $this->render('admin/user/show.html.twig', [
            'user' => $user,
            'loginAttemptRepo' => $loginAttemptRepo,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        $currentRole = in_array('ROLE_ADMIN', $user->getRoles()) ? 'ROLE_ADMIN' : 'ROLE_USER';
        $form = $this->createForm(UserEditType::class, $user, [
            'current_role' => $currentRole,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            }
            $user->setRoles([$form->get('role')->getData()]);

            $entityManager->flush();

            $this->addFlash('success', sprintf('Utilisateur "%s" modifié avec succès.', $user->getFullName()));

            return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'admin_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $entityManager, EventLoggerService $logger): Response
    {
        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->getPayload()->getString('_token'))) {
            if ($user === $this->getUser()) {
                $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
                return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
            }

            $email = $user->getEmail();
            $entityManager->remove($user);
            $entityManager->flush();

            $logger->warning(sprintf('Utilisateur supprimé : %s.', $email));

            $this->addFlash('success', sprintf('Utilisateur "%s" supprimé.', $user->getFullName()));
        }

        return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
    }
}
