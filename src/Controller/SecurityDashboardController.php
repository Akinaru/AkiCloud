<?php

namespace App\Controller;

use App\Repository\LoginAttemptRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class SecurityDashboardController extends AbstractController
{
    #[Route('/security-dashboard', name: 'app_security_dashboard')]
    public function index(Request $request, LoginAttemptRepository $loginAttemptRepo, UserRepository $userRepo): Response
    {
        $connections24h = $loginAttemptRepo->countLast24h();
        $failedAttempts = $loginAttemptRepo->countFailedLast24h();

        // Count active sessions from PHP session files
        $sessionPath = session_save_path() ?: sys_get_temp_dir();
        $activeSessions = 0;
        if (is_dir($sessionPath)) {
            $now = time();
            $maxLifetime = (int) ini_get('session.gc_maxlifetime') ?: 1440;
            foreach (glob($sessionPath . '/sess_*') as $file) {
                if (($now - filemtime($file)) < $maxLifetime) {
                    $activeSessions++;
                }
            }
        }

        $stats = [
            'connections_24h' => $connections24h,
            'failed_attempts' => $failedAttempts,
            'active_sessions' => $activeSessions,
        ];

        // Pagination for Login Attempts
        $pageAttempts = $request->query->getInt('page_attempts', 1);
        $limitAttempts = $request->query->getInt('limit_attempts', 10);

        $resultAttempts = $loginAttemptRepo->findPaginated($pageAttempts, $limitAttempts, 'attemptedAt', 'DESC');
        $totalAttempts = $resultAttempts['total'];
        $totalPagesAttempts = ceil($totalAttempts / $limitAttempts);

        // Pagination for Users
        $pageUsers = $request->query->getInt('page_users', 1);
        $limitUsers = $request->query->getInt('limit_users', 10);

        $resultUsers = $userRepo->findPaginated($pageUsers, $limitUsers, 'createdAt', 'DESC');
        $totalUsers = $resultUsers['total'];
        $totalPagesUsers = ceil($totalUsers / $limitUsers);

        return $this->render('security_dashboard/index.html.twig', [
            'stats' => $stats,
            'attempts' => $resultAttempts['items'],
            'totalAttempts' => $totalAttempts,
            'pageAttempts' => $pageAttempts,
            'limitAttempts' => $limitAttempts,
            'totalPagesAttempts' => $totalPagesAttempts,

            'users' => $resultUsers['items'],
            'totalUsers' => $totalUsers,
            'pageUsers' => $pageUsers,
            'limitUsers' => $limitUsers,
            'totalPagesUsers' => $totalPagesUsers,
            'loginAttemptRepo' => $loginAttemptRepo, // Pass repository to get last login in Twig
        ]);
    }
}
