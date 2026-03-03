<?php

namespace App\Service;

use App\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\DriverManager;

class DatabaseManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $sharedDbHost = 'db',
        private int $sharedDbPort = 3306,
        private string $sharedDbUser = 'root',
        private string $sharedDbPassword = ''
    ) {
    }

    public function createDatabase(Site $site): void
    {
        $dbName = 'site_' . $site->getId() . '_' . bin2hex(random_bytes(3));
        $dbUser = 'siteu_' . $site->getId() . '_' . bin2hex(random_bytes(2));
        $dbPassword = bin2hex(random_bytes(16));

        $connectionParams = [
            'dbname' => 'mysql',
            'user' => $this->sharedDbUser,
            'password' => $this->sharedDbPassword,
            'host' => $this->sharedDbHost,
            'port' => $this->sharedDbPort,
            'driver' => 'pdo_mysql',
        ];

        $conn = DriverManager::getConnection($connectionParams);

        try {
            // Create database
            $conn->executeStatement(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $dbName));
            
            // Create user and grant privileges
            // In MySQL 8+, CREATE USER and GRANT are separate
            $conn->executeStatement(sprintf("CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s'", $dbUser, $dbPassword));
            $conn->executeStatement(sprintf("GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%%'", $dbName, $dbUser));
            $conn->executeStatement("FLUSH PRIVILEGES");

            $site->setDbName($dbName);
            $site->setDbUser($dbUser);
            $site->setDbPassword($dbPassword);
            $site->setDbHost($this->sharedDbHost);

            $this->entityManager->flush();
        } finally {
            $conn->close();
        }
    }
}
