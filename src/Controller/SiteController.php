<?php

namespace App\Controller;

use App\Entity\Site;
use App\Form\SiteType;
use App\Repository\SiteRepository;
use App\Repository\SettingRepository;
use App\Service\CoolifyApiService;
use App\Service\DatabaseManager;
use App\Service\EventLoggerService;
use App\Service\WordPressConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/site')]
final class SiteController extends AbstractController
{
    #[Route('/bulk-create', name: 'app_site_bulk_create', methods: ['GET', 'POST'])]
    public function bulkCreate(
        Request $request,
        EntityManagerInterface $entityManager,
        CoolifyApiService $coolifyApi,
        SettingRepository $settingRepository,
        EventLoggerService $logger,
        DatabaseManager $databaseManager,
        WordPressConfigService $wpConfigService
    ): Response {
        $form = $this->createFormBuilder()
            ->add('is_list', ChoiceType::class, [
                'label' => 'Mode de création',
                'choices' => [
                    'Via une liste (Import CSV)' => true,
                    'Séquentiel (Simple)' => false,
                ],
                'expanded' => true,
                'data' => true
            ])
            ->add('prefix', TextType::class, [
                'label' => 'Modèle d\'URL',
                'required' => true,
                'attr' => ['placeholder' => 'ex: wordpress-cours-[liste]'],
                'help' => 'Utilisez [liste] pour insérer le nom généré. En mode simple, [liste] sera remplacé par un numéro.'
            ])
            ->add('count', IntegerType::class, [
                'label' => 'Nombre de sites (Mode Simple)',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 500],
                'data' => 10
            ])
            ->add('csv_file', \Symfony\Component\Form\Extension\Core\Type\FileType::class, [
                'label' => 'Fichier CSV (Mode Liste)',
                'required' => false,
                'help' => 'Colonnes requises : nom;prenom;email'
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type de stack',
                'choices' => [
                    'WordPress' => 'wordpress',
                    'Vierge (Statique)' => 'static',
                ],
            ])
            ->add('auto_configure_wp', CheckboxType::class, [
                'label' => 'Configurer WordPress et créer les comptes admin automatiquement',
                'required' => false,
                'data' => true,
            ])
            ->add('email_template', \Symfony\Bridge\Doctrine\Form\Type\EntityType::class, [
                'class' => \App\Entity\EmailTemplate::class,
                'choice_label' => 'name',
                'label' => 'Modèle d\'email de notification',
                'placeholder' => 'Ne pas envoyer d\'email',
                'required' => false,
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $isList = $data['is_list'];
            $pattern = $data['prefix'];
            $type = $data['type'];
            $emailTemplate = $data['email_template'];
            $autoConfigureWp = $data['auto_configure_wp'] ?? false;

            error_log("BulkCreate: isList=" . ($isList ? 'true' : 'false') . ", pattern=$pattern");

            if (!str_contains($pattern, '[liste]')) {
                error_log("BulkCreate Error: pattern missing [liste]");
                $this->addFlash('error', 'Le modèle d\'URL doit contenir la balise [liste].');
                return $this->redirectToRoute('app_site_bulk_create');
            }

            $slugger = new \Symfony\Component\String\Slugger\AsciiSlugger();
            $sitesToCreate = [];
            $skippedRows = 0;

            if ($isList) {
                /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $file */
                $file = $data['csv_file'];
                if (!$file) {
                    error_log("BulkCreate Error: csv_file is null");
                    $this->addFlash('error', 'Veuillez uploader un fichier CSV.');
                    return $this->redirectToRoute('app_site_bulk_create');
                }

                error_log("BulkCreate: processing CSV file: " . $file->getClientOriginalName());

                if (($handle = fopen($file->getRealPath(), "r")) !== FALSE) {
                    $header = fgetcsv($handle, 1000, ";"); // nom;prenom;email
                    while (($row = fgetcsv($handle, 1000, ";")) !== FALSE) {
                        if (count($row) < 3 || empty(trim($row[0])) || empty(trim($row[1])) || empty(trim($row[2]))) {
                            error_log("BulkCreate: skipping row due to missing data: " . json_encode($row));
                            $skippedRows++;
                            continue;
                        }
                        $nom = trim($row[0]);
                        $prenom = trim($row[1]);
                        $email = trim($row[2]);

                        $idList = strtolower($slugger->slug(substr($prenom, 0, 1) . $nom));
                        $sitesToCreate[] = [
                            'name' => str_replace('[liste]', $idList, $pattern),
                            'firstname' => $prenom,
                            'lastname' => $nom,
                            'email' => $email
                        ];
                    }
                    fclose($handle);
                }
                error_log("BulkCreate: identified " . count($sitesToCreate) . " sites to create from CSV, skipped $skippedRows rows");
            } else {
                $count = $data['count'] ?: 1;
                for ($i = 1; $i <= $count; $i++) {
                    $sitesToCreate[] = [
                        'name' => str_replace('[liste]', (string)$i, $pattern),
                        'firstname' => null,
                        'lastname' => null,
                        'email' => null
                    ];
                }
            }

            foreach ($sitesToCreate as $index => $siteData) {
                $site = new Site();
                $site->setName($siteData['name']);
                $site->setType($type);
                $site->setStatus(Site::STATUS_BUILDING);
                $site->setOwnerFirstname($siteData['firstname']);
                $site->setOwnerLastname($siteData['lastname']);
                $site->setOwnerEmail($siteData['email']);
                $site->setPendingEmailTemplate($emailTemplate);

                $entityManager->persist($site);
                $entityManager->flush();

                if ($type === 'wordpress') {
                    $databaseManager->createDatabase($site);

                    if ($autoConfigureWp) {
                        if ($isList && $siteData['firstname'] && $siteData['lastname'] && $siteData['email']) {
                            $wpCreds = $wpConfigService->generateCredentialsFromCsv(
                                $siteData['firstname'],
                                $siteData['lastname'],
                                $siteData['email']
                            );
                        } else {
                            $wpCreds = $wpConfigService->generateCredentialsSequential($index + 1);
                        }

                        $site->setWpAdminUser($wpCreds['username']);
                        $site->setWpAdminPassword($wpCreds['password']);
                        $site->setWpAdminEmail($wpCreds['email']);
                        $entityManager->flush();
                    }
                }

                $result = $coolifyApi->deploy($site);
                if (isset($result['uuid'])) {
                    $site->setCoolifyUuid($result['uuid']);
                }
            }

            $entityManager->flush();

            $logger->info(sprintf('Création en masse réussie : %d sites créés.', count($sitesToCreate)));
            $this->addFlash('success', sprintf('%d sites créés et déploiement initié.', count($sitesToCreate)));

            if ($skippedRows > 0) {
                $this->addFlash('warning', sprintf('%d lignes du CSV ont été ignorées car elles étaient incomplètes.', $skippedRows));
            }

            return $this->redirectToRoute('app_site_index');
        }

        return $this->render('site/bulk_create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    private function sendNotificationEmail(
        \Symfony\Component\Mailer\MailerInterface $mailer,
        Site $site,
        \App\Entity\EmailTemplate $template,
        SettingRepository $settingRepository
    ): void {
        $baseDomain = $settingRepository->getValue('base_domain', 'cloud.fac-info.fr');
        $fromEmail = $settingRepository->getValue('sender_email', 'noreply@akinaru.fr');
        $content = $template->getContent();
        $placeholders = [
            '[prenom]' => $site->getOwnerFirstname(),
            '[nom]' => $site->getOwnerLastname(),
            '[email]' => $site->getOwnerEmail(),
            '[url]' => 'https://' . $site->getFullUrl($baseDomain),
            '[site_name]' => $site->getName(),
        ];

        $content = str_replace(array_keys($placeholders), array_values($placeholders), $content);
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $template->getSubject());

        $email = (new \Symfony\Component\Mime\Email())
            ->from($fromEmail)
            ->to($site->getOwnerEmail())
            ->subject($subject)
            ->html($content);

        try {
            $mailer->send($email);
        } catch (\Exception $e) {
            // Log error but don't stop deployment
        }
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
            return $this->json(['success' => false, 'message' => 'Aucun compte admin WordPress configuré.'], 400);
        }

