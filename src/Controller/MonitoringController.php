<?php

namespace App\Controller;

use App\Repository\SystemEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Service\ServerMonitoringService;
use App\Service\EventLoggerService;

#[IsGranted('ROLE_ADMIN')]
class MonitoringController extends AbstractController
{
    #[Route('/monitoring', name: 'app_monitoring')]
    public function index(Request $request, SystemEventRepository $systemEventRepo, EntityManagerInterface $entityManager, ServerMonitoringService $monitoringService, EventLoggerService $logger): Response
    {
        $stats = $monitoringService->getServerStats();

        // On peut logger un event si les ressources sont critiques (ex: RAM > 90%)
        if ($stats['ram_used'] / $stats['ram_total'] > 0.9) {
            $logger->warning(sprintf('Utilisation RAM critique : %.1f Go utilisés sur %.1f Go.', $stats['ram_used'], $stats['ram_total']));
        }

        // Pagination for System Events
        $pageEvents = $request->query->getInt('page_events', 1);
        $limitEvents = $request->query->getInt('limit_events', 10);

        $resultEvents = $systemEventRepo->findPaginated($pageEvents, $limitEvents, 'createdAt', 'DESC');
        $totalEvents = $resultEvents['total'];
        $totalPagesEvents = ceil($totalEvents / $limitEvents);

        return $this->render('monitoring/index.html.twig', [
            'stats' => $stats,
            'events' => $resultEvents['items'],
            'totalEvents' => $totalEvents,
            'pageEvents' => $pageEvents,
            'limitEvents' => $limitEvents,
            'totalPagesEvents' => $totalPagesEvents,
        ]);
    }

    #[Route('/api/monitoring/stats', name: 'api_monitoring_stats', methods: ['GET'])]
    public function getStats(ServerMonitoringService $monitoringService): Response
    {
        return $this->json($monitoringService->getServerStats());
    }

    #[Route('/monitoring/clear', name: 'app_monitoring_clear', methods: ['POST'])]
    public function clearEvents(SystemEventRepository $systemEventRepo, EntityManagerInterface $entityManager): Response
    {
        $systemEventRepo->createQueryBuilder('e')
            ->delete()
            ->getQuery()
            ->execute();

        $this->addFlash('success', 'Tous les événements système ont été supprimés.');

        return $this->redirectToRoute('app_monitoring');
    }
}
