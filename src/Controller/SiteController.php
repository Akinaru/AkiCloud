<?php

namespace App\Controller;

use App\Entity\Site;
use App\Form\SiteType;
use App\Repository\SettingRepository;
use App\Repository\SiteRepository;
use App\Service\CoolifyApiService;
use App\Service\DatabaseManager;
use App\Service\EventLoggerService;
use App\Entity\User;
use App\Service\WordPressConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/site')]
#[IsGranted('ROLE_ADMIN')]
final class SiteController extends AbstractController
{
    #[Route('/bulk-create', name: 'app_site_bulk_create', methods: ['GET'])]
    public function bulkCreateRedirect(): Response
    {
        return $this->redirectToRoute('app_site_new');
    }

    #[Route('/new', name: 'app_site_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        SettingRepository $settingRepository,
        SiteRepository $siteRepository,
        CoolifyApiService $coolifyApi,
        DatabaseManager $databaseManager,
        EventLoggerService $logger
    ): Response {
        $site = new Site();
        $site->setStatus(Site::STATUS_BUILDING);
        $site->setPort(80);
        $site->setPublishDirectory('/');
        $site->setCreateDatabase(true);

        $form = $this->createForm(SiteType::class, $site);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $baseDomain = (string) $settingRepository->getValue('base_domain', 'cloud.akinaru.fr');
            $customDomain = $this->normalizeHost($site->getCustomDomain());
            $site->setCustomDomain($customDomain);

            $effectiveDomain = $customDomain ?: $site->getFullUrl($baseDomain);
            if (!$effectiveDomain) {
                $this->addFlash('error', 'Impossible de résoudre le domaine du site.');
                return $this->redirectToRoute('app_site_new');
            }

            if ($customDomain) {
                $existingDomainSite = $siteRepository->findOneBy(['customDomain' => $customDomain]);
                if ($existingDomainSite) {
                    $this->addFlash('error', 'Ce nom de domaine custom est déjà utilisé.');
                    return $this->redirectToRoute('app_site_new');
                }
            } else {
                $existingSubdomainSite = $siteRepository->findOneBy(['subdomain' => $site->getSubdomain(), 'customDomain' => null]);
                if ($existingSubdomainSite) {
                    $this->addFlash('error', 'Ce nom de site génère un sous-domaine déjà utilisé. Choisis un autre nom.');
                    return $this->redirectToRoute('app_site_new');
                }
            }

            if ($site->getDeploymentSource() === Site::SOURCE_GIT_PUBLIC) {
                $repo = trim((string) $site->getGitRepository());
                if ($repo === '') {
                    $this->addFlash('error', 'Le dépôt Git public est requis pour le mode Git.');
                    return $this->redirectToRoute('app_site_new');
                }
                if (!preg_match('/^(https?:\/\/|git@).+/i', $repo)) {
                    $this->addFlash('error', 'Le dépôt Git doit être une URL publique valide (https://... ou git@...).');
                    return $this->redirectToRoute('app_site_new');
                }
                $site->setGitRepository($repo);
            } else {
                $site->setGitRepository(null);
                $volumePath = '/var/www/akicloud/' . $effectiveDomain;
                $site->setLocalVolumePath($volumePath);
                try {
                    $this->ensureLocalVolumeScaffold($volumePath, $site->getName() ?? 'Projet');
                } catch (\Throwable $e) {
                    $logger->error(sprintf('Création volume local échouée pour "%s": %s', $site->getName(), $e->getMessage()));
                    $this->addFlash('error', 'Impossible de créer le volume local: vérifie les permissions sur /var/www/akicloud.');
                    return $this->redirectToRoute('app_site_new');
                }
            }

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

            if ($site->isCreateDatabase()) {
                try {
                    $databaseManager->createDatabase($site);
                } catch (\Throwable $e) {
                    $logger->error(sprintf('Création base échouée pour "%s": %s', $site->getName(), $e->getMessage()));
                    $this->addFlash('warning', 'Site créé, mais la base de données n a pas pu être créée.');
                }
            }

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

    private function normalizeHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $value = trim($host);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = preg_replace('#/.*$#', '', $value) ?? $value;
        $value = trim($value, '.');

        return $value !== '' ? mb_strtolower($value) : null;
    }

    private function ensureLocalVolumeScaffold(string $path, string $siteName): void
    {
        $fs = new Filesystem();
        if (!$fs->exists($path)) {
            $fs->mkdir($path, 0775);
        }

        $indexPath = rtrim($path, '/') . '/index.html';
        if ($fs->exists($indexPath)) {
            return;
        }

        $content = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8')
            . '</title><style>body{margin:0;font-family:Arial,sans-serif;background:#1a1a1a;color:#ecdbba;display:flex;min-height:100vh;align-items:center;justify-content:center}main{max-width:680px;padding:28px;text-align:center;border:1px solid #2d4263;background:#191919;border-radius:12px}h1{margin:0 0 10px;font-size:26px;color:#ecdbba}p{margin:0;color:#bfb09c}code{color:#c84b31}</style></head><body><main><h1>Projet en construction</h1><p>Le dossier local est prêt: <code>'
            . htmlspecialchars($path, ENT_QUOTES, 'UTF-8')
            . '</code></p></main></body></html>';
        $fs->dumpFile($indexPath, $content);
    }

    #[Route('/{id}/wp-change-password', name: 'app_site_wp_change_password', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
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
    #[IsGranted('ROLE_ADMIN')]
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
    #[IsGranted('ROLE_ADMIN')]
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
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('Authentification requise.');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
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

        $result = $isAdmin
            ? $siteRepository->findWithPagination($page, $limit, $sort, $direction)
            : $siteRepository->findWithPaginationForUser($currentUser, $page, $limit, $sort, $direction);
        $totalSites = $result['total'];
        $totalPages = ceil($totalSites / $limit);

        return $this->render('site/index.html.twig', [
            'sites' => $result['items'],
            'canManage' => $isAdmin,
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
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('Authentification requise.');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (!$isAdmin && !$site->isUserAuthorized($currentUser)) {
            throw $this->createAccessDeniedException('Vous n avez pas accès à ce site.');
        }

        return $this->render('site/show.html.twig', [
            'site' => $site,
            'canManage' => $isAdmin,
            'base_domain' => $settingRepository->getValue('base_domain', 'cloud.fac-info.fr'),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_site_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, Site $site, EntityManagerInterface $entityManager, CoolifyApiService $coolifyApi): Response
    {
        $form = $this->createForm(SiteType::class, $site);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            if ($site->getCoolifyUuid()) {
                $coolifyApi->syncProtection($site);
            }

            $this->addFlash('success', sprintf('Site "%s" modifie avec succes.', $site->getName()));
            return $this->redirectToRoute('app_site_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('site/edit.html.twig', [
            'site' => $site,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_site_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
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
    #[IsGranted('ROLE_ADMIN')]
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
    #[IsGranted('ROLE_ADMIN')]
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
    #[IsGranted('ROLE_ADMIN')]
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
    #[IsGranted('ROLE_ADMIN')]
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
