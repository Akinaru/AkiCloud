<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\SiteRepository;
use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SiteAccessController extends AbstractController
{
    public function __construct(
        private string $appPublicUrl = 'https://akinaru.fr'
    ) {
    }

    #[Route('/auth/site-access', name: 'app_site_access_check', methods: ['GET'])]
    public function check(Request $request, SiteRepository $siteRepository, SettingRepository $settingRepository): Response
    {
        $host = $this->resolveRequestedHost($request);
        $uri = $this->resolveRequestedUri($request);
        $scheme = $this->resolveRequestedScheme($request);

        if ($host === '') {
            return new Response('invalid host', Response::HTTP_BAD_REQUEST);
        }

        $baseDomain = (string) $settingRepository->getValue('base_domain', 'akinaru.fr');
        $site = $siteRepository->findOneByHost($host, $baseDomain);

        if ($site === null || !$site->isProtected()) {
            return new Response('ok', Response::HTTP_OK);
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $target = sprintf('%s://%s%s', $scheme, $host, $uri);
            $loginPath = $this->generateUrl('app_login', ['next' => $target]);
            $loginUrl = $this->toAppAbsoluteUrl($loginPath);

            return new RedirectResponse($loginUrl);
        }

        if ($this->isGranted('ROLE_ADMIN') || $site->isUserAuthorized($currentUser)) {
            return new Response('ok', Response::HTTP_OK);
        }

        $deniedPath = $this->generateUrl('app_site_access_denied', [
            'site' => $site->getName(),
            'host' => $host,
        ]);
        $deniedUrl = $this->toAppAbsoluteUrl($deniedPath);

        return new RedirectResponse($deniedUrl);
    }

    #[Route('/site-access-denied', name: 'app_site_access_denied', methods: ['GET'])]
    public function denied(Request $request): Response
    {
        return $this->render('security/site_access_denied.html.twig', [
            'site_name' => (string) $request->query->get('site', 'ce site'),
            'host' => (string) $request->query->get('host', ''),
        ]);
    }

    private function resolveRequestedHost(Request $request): string
    {
        $host = (string) ($request->headers->get('X-Forwarded-Host') ?: $request->getHost());
        $host = trim(explode(',', $host)[0] ?? '');
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return mb_strtolower($host);
    }

    private function resolveRequestedUri(Request $request): string
    {
        $uri = (string) ($request->headers->get('X-Forwarded-Uri') ?: $request->getRequestUri() ?: '/');

        if ($uri === '') {
            return '/';
        }

        return str_starts_with($uri, '/') ? $uri : '/' . $uri;
    }

    private function resolveRequestedScheme(Request $request): string
    {
        $proto = (string) ($request->headers->get('X-Forwarded-Proto') ?: $request->getScheme());
        $proto = trim(explode(',', $proto)[0] ?? 'https');

        return in_array(mb_strtolower($proto), ['http', 'https'], true) ? mb_strtolower($proto) : 'https';
    }

    private function toAppAbsoluteUrl(string $path): string
    {
        return rtrim($this->appPublicUrl, '/') . $path;
    }
}
