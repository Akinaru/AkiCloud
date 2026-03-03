<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\SettingRepository;
use App\Repository\SiteRepository;
use App\Repository\UserRepository;
use App\Service\SiteSecurityTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SiteSecurityController extends AbstractController
{
    #[Route('/security/site/login', name: 'app_site_security_login', methods: ['GET'])]
    public function loginGateway(
        Request $request,
        SiteRepository $siteRepository,
        SettingRepository $settingRepository,
        SiteSecurityTokenService $tokenService
    ): Response {
        $siteHost = $this->normalizeHost((string) $request->query->get('site', ''));
        $returnUrl = trim((string) $request->query->get('return_url', ''));

        if ($siteHost === '' || $returnUrl === '') {
            return new Response('Missing site or return_url.', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isSafeReturnUrl($returnUrl, $siteHost)) {
            return new Response('Invalid return_url host.', Response::HTTP_BAD_REQUEST);
        }

        $baseDomain = (string) $settingRepository->getValue('base_domain', 'cloud.akinaru.fr');
        $site = $siteRepository->findOneByHost($siteHost, $baseDomain);
        if ($site === null) {
            return new Response('Unknown site host.', Response::HTTP_NOT_FOUND);
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $loginUrl = $this->generateUrl('app_login', [
                'next' => $request->getUri(),
            ]);

            return new RedirectResponse($loginUrl);
        }

        if (!$site->isProtected()) {
            return new RedirectResponse($this->appendQuery($returnUrl, ['aki_status' => 'public']));
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$site->isUserAuthorized($currentUser)) {
            return new RedirectResponse($this->appendQuery($returnUrl, ['aki_status' => 'forbidden']));
        }

        $token = $tokenService->createToken($currentUser, $site, $siteHost);

        return new RedirectResponse($this->appendQuery($returnUrl, [
            'aki_token' => $token,
            'aki_status' => 'ok',
        ]));
    }

    #[Route('/api/security/site/validate-token', name: 'api_site_security_validate_token', methods: ['POST'])]
    public function validateToken(
        Request $request,
        SiteRepository $siteRepository,
        UserRepository $userRepository,
        SiteSecurityTokenService $tokenService
    ): JsonResponse {
        $configuredApiKey = $tokenService->getApiKey();
        if ($configuredApiKey !== '') {
            $providedApiKey = trim((string) $request->headers->get('X-Aki-Security-Key', ''));
            if ($providedApiKey === '' || !hash_equals($configuredApiKey, $providedApiKey)) {
                return $this->json(['ok' => false, 'error' => 'Invalid API key.'], Response::HTTP_UNAUTHORIZED);
            }
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $token = trim((string) ($payload['token'] ?? ''));
        $siteHost = $this->normalizeHost((string) ($payload['site'] ?? ''));
        if ($token === '') {
            return $this->json(['ok' => false, 'error' => 'Token is required.'], Response::HTTP_BAD_REQUEST);
        }

        $claims = $tokenService->validateToken($token, $siteHost !== '' ? $siteHost : null);
        if ($claims === null) {
            return $this->json(['ok' => false, 'error' => 'Invalid or expired token.'], Response::HTTP_UNAUTHORIZED);
        }

        $siteId = (int) ($claims['site_id'] ?? 0);
        $userId = (int) ($claims['sub'] ?? 0);
        if ($siteId <= 0 || $userId <= 0) {
            return $this->json(['ok' => false, 'error' => 'Invalid token payload.'], Response::HTTP_UNAUTHORIZED);
        }

        $site = $siteRepository->find($siteId);
        $user = $userRepository->find($userId);
        if ($site === null || $user === null) {
            return $this->json(['ok' => false, 'error' => 'Unknown site or user.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($site->isProtected() && !$site->isUserAuthorized($user) && !$this->isAdminUser($user)) {
            return $this->json(['ok' => false, 'error' => 'User not allowed for this site.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'ok' => true,
            'site' => [
                'id' => $site->getId(),
                'name' => $site->getName(),
                'host' => (string) ($claims['site_host'] ?? ''),
                'protected' => $site->isProtected(),
            ],
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName(),
                'roles' => $user->getRoles(),
            ],
            'exp' => (int) ($claims['exp'] ?? 0),
        ]);
    }

    private function isAdminUser(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    private function normalizeHost(string $host): string
    {
        $value = trim($host);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = preg_replace('#/.*$#', '', $value) ?? $value;
        $value = preg_replace('/:\d+$/', '', $value) ?? $value;
        $value = trim($value, '.');

        return mb_strtolower($value);
    }

    private function isSafeReturnUrl(string $returnUrl, string $siteHost): bool
    {
        $parts = parse_url($returnUrl);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = $this->normalizeHost((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return $host !== '' && $host === $siteHost;
    }

    /**
     * @param array<string, string> $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        foreach ($params as $key => $value) {
            $query[$key] = $value;
        }

        $path = (string) ($parts['path'] ?? '/');
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return sprintf('%s://%s%s%s?%s%s', $scheme, $host, $port, $path, http_build_query($query), $fragment);
    }
}
