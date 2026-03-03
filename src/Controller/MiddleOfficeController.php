<?php

namespace App\Controller;

use App\Entity\Site;
use App\Entity\User;
use App\Repository\SiteRepository;
use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/middle-office')]
class MiddleOfficeController extends AbstractController
{
    #[Route('', name: 'app_middle_office', methods: ['GET'])]
    public function index(SiteRepository $siteRepository, SettingRepository $settingRepository): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('Authentification requise.');
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_site_index');
        }

        $sites = $siteRepository->findAccessibleForUser($currentUser);
        $baseDomain = (string) $settingRepository->getValue('base_domain', 'cloud.akinaru.fr');

        return $this->render('middle_office/index.html.twig', [
            'sites' => $sites,
            'base_domain' => $baseDomain,
        ]);
    }

    #[Route('/site/{id}', name: 'app_middle_office_site_show', methods: ['GET'])]
    public function show(Site $site, SettingRepository $settingRepository): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('Authentification requise.');
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_site_show', ['id' => $site->getId()]);
        }

        if (!$site->isUserAuthorized($currentUser)) {
            throw $this->createAccessDeniedException('Vous n avez pas accès à ce site.');
        }

        return $this->render('middle_office/show.html.twig', [
            'site' => $site,
            'base_domain' => (string) $settingRepository->getValue('base_domain', 'cloud.akinaru.fr'),
        ]);
    }
}
