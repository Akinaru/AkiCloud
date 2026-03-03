<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\Security\InvitationAcceptType;
use App\Repository\UserInvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/invite/{token}', name: 'app_invitation_accept', methods: ['GET', 'POST'])]
    public function acceptInvitation(
        string $token,
        Request $request,
        UserInvitationRepository $invitationRepository,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TokenStorageInterface $tokenStorage
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $invitation = $invitationRepository->findOneBy(['token' => $token]);
        if (!$invitation || $invitation->isAccepted() || $invitation->isExpired()) {
            return $this->render('security/invitation_accept.html.twig', [
                'invitation' => $invitation,
                'isInvalid' => true,
                'form' => null,
            ]);
        }

        $existingUser = $userRepository->findOneBy(['email' => $invitation->getEmail()]);
        if ($existingUser instanceof User) {
            if (!$invitation->isAccepted()) {
                $invitation->setAcceptedAt(new \DateTimeImmutable());
                $invitation->setAcceptedBy($existingUser);
                $entityManager->flush();
            }

            $this->addFlash('success', 'Ce compte existe déjà. Connectez-vous avec vos identifiants.');

            return $this->redirectToRoute('app_login');
        }

        $form = $this->createForm(InvitationAcceptType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $user = new User();
            $user->setEmail((string) $invitation->getEmail());
            $user->setFirstName(trim((string) ($data['firstName'] ?? '')));
            $user->setLastName(trim((string) ($data['lastName'] ?? '')));
            $role = (string) $invitation->getRole();
            $user->setRoles([in_array($role, ['ROLE_USER', 'ROLE_ADMIN'], true) ? $role : 'ROLE_USER']);

            $plainPassword = (string) $form->get('plainPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

            $entityManager->persist($user);
            $invitation->setAcceptedAt(new \DateTimeImmutable());
            $invitation->setAcceptedBy($user);
            $entityManager->flush();

            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
            $tokenStorage->setToken($token);
            $session = $request->getSession();
            $session->set('_security_main', serialize($token));
            $session->migrate(true);

            $this->addFlash('success', 'Compte créé. Bienvenue sur AkiCloud.');

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/invitation_accept.html.twig', [
            'invitation' => $invitation,
            'isInvalid' => false,
            'form' => $form,
        ]);
    }
}
