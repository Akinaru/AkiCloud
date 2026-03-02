<?php

namespace App\EventSubscriber;

use App\Entity\LoginAttempt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $user = $event->getAuthenticatedToken()->getUser();

        $attempt = new LoginAttempt();
        $attempt->setEmail($user->getUserIdentifier());
        $attempt->setIpAddress($request->getClientIp() ?? '127.0.0.1');
        $attempt->setSuccessful(true);

        $this->entityManager->persist($attempt);
        $this->entityManager->flush();
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $email = $request->request->get('_username', 'inconnu');

        $attempt = new LoginAttempt();
        $attempt->setEmail($email);
        $attempt->setIpAddress($request->getClientIp() ?? '127.0.0.1');
        $attempt->setSuccessful(false);

        $this->entityManager->persist($attempt);
        $this->entityManager->flush();
    }
}
