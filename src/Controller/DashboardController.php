<?php

namespace App\Controller;

use App\Repository\SiteRepository;
use App\Repository\SettingRepository;
use App\Entity\User;
use App\Service\ServerMonitoringService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(SiteRepository $siteRepository, SettingRepository $settingRepository, ServerMonitoringService $monitoringService): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('Authentification requise.');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $allSites = $isAdmin
            ? $siteRepository->findAll()
            : $siteRepository->findAccessibleForUser($currentUser);
        $baseDomain = $settingRepository->getValue('base_domain', 'cloud.fac-info.fr');

        $systemStats = $monitoringService->getServerStats();

        // Stats
        $stats = [
            'total' => count($allSites),
            'running' => count(array_filter($allSites, fn($s) => $s->getStatus() === 'running')),
            'cpu_usage' => $systemStats['cpu'] . '%', 
        ];

        return $this->render('dashboard/index.html.twig', [
            'totalSites' => count($allSites),
            'stats' => $stats,
            'isAdmin' => $isAdmin,
            'base_domain' => $baseDomain,
        ]);
    }
}
