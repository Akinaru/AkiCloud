<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserInvitation;
use App\Form\Admin\UserInviteType;
use App\Form\Admin\UserCreateType;
use App\Form\Admin\UserEditType;
use App\Repository\SettingRepository;
use App\Repository\UserInvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Service\EventLoggerService;
use Throwable;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    #[Route(name: 'admin_user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository, UserInvitationRepository $invitationRepository, \App\Repository\LoginAttemptRepository $loginAttemptRepo): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'users' => $userRepository->findAll(),
            'pendingInvitations' => $invitationRepository->findPending(15),
            'loginAttemptRepo' => $loginAttemptRepo,
        ]);
    }

    #[Route('/invite', name: 'admin_user_invite', methods: ['GET', 'POST'])]
    public function invite(
        Request $request,
        UserRepository $userRepository,
        UserInvitationRepository $invitationRepository,
        SettingRepository $settingRepository,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        HttpClientInterface $httpClient,
        EventLoggerService $logger
    ): Response {
        $form = $this->createForm(UserInviteType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
            $role = (string) ($data['role'] ?? 'ROLE_USER');

            if ($userRepository->findOneBy(['email' => $email])) {
                $this->addFlash('error', 'Un compte existe déjà avec cet email.');
                return $this->redirectToRoute('admin_user_invite');
            }

            $existingPending = $invitationRepository->findOneBy([
                'email' => $email,
                'acceptedAt' => null,
            ]);
            if ($existingPending && !$existingPending->isExpired()) {
                $this->addFlash('error', 'Une invitation active existe déjà pour cet email.');
                return $this->redirectToRoute('admin_user_invite');
            }

            $invitation = new UserInvitation();
            $invitation->setEmail($email);
            $invitation->setRole($role);
            $invitation->setToken(bin2hex(random_bytes(24)));
            $invitation->setExpiresAt(new \DateTimeImmutable('+7 days'));
            $invitation->setInvitedBy($this->getUser() instanceof User ? $this->getUser() : null);
            $entityManager->persist($invitation);
            $entityManager->flush();

            $inviteUrl = $this->generateUrl(
                'app_invitation_accept',
                ['token' => $invitation->getToken()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            $fromEmail = trim((string) $settingRepository->getValue('sender_email', 'noreply@akinaru.fr'));
            if ($fromEmail === '') {
                $this->addFlash('error', 'Email expéditeur manquant. Configure-le dans Paramètres.');
                return $this->redirectToRoute('settings_index');
            }
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', sprintf('Email expéditeur invalide: "%s". Corrige-le dans Paramètres.', $fromEmail));
                return $this->redirectToRoute('settings_index');
            }

            $mailerDsn = (string) ($_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? '');
            if ($mailerDsn === '' || str_starts_with($mailerDsn, 'null://')) {
                $logger->error('MAILER_DSN invalide pour envoi invitation: ' . ($mailerDsn === '' ? '[vide]' : $mailerDsn));
                $this->addFlash('error', 'MAILER_DSN invalide (null://null ou vide). Corrige le fichier .env / variables serveur.');
                return $this->redirectToRoute('admin_user_index');
            }

            $subject = 'Invitation AkiCloud';
            $htmlBody = $this->renderView('emails/invitation.html.twig', [
                'email_title' => $subject,
                'invite_url' => $inviteUrl,
                'expires_at' => $invitation->getExpiresAt(),
            ]);
            $textBody = "Invitation AkiCloud\n\n"
                . "Vous avez été invité à créer votre compte AkiCloud.\n"
                . "Créer mon compte: {$inviteUrl}\n"
                . 'Ce lien expire le ' . $invitation->getExpiresAt()?->format('d/m/Y H:i') . ".\n";

            $brevoApiKey = trim((string) ($_ENV['BREVO_API_KEY'] ?? $_SERVER['BREVO_API_KEY'] ?? getenv('BREVO_API_KEY') ?: ''));
            if ($brevoApiKey !== '') {
                try {
                    $response = $httpClient->request('POST', 'https://api.brevo.com/v3/smtp/email', [
                        'headers' => [
                            'accept' => 'application/json',
                            'api-key' => $brevoApiKey,
                            'content-type' => 'application/json',
                        ],
                        'json' => [
                            'sender' => [
                                'email' => $fromEmail,
                                'name' => 'AkiCloud',
                            ],
                            'to' => [
                                ['email' => $email],
                            ],
                            'subject' => $subject,
                            'htmlContent' => $htmlBody,
                            'textContent' => $textBody,
                            'headers' => [
                                'X-Mailin-track' => '0',
                                'X-Mailin-track-opens' => '0',
                                'X-Mailin-track-clicks' => '0',
                            ],
                        ],
                        'timeout' => 20,
                    ]);

                    $status = $response->getStatusCode();
                    if ($status >= 200 && $status < 300) {
                        $logger->info(sprintf('Invitation envoyée à %s via API Brevo (from: %s).', $email, $fromEmail));
                        $this->addFlash('success', 'Invitation envoyée avec succès (API).');
                        $this->addFlash('success', 'Lien invitation: ' . $inviteUrl);

                        return $this->redirectToRoute('admin_user_index');
                    }

                    $logger->error(sprintf('Échec API Brevo pour %s: HTTP %d - %s', $email, $status, $response->getContent(false)));
                } catch (Throwable $e) {
                    $logger->error(sprintf('Échec API Brevo pour %s - %s: %s', $email, $e::class, $e->getMessage()));
                }
            }

            $mail = (new Email())
                ->from($fromEmail)
                ->to($email)
                ->subject($subject)
                ->html($htmlBody)
                ->text($textBody);

            try {
                $mailer->send($mail);
                $logger->info(sprintf('Invitation envoyée à %s (from: %s).', $email, $fromEmail));
                $this->addFlash('success', 'Invitation envoyée avec succès.');
                $this->addFlash('success', 'Lien invitation: ' . $inviteUrl);
            } catch (Throwable $e) {
                $errorDetail = sprintf(
                    'Échec envoi invitation à %s (from: %s) - %s: %s',
                    $email,
                    $fromEmail,
                    $e::class,
                    $e->getMessage()
                );
                $logger->error($errorDetail);
                error_log('[AkiCloud][InviteMailer] ' . $errorDetail);

                $this->addFlash('error', 'Invitation créée mais envoi email échoué: ' . $e->getMessage());
                $this->addFlash('warning', 'Lien invitation (à copier): ' . $inviteUrl);
            }

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/invite.html.twig', [
            'form' => $form,
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

    #[Route('/invitation/{id}/delete', name: 'admin_user_invitation_delete', methods: ['POST'])]
    public function deleteInvitation(Request $request, UserInvitation $invitation, EntityManagerInterface $entityManager, EventLoggerService $logger): Response
    {
        if (!$this->isCsrfTokenValid('delete_invitation' . $invitation->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($invitation->isAccepted()) {
            $this->addFlash('warning', 'Cette invitation est déjà acceptée.');
            return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
        }

        $email = (string) $invitation->getEmail();
        $entityManager->remove($invitation);
        $entityManager->flush();

        $logger->warning(sprintf('Invitation supprimée: %s.', $email));
        $this->addFlash('success', sprintf('Invitation supprimée pour %s.', $email));

        return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
    }
}
