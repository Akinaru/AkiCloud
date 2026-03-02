<?php

namespace App\Controller;

use App\Entity\Site;
use App\Form\SiteType;
use App\Repository\SettingRepository;
use App\Repository\SiteRepository;
use App\Service\CoolifyApiService;
use App\Service\EventLoggerService;
use App\Entity\User;
use App\Service\WordPressConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/site')]
final class SiteController extends AbstractController
{
    #[Route('/bulk-create', name: 'app_site_bulk_create', methods: ['GET'])]
    public function bulkCreateRedirect(): Response
    {
        return $this->redirectToRoute('app_site_new');
    }

    #[Route('/new', name: 'app_site_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        CoolifyApiService $coolifyApi,
        EventLoggerService $logger
    ): Response {
        $site = new Site();
        $site->setStatus(Site::STATUS_BUILDING);
        $site->setPort(80);
        $site->setPublishDirectory('/');

        $form = $this->createForm(SiteType::class, $site);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ownerDifferent = (bool) $form->get('ownerDifferent')->getData();
            if (!$ownerDifferent) {
                $currentUser = $this->getUser();
                if ($currentUser instanceof User) {
                    $site->setOwnerFirstname((string) $currentUser->getFirstName());
                    $site->setOwnerLastname((string) $currentUser->getLastName());
                    $site->setOwnerEmail((string) $currentUser->getEmail());
                }
            }

            $entityManager->persist($site);
            $entityManager->flush();

            $result = $coolifyApi->deploy($site);
            if (($result['status'] ?? 'error') === 'success' && isset($result['uuid'])) {
                $site->setCoolifyUuid((string) $result['uuid']);
                $entityManager->flush();

                $logger->info(sprintf('Site cree et deploy lance: %s', $site->getName()));
                $this->addFlash('success', sprintf('Site "%s" cree. Deploiement lance.', $site->getName()));

                return $this->redirectToRoute('app_site_show', ['id' => $site->getId()]);
            }

            $site->setStatus(Site::STATUS_FAILED);
            $entityManager->flush();

            $message = (string) ($result['message'] ?? 'Erreur inconnue');
            $logger->error(sprintf('Echec deploy pour "%s": %s', $site->getName(), $message));
            $this->addFlash('error', sprintf('Creation ok, mais deploy en echec: %s', $message));

            return $this->redirectToRoute('app_site_show', ['id' => $site->getId()]);
        }

        return $this->render('site/new.html.twig', [
            'site' => $site,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/wp-change-password', name: 'app_site_wp_change_password', methods: ['POST'])]
    public function wpChangePassword(
        Request $request,
        Site $site,
        WordPressConfigService $wpConfigService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $newPassword = $data['password'] ?? null;

        if (!$newPassword) {
            return $this->json(['success' => false, 'message' => 'Mot de passe requis.'], 400);
        }

        if (!$site->getWpAdminUser()) {
            return $this->json(['success' => false, 'message' => 'Aucun compte admin WordPress configure.'], 400);
        }

        $success = $wpConfigService->changePassword($site, $newPassword);

        if ($success) {
            return $this->json(['success' => true, 'message' => 'Mot de passe WordPress modifie avec succes.']);
        }

        return $this->json(['success' => false, 'message' => 'Echec de la modification du mot de passe.'], 500);
    }

    #[Route('/{id}/wp-change-password-usmb', name: 'app_site_wp_change_password_usmb', methods: ['POST'])]
    public function wpChangePasswordUsmb(
        Request $request,
        Site $site,
        WordPressConfigService $wpConfigService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $newPassword = $data['password'] ?? null;

        if (!$newPassword) {
            return $this->json(['success' => false, 'message' => 'Mot de passe requis.'], 400);
        }

        $success = $wpConfigService->changeUsmbPassword($site, $newPassword);

        if ($success) {
            return $this->json(['success' => true, 'message' => 'Mot de passe technique AkiCloud modifie avec succes.']);
        }

        return $this->json(['success' => false, 'message' => 'Echec de la modification du mot de passe technique.'], 500);
    }

    #[Route('/bulk-action', name: 'app_site_bulk_action', methods: ['POST'])]
    public function bulkAction(Request $request, SiteRepository $siteRepository, CoolifyApiService $coolifyApi, EntityManagerInterface $entityManager, EventLoggerService $logger): Response
    {
        $ids = $request->request->all('ids');
        $action = $request->request->get('action');

        if (empty($ids) || empty($action)) {
            $this->addFlash('error', 'Aucun site selectionne ou action invalide.');
            return $this->redirectToRoute('app_site_index');
        }

        $sites = $siteRepository->findBy(['id' => $ids]);
        $count = 0;

        foreach ($sites as $site) {
            if (!$site->getCoolifyUuid() && $action !== 'delete') {
                continue;
            }

            switch ($action) {
                case 'start':
                    $coolifyApi->startResource($site->getCoolifyUuid());
                    $site->setStatus(Site::STATUS_STARTING);
                    break;
                case 'stop':
                    $coolifyApi->stopResource($site->getCoolifyUuid());
                    $site->setStatus(Site::STATUS_STOPPING);
                    break;
                case 'restart':
                    $coolifyApi->restartResource($site->getCoolifyUuid());
                    $site->setStatus(Site::STATUS_RESTARTING);
                    break;
                case 'redeploy':
                    $coolifyApi->startResource($site->getCoolifyUuid());
                    $site->setStatus(Site::STATUS_BUILDING);
                    break;
                case 'delete':
                    if ($site->getCoolifyUuid()) {
                        $coolifyApi->deleteResource($site->getCoolifyUuid());
                    }
                    $name = $site->getName();
                    $entityManager->remove($site);
                    $logger->warning(sprintf('Site supprime via action groupee: %s.', $name));
                    break;
            }
            $count++;
        }

        $entityManager->flush();

        $actionLabels = [
            'start' => 'demarres',
            'stop' => 'arretes',
            'restart' => 'redemarres',
            'redeploy' => 'redeployes',
            'delete' => 'supprimes',
        ];

        if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            return $this->json([
                'success' => true,
                'message' => sprintf('%d sites ont ete %s.', $count, $actionLabels[$action] ?? 'traites'),
            ]);
        }

        $this->addFlash('success', sprintf('%d sites ont ete %s avec succes.', $count, $actionLabels[$action] ?? 'traites'));

        return $this->redirectToRoute('app_site_index');
    }

    #[Route(name: 'app_site_index', methods: ['GET'])]
    public function index(Request $request, SiteRepository $siteRepository, SettingRepository $settingRepository): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 25);
        $sort = $request->query->get('sort', 'id');
        $direction = strtoupper($request->query->get('direction', 'DESC'));

        if (!in_array($sort, ['id', 'name', 'subdomain', 'type', 'status'], true)) {
            $sort = 'id';
        }
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'DESC';
        }

        $result = $siteRepository->findWithPagination($page, $limit, $sort, $direction);
        $totalSites = $result['total'];
        $totalPages = ceil($totalSites / $limit);

        return $this->render('site/index.html.twig', [
            'sites' => $result['items'],
            'base_domain' => $settingRepository->getValue('base_domain', 'cloud.fac-info.fr'),
            'currentPage' => $page,
            'limit' => $limit,
            'sort' => $sort,
            'direction' => $direction,
            'totalPages' => $totalPages,
            'totalSites' => $totalSites,
        ]);
    }

    #[Route('/{id}', name: 'app_site_show', methods: ['GET'])]
    public function show(Site $site, SettingRepository $settingRepository): Response
    {
        return $this->render('site/show.html.twig', [
            'site' => $site,
            'base_domain' => $settingRepository->getValue('base_domain', 'cloud.fac-info.fr'),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_site_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Site $site, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SiteType::class, $site);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', sprintf('Site "%s" modifie avec succes.', $site->getName()));
            return $this->redirectToRoute('app_site_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('site/edit.html.twig', [
            'site' => $site,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_site_delete', methods: ['POST'])]
    public function delete(Request $request, Site $site, EntityManagerInterface $entityManager, EventLoggerService $logger, CoolifyApiService $coolifyApi): Response
    {
        if ($this->isCsrfTokenValid('delete' . $site->getId(), $request->getPayload()->getString('_token'))) {
            if ($site->getCoolifyUuid()) {
                $coolifyApi->deleteResource($site->getCoolifyUuid());
            }

            $name = $site->getName();
            $entityManager->remove($site);
            $entityManager->flush();

            $logger->warning(sprintf('Site supprime: %s.', $name));
            $this->addFlash('success', sprintf('Site "%s" supprime.', $name));
        }

        return $this->redirectToRoute('app_site_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/start', name: 'app_site_start', methods: ['GET'])]
    public function start(Request $request, Site $site, CoolifyApiService $coolifyApi, EntityManagerInterface $entityManager): Response
    {
        if ($site->getCoolifyUuid()) {
            $coolifyApi->startResource($site->getCoolifyUuid());
            $site->setStatus(Site::STATUS_STARTING);
            $entityManager->flush();

            if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
                return $this->json(['success' => true, 'message' => 'Demarrage initie']);
            }

            $this->addFlash('success', sprintf('Action de demarrage envoyee pour le site "%s".', $site->getName()));
        }

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_site_index'));
    }

    #[Route('/{id}/stop', name: 'app_site_stop', methods: ['GET'])]
    public function stop(Request $request, Site $site, CoolifyApiService $coolifyApi, EntityManagerInterface $entityManager): Response
    {
        if ($site->getCoolifyUuid()) {
            $coolifyApi->stopResource($site->getCoolifyUuid());
            $site->setStatus(Site::STATUS_STOPPING);
            $entityManager->flush();

            if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
                return $this->json(['success' => true, 'message' => 'Arret initie']);
            }

            $this->addFlash('success', sprintf('Action d arret envoyee pour le site "%s".', $site->getName()));
        }

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_site_index'));
    }

    #[Route('/{id}/restart', name: 'app_site_restart', methods: ['GET'])]
    public function restart(Request $request, Site $site, CoolifyApiService $coolifyApi, EntityManagerInterface $entityManager): Response
    {
        if ($site->getCoolifyUuid()) {
            $coolifyApi->restartResource($site->getCoolifyUuid());
            $site->setStatus(Site::STATUS_RESTARTING);
            $entityManager->flush();

            if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
                return $this->json(['success' => true, 'message' => 'Redemarrage initie']);
            }

            $this->addFlash('success', sprintf('Action de redemarrage envoyee pour le site "%s".', $site->getName()));
        }

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_site_index'));
    }

    #[Route('/{id}/redeploy', name: 'app_site_redeploy', methods: ['GET'])]
    public function redeploy(Request $request, Site $site, CoolifyApiService $coolifyApi, EntityManagerInterface $entityManager): Response
    {
        if ($site->getCoolifyUuid()) {
            $coolifyApi->startResource($site->getCoolifyUuid());
            $site->setStatus(Site::STATUS_BUILDING);
            $entityManager->flush();

            if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
                return $this->json(['success' => true, 'message' => 'Redeploiement initie']);
            }

            $this->addFlash('success', sprintf('Redeploiement force initie pour le site "%s".', $site->getName()));
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
