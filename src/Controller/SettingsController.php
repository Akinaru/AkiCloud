<?php

namespace App\Controller;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    #[Route('/', name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(Request $request, SettingRepository $settingRepository, EntityManagerInterface $entityManager): Response
    {
        $baseDomain = $settingRepository->findOneBy(['settingKey' => 'base_domain']);
        if (!$baseDomain) {
            $baseDomain = new Setting();
            $baseDomain->setSettingKey('base_domain');
            $baseDomain->setSettingValue('akinaru.fr');
            $entityManager->persist($baseDomain);
        }

        $senderEmail = $settingRepository->findOneBy(['settingKey' => 'sender_email']);
        if (!$senderEmail) {
            $senderEmail = new Setting();
            $senderEmail->setSettingKey('sender_email');
            $senderEmail->setSettingValue('noreply@akiagency.fr');
            $entityManager->persist($senderEmail);
        }

        $entityManager->flush();

        if ($request->isMethod('POST')) {
            $newDomain = $request->request->get('base_domain');
            if ($newDomain) {
                $baseDomain->setSettingValue($newDomain);
            }

            $newEmail = $request->request->get('sender_email');
            if ($newEmail) {
                $senderEmail->setSettingValue($newEmail);
            }

            $entityManager->flush();
            $this->addFlash('success', 'Paramètres mis à jour.');

            return $this->redirectToRoute('app_settings');
        }

        return $this->render('settings/index.html.twig', [
            'base_domain' => $baseDomain->getSettingValue(),
            'sender_email' => $senderEmail->getSettingValue(),
            'db_info' => [
                'host' => $_ENV['SHARED_DB_HOST'] ?? '127.0.0.1',
                'user' => $_ENV['SHARED_DB_USER'] ?? 'root',
            ]
        ]);
    }
}
