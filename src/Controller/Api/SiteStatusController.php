<?php

namespace App\Controller\Api;

use App\Entity\Site;
use App\Repository\SiteRepository;
use App\Service\CoolifyApiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class SiteStatusController extends AbstractController
{
    #[Route('/api/sites/check-status', name: 'api_sites_check_status', methods: ['POST'])]
    public function checkStatus(
        Request $request,
        SiteRepository $siteRepository,
        CoolifyApiService $coolifyApi,
        EntityManagerInterface $entityManager,
        \App\Service\EmailNotificationService $emailService,
        \App\Service\WordPressConfigService $wpConfigService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $siteIds = $data['ids'] ?? [];
        
        $results = [];
        $changed = false;

        foreach ($siteIds as $id) {
            $site = $siteRepository->find($id);
            if (!$site || !$site->getCoolifyUuid()) {
                continue;
            }

            $currentStatus = $site->getStatus();
            $realStatus = $coolifyApi->getResourceStatus($site->getCoolifyUuid());

            if ($realStatus === 'unknown') {
                $results[] = [
                    'id' => $site->getId(),
                    'status' => $currentStatus,
                    'is_finished' => false
                ];
                continue;
            }

            $finalStatus = $currentStatus;

            // Matrice de décision intelligente
            if ($realStatus === Site::STATUS_STOPPED) {
                if ($currentStatus === Site::STATUS_STOPPING) {
                    // Action utilisateur réussie
                    $finalStatus = Site::STATUS_STOPPED;
                } elseif ($currentStatus === Site::STATUS_STOPPED) {
                    // Déjà arrêté
                    $finalStatus = Site::STATUS_STOPPED;
                } elseif (in_array($currentStatus, [Site::STATUS_STARTING, Site::STATUS_BUILDING, Site::STATUS_RESTARTING])) {
                    // Patient : Tant qu'on est en transition vers Running, on ignore STOPPED (attente build/start)
                    $finalStatus = $currentStatus;
                } else {
                    // Était censé tourner (RUNNING) mais est arrêté -> CRASH
                    $finalStatus = Site::STATUS_FAILED;
                }
            } elseif ($realStatus === Site::STATUS_RUNNING) {
                if ($currentStatus === Site::STATUS_STOPPING) {
                    // On attend encore que ça s'arrête
                    $finalStatus = Site::STATUS_STOPPING;
                } else {
                    // Dans tous les autres cas (STARTING, BUILDING, STOPPED), s'il tourne, c'est bon
                    $finalStatus = Site::STATUS_RUNNING;
                }
            } elseif ($realStatus === Site::STATUS_FAILED) {
                // Si l'API dit explicitement FAILED, on suit
                $finalStatus = Site::STATUS_FAILED;
            } elseif ($realStatus === Site::STATUS_BUILDING) {
                // Si l'API est en cours de build/start, on reste en attente
                $finalStatus = Site::STATUS_BUILDING;
            }

            if ($finalStatus !== $currentStatus) {
                $site->setStatus($finalStatus);
                $changed = true;

                // SI LE SITE WP PASSE EN RUNNING ET QU'IL FAUT AUTO-CONFIGURER
                if ($finalStatus === Site::STATUS_RUNNING
                    && $site->getType() === 'wordpress'
                    && $site->getWpAdminUser()
                    && !$site->isWpConfigured()
                ) {
                    try {
                        $wpConfigService->installWordPress($site);
                    } catch (\Exception $e) {
                        error_log("Failed to auto-configure WordPress for site " . $site->getId() . ": " . $e->getMessage());
                    }
                }

                // SI LE SITE PASSE EN RUNNING ET QU'IL Y A UN EMAIL EN ATTENTE
                if ($finalStatus === Site::STATUS_RUNNING && $site->getPendingEmailTemplate()) {
                    try {
                        $emailService->sendSiteReadyEmail($site, $site->getPendingEmailTemplate());
                        // On vide le template pour ne pas renvoyer le mail
                        $site->setPendingEmailTemplate(null);
                    } catch (\Exception $e) {
                        // Log error but continue
                        error_log("Failed to send notification email for site " . $site->getId() . ": " . $e->getMessage());
                    }
                }
            }

            $results[] = [
                'id' => $site->getId(),
                'status' => $site->getStatus(),
                'is_finished' => !in_array($site->getStatus(), [
                    Site::STATUS_STARTING,
                    Site::STATUS_STOPPING,
                    Site::STATUS_RESTARTING,
                    Site::STATUS_BUILDING
                ]),
            ];
        }

        if ($changed) {
            $entityManager->flush();
        }

        return $this->json(['sites' => $results]);
    }
}
