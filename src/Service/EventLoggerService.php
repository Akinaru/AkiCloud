<?php

namespace App\Service;

use App\Entity\SystemEvent;
use Doctrine\ORM\EntityManagerInterface;

class EventLoggerService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function log(string $type, string $message): void
    {
        $event = new SystemEvent();
        $event->setType($type);
        $event->setMessage($message);
        $event->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }

    public function info(string $message): void
    {
        $this->log(SystemEvent::TYPE_INFO, $message);
    }

    public function warning(string $message): void
    {
        $this->log(SystemEvent::TYPE_WARNING, $message);
    }

    public function error(string $message): void
    {
        $this->log(SystemEvent::TYPE_ERROR, $message);
    }
}
