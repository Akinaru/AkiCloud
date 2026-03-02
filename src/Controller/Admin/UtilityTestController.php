<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class UtilityTestController extends AbstractController
{
    #[Route('/admin/test-db-connection', name: 'admin_test_db_connection', methods: ['POST'])]
    public function testDbConnection(): JsonResponse
    {
        $host = $_ENV['SHARED_DB_HOST'] ?? '127.0.0.1';
        $port = $_ENV['SHARED_DB_PORT'] ?? '3306';
        $user = $_ENV['SHARED_DB_USER'] ?? 'root';
        $pass = $_ENV['SHARED_DB_PASSWORD'] ?? '';

        try {
            $dsn = "mysql:host=$host;port=$port";
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ];

            new \PDO($dsn, $user, $pass, $options);

            return new JsonResponse([
                'success' => true,
                'message' => 'Connexion réussie au serveur MariaDB !'
            ]);
        } catch (\PDOException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur de connexion : ' . $e->getMessage()
            ], 400);
        }
    }

    #[Route('/admin/test-mailer', name: 'admin_test_mailer', methods: ['POST'])]
    public function testMailer(Request $request, MailerInterface $mailer, \App\Repository\SettingRepository $settings): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $emailAddress = $data['email'] ?? $this->getUser()->getEmail();
        $fromEmail = $settings->getValue('sender_email', 'noreply@akinaru.fr');

        if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Adresse email invalide.'
            ], 400);
        }

        try {
            $email = (new Email())
                ->from($fromEmail)
                ->to($emailAddress)
                ->subject('Test de configuration Mailer — AkiCloud')
                ->html('<div style="font-family: sans-serif; padding: 20px; border: 1px solid #eee; border-radius: 10px;">
                    <h2 style="color: #11069f;">Test de connexion réussi !</h2>
                    <p>Si vous recevez ce message, c\'est que votre configuration <strong>Infomaniak (MAILER_DSN)</strong> est fonctionnelle.</p>
                    <p style="font-size: 12px; color: #666;">Date du test : ' . date('d/m/Y H:i:s') . '</p>
                </div>');

            $mailer->send($email);

            return new JsonResponse([
                'success' => true,
                'message' => 'Email de test envoyé avec succès à ' . $emailAddress
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Échec de l\'envoi : ' . $e->getMessage()
            ], 400);
        }
    }
}