        $success = $wpConfigService->changePassword($site, $newPassword);

        if ($success) {
            return $this->json(['success' => true, 'message' => 'Mot de passe WordPress modifié avec succès.']);
        }

        return $this->json(['success' => false, 'message' => 'Échec de la modification du mot de passe.'], 500);
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
            return $this->json(['success' => true, 'message' => 'Mot de passe technique AkiCloud modifié avec succès.']);
        }

        return $this->json(['success' => false, 'message' => 'Échec de la modification du mot de passe technique.'], 500);
    }

    #[Route('/bulk-action', name: 'app_site_bulk_action', methods: ['POST'])]
    public function bulkAction(Request $request, SiteRepository $siteRepository, CoolifyApiService $coolifyApi, EntityManagerInterface $entityManager, EventLoggerService $logger): Response
    {
        $ids = $request->request->all('ids');
        $action = $request->request->get('action');

        if (empty($ids) || empty($action)) {
            $this->addFlash('error', 'Aucun site sélectionné ou action invalide.');
            return $this->redirectToRoute('app_site_index');
        }

        $sites = $siteRepository->findBy(['id' => $ids]);
        $count = 0;

        foreach ($sites as $site) {
            if (!$site->getCoolifyUuid() && $action !== 'delete') continue;

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
                    $logger->warning(sprintf('Site supprimé via action groupée : %s.', $name));
                    break;
            }
            $count++;
        }

        $entityManager->flush();

        $actionLabels = [
            'start' => 'démarrés',
            'stop' => 'arrêtés',
            'restart' => 'redémarrés',
            'redeploy' => 'redéployés',
            'delete' => 'supprimés'
        ];

        if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            return $this->json([
                'success' => true,
                'message' => sprintf('%d sites ont été %s.', $count, $actionLabels[$action] ?? 'traités')
            ]);
        }

        $this->addFlash('success', sprintf('%d sites ont été %s avec succès.', $count, $actionLabels[$action] ?? 'traités'));

        return $this->redirectToRoute('app_site_index');
    }

    #[Route(name: 'app_site_index', methods: ['GET'])]
    public function index(Request $request, SiteRepository $siteRepository, SettingRepository $settingRepository): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 25);
        $sort = $request->query->get('sort', 'id');
        $direction = strtoupper($request->query->get('direction', 'DESC'));

        if (!in_array($sort, ['id', 'name', 'subdomain', 'type', 'status'])) {
            $sort = 'id';
        }
        if (!in_array($direction, ['ASC', 'DESC'])) {
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
            $this->addFlash('success', sprintf('Site "%s" modifié avec succès.', $site->getName()));
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
        if ($this->isCsrfTokenValid('delete'.$site->getId(), $request->getPayload()->getString('_token'))) {
            if ($site->getCoolifyUuid()) {
                $coolifyApi->deleteResource($site->getCoolifyUuid());
            }

            $name = $site->getName();
            $entityManager->remove($site);
            $entityManager->flush();

            $logger->warning(sprintf('Site supprimé : %s.', $name));
            $this->addFlash('success', sprintf('Site "%s" supprimé.', $name));
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
                return $this->json(['success' => true, 'message' => 'Démarrage initié']);
            }

            $this->addFlash('success', sprintf('Action de démarrage envoyée pour le site "%s".', $site->getName()));
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
                return $this->json(['success' => true, 'message' => 'Arrêt initié']);
            }

            $this->addFlash('success', sprintf('Action d\'arrêt envoyée pour le site "%s".', $site->getName()));
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
                return $this->json(['success' => true, 'message' => 'Redémarrage initié']);
            }

            $this->addFlash('success', sprintf('Action de redémarrage envoyée pour le site "%s".', $site->getName()));
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
                return $this->json(['success' => true, 'message' => 'Redéploiement initié']);
            }

            $this->addFlash('success', sprintf('Redéploiement forcé initié pour le site "%s".', $site->getName()));
        }
        return $this->redirectToRoute('app_dashboard');
    }
}
