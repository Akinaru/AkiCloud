<?php

namespace App\Service;

use App\Entity\Site;
use App\Repository\SettingRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class CoolifyApiService
{
    private const FACTORY_GIT_REPO = 'git@github.com:Akinaru/AkiCloud.git';

    public function __construct(
        private HttpClientInterface $httpClient,
        private SettingRepository $settingRepository,
        private EventLoggerService $logger,
        private string $coolifyApiUrl = 'https://app.coolify.io/api/v1',
        private string $coolifyToken = '',
        private string $coolifyServerUuid = 'localhost',
        private string $coolifyProjectUuid = '',
        private string $coolifyEnvironmentName = 'production',
        private string $coolifyPrivateKeyUuid = '',
        private string $appPublicUrl = 'https://akinaru.fr'
    ) {
    }

    public function deploy(Site $site): array
    {
        $type = (string) ($site->getType() ?: 'php');
        $uuid = null;

        try {
            $this->logger->info(sprintf('Creation mode Git prive (%s)', $type));

            $isLocalVolume = $site->getDeploymentSource() === Site::SOURCE_LOCAL_VOLUME;
            $hasCustomRepository = (bool) $site->getGitRepository();
            $gitRepository = (string) ($hasCustomRepository ? $site->getGitRepository() : self::FACTORY_GIT_REPO);
            $publishDirectory = (string) ($site->getPublishDirectory() ?: '/');
            $isStatic = $type === 'static';
            $baseDirectory = ($hasCustomRepository || $isLocalVolume) ? '/' : '/templates_sites/vierge';
            $baseDomain = $this->normalizeBaseDomain((string) $this->settingRepository->getValue('base_domain', 'akinaru.fr'));
            $host = $site->getFullUrl($baseDomain);
            $domain = sprintf('https://%s', $host);

            $body = [
                'name' => $site->getName(),
                'domains' => $domain,
                'force_domain_override' => true,
                'project_uuid' => $this->coolifyProjectUuid,
                'server_uuid' => $this->coolifyServerUuid,
                'environment_name' => $this->coolifyEnvironmentName,
                'destination_uuid' => $this->coolifyServerUuid,
                'git_branch' => 'main',
                'ports_exposes' => (string) ($site->getPort() ?: 80),
                'private_key_uuid' => $this->coolifyPrivateKeyUuid,
                'is_static' => $isStatic,
                'git_repository' => $gitRepository,
                'build_pack' => 'nixpacks',
                'base_directory' => $baseDirectory,
                'publish_directory' => $publishDirectory,
            ];

            if ($isLocalVolume) {
                $body['git_repository'] = self::FACTORY_GIT_REPO;
                $body['base_directory'] = '/templates_sites/vierge';
            }

            $response = $this->httpClient->request('POST', $this->coolifyApiUrl . '/applications/private-deploy-key', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
            ]);
            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf(
                    'Creation application Coolify en echec (HTTP %d): %s',
                    $statusCode,
                    $this->extractApiError($rawBody)
                ));
            }

            $data = json_decode($rawBody, true);
            if (!is_array($data)) {
                throw new \RuntimeException('Reponse Coolify invalide lors de la creation application.');
            }
            $uuid = $data['uuid'] ?? null;

            if (!$uuid) {
                throw new \Exception('UUID non reçu de Coolify');
            }

            $this->logger->info(sprintf('Application créée avec succès (UUID: %s).', $uuid));

            // Étape 2 : Variables d'Environnement
            $envs = [
                'APP_NAME' => $site->getName(),
                'APP_RUNTIME' => $type,
            ];

            foreach ($envs as $key => $value) {
                $envBody = [
                    'key' => $key,
                    'value' => (string) $value,
                    'is_preview' => false,
                    'is_literal' => true
                ];

                $this->httpClient->request('POST', $this->coolifyApiUrl . '/applications/' . $uuid . '/envs', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->coolifyToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $envBody,
                ]);
            }

            // Étape 3 : Déclenchement du déploiement
            return $this->startResource($uuid);

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            if ($e instanceof HttpExceptionInterface) {
                $responseContent = $e->getResponse()->getContent(false);
                $errorMessage .= ' | Réponse serveur: ' . $responseContent;
            }

            $this->logger->error(sprintf('Échec du cycle de déploiement Coolify pour "%s" : %s', $site->getName(), $errorMessage));

            return [
                'status' => 'error',
                'message' => $errorMessage,
            ];
        }
    }

    public function getResourceStatus(string $uuid): string
    {
        try {
            $response = $this->httpClient->request('GET', $this->coolifyApiUrl . '/applications/' . $uuid, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return 'unknown';
            }

            $content = $response->getContent(false);
            $data = json_decode($content, true);
            if (!is_array($data)) {
                return 'unknown';
            }

            $coolifyStatus = $this->extractCoolifyStatus($data);

            // 1. Si le statut contient failed (explicite) ou degraded -> STATUS_FAILED
            if (str_contains($coolifyStatus, 'failed') || str_contains($coolifyStatus, 'degraded')) {
                return Site::STATUS_FAILED;
            }

            // 2. Si le statut contient restarting, starting, queued ou building -> STATUS_BUILDING
            if (str_contains($coolifyStatus, 'restarting') || str_contains($coolifyStatus, 'starting') || str_contains($coolifyStatus, 'queued') || str_contains($coolifyStatus, 'building')) {
                return Site::STATUS_BUILDING;
            }

            // 3. CHANGEMENT CRITIQUE : Si le statut contient exited ou stopped -> STATUS_STOPPED
            if (str_contains($coolifyStatus, 'exited') || str_contains($coolifyStatus, 'stopped')) {
                return Site::STATUS_STOPPED;
            }

            // 4. Si le statut contient running ou healthy -> STATUS_RUNNING
            if (str_contains($coolifyStatus, 'running') || str_contains($coolifyStatus, 'healthy')) {
                return Site::STATUS_RUNNING;
            }

            // 5. Si unhealthy tout court (et pas exited) -> on considère qu'il tourne encore
            if (str_contains($coolifyStatus, 'unhealthy')) {
                return Site::STATUS_RUNNING;
            }

            return 'unknown';

        } catch (\Exception $e) {
            $this->logger->error(sprintf('Impossible de récupérer le statut Coolify pour %s : %s', $uuid, $e->getMessage()));
            return 'unknown';
        }
    }

    private function extractCoolifyStatus(array $data): string
    {
        $candidates = [
            $data['status'] ?? null,
            $data['application_status'] ?? null,
            $data['deployment_status'] ?? null,
            $data['health'] ?? null,
            $data['state'] ?? null,
            $data['current_status'] ?? null,
            $data['application']['status'] ?? null,
            $data['application']['state'] ?? null,
            $data['application']['health'] ?? null,
            $data['application']['docker_status'] ?? null,
            $data['application']['application_status'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $normalized = trim(mb_strtolower($candidate));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    public function stopResource(string $uuid): bool
    {
        try {
            $response = $this->httpClient->request('POST', $this->coolifyApiUrl . '/applications/' . $uuid . '/stop', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                ],
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Échec de l\'arrêt pour %s : %s', $uuid, $e->getMessage()));
            return false;
        }
    }

    public function restartResource(string $uuid): bool
    {
        try {
            $response = $this->httpClient->request('POST', $this->coolifyApiUrl . '/applications/' . $uuid . '/restart', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                ],
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Échec du redémarrage pour %s : %s', $uuid, $e->getMessage()));
            return false;
        }
    }

    public function startResource(string $uuid): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->coolifyApiUrl . '/deploy', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                ],
                'query' => [
                    'uuid' => $uuid,
                ],
            ]);
            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);
            if ($statusCode < 200 || $statusCode >= 300) {
                return [
                    'status' => 'error',
                    'message' => sprintf(
                        'Deploiement refuse par Coolify (HTTP %d): %s',
                        $statusCode,
                        $this->extractApiError($rawBody)
                    ),
                ];
            }

            return [
                'status' => 'success',
                'uuid' => $uuid,
                'message' => 'Déploiement/Démarrage initié.',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function extractApiError(?string $rawBody): string
    {
        $rawBody = is_string($rawBody) ? trim($rawBody) : '';
        if ($rawBody === '') {
            return 'Aucun detail retourne par l API.';
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return mb_substr($rawBody, 0, 500);
        }

        foreach (['message', 'error', 'detail'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                return trim($decoded[$key]);
            }
        }

        return mb_substr($rawBody, 0, 500);
    }

    private function normalizeBaseDomain(string $domain): string
    {
        $domain = trim($domain);
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;
        $domain = trim($domain, '.');

        return $domain !== '' ? mb_strtolower($domain) : 'akinaru.fr';
    }

    public function syncProtection(Site $site): void
    {
        $uuid = $site->getCoolifyUuid();
        if (!$uuid) {
            return;
        }

        $baseDomain = $this->normalizeBaseDomain((string) $this->settingRepository->getValue('base_domain', 'akinaru.fr'));
        $host = $site->getFullUrl($baseDomain);
        if ($host === '') {
            return;
        }

        $this->syncProtectionForUuid($site, $uuid, $host);
    }

    private function syncProtectionForUuid(Site $site, string $uuid, string $host): void
    {
        try {
            $response = $this->httpClient->request('GET', $this->coolifyApiUrl . '/applications/' . $uuid, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning(sprintf(
                    'Impossible de lire la config de labels Coolify pour %s (HTTP %d): %s',
                    $site->getName(),
                    $statusCode,
                    $this->extractApiError($rawBody)
                ));
                return;
            }

            $data = json_decode($rawBody, true);
            if (!is_array($data)) {
                $this->logger->warning(sprintf('Reponse invalide lors de la lecture des labels Coolify pour %s.', $site->getName()));
                return;
            }

            $existingLabels = (string) ($data['custom_labels'] ?? '');
            $managedLabels = $site->isProtected() ? $this->buildProtectionLabels($site, $uuid, $host) : '';
            $nextLabels = $this->mergeManagedCustomLabels($existingLabels, $managedLabels);

            if (trim($nextLabels) === trim($existingLabels)) {
                return;
            }

            $updateResponse = $this->httpClient->request('PATCH', $this->coolifyApiUrl . '/applications/' . $uuid, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $this->buildApplicationUpdatePayload($data, $site, $host, $nextLabels),
            ]);

            $updateStatus = $updateResponse->getStatusCode();
            if ($updateStatus < 200 || $updateStatus >= 300) {
                $updateRawBody = $updateResponse->getContent(false);
                $this->logger->warning(sprintf(
                    'Mise a jour des labels de protection echouee pour %s (HTTP %d): %s',
                    $site->getName(),
                    $updateStatus,
                    $this->extractApiError($updateRawBody)
                ));
                return;
            }

            $this->logger->info(sprintf(
                'Protection %s pour "%s" (%s).',
                $site->isProtected() ? 'activee' : 'desactivee',
                $site->getName(),
                $host
            ));
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Impossible de synchroniser la protection Coolify pour "%s": %s',
                $site->getName(),
                $e->getMessage()
            ));
        }
    }

    private function buildProtectionLabels(Site $site, string $uuid, string $host): string
    {
        $routerSuffix = mb_strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $uuid) ?? $uuid);
        $routerName = sprintf('akiprotect-%s', trim($routerSuffix, '-'));
        $middlewareName = sprintf('akiauth-%s', trim($routerSuffix, '-'));
        $forwardAuthUrl = rtrim($this->appPublicUrl, '/') . '/auth/site-access';
        $port = (string) ($site->getPort() ?: 80);

        $labels = [
            'traefik.enable=true',
            sprintf('traefik.http.routers.%s.rule=Host(`%s`)', $routerName, $host),
            sprintf('traefik.http.routers.%s.priority=1000', $routerName),
            sprintf('traefik.http.routers.%s.tls=true', $routerName),
            sprintf('traefik.http.routers.%s.service=%s', $routerName, $routerName),
            sprintf('traefik.http.routers.%s.middlewares=%s', $routerName, $middlewareName),
            sprintf('traefik.http.services.%s.loadbalancer.server.port=%s', $routerName, $port),
            sprintf('traefik.http.middlewares.%s.forwardauth.address=%s', $middlewareName, $forwardAuthUrl),
            sprintf('traefik.http.middlewares.%s.forwardauth.trustForwardHeader=true', $middlewareName),
        ];

        return implode("\n", $labels);
    }

    private function buildApplicationUpdatePayload(array $data, Site $site, string $host, string $customLabels): array
    {
        $domain = sprintf('https://%s', $host);

        return [
            'name' => (string) ($data['name'] ?? $site->getName() ?? 'site'),
            'domains' => (string) ($data['domains'] ?? $data['fqdn'] ?? $domain),
            'force_domain_override' => true,
            'project_uuid' => (string) ($data['project_uuid'] ?? $this->coolifyProjectUuid),
            'server_uuid' => (string) ($data['server_uuid'] ?? $this->coolifyServerUuid),
            'environment_name' => (string) ($data['environment_name'] ?? $this->coolifyEnvironmentName),
            'destination_uuid' => (string) ($data['destination_uuid'] ?? $this->coolifyServerUuid),
            'git_branch' => (string) ($data['git_branch'] ?? 'main'),
            'ports_exposes' => (string) ($data['ports_exposes'] ?? $site->getPort() ?? 80),
            'private_key_uuid' => (string) ($data['private_key_uuid'] ?? $this->coolifyPrivateKeyUuid),
            'is_static' => (bool) ($data['is_static'] ?? ($site->getType() === 'static')),
            'git_repository' => (string) ($data['git_repository'] ?? self::FACTORY_GIT_REPO),
            'build_pack' => (string) ($data['build_pack'] ?? 'nixpacks'),
            'base_directory' => (string) ($data['base_directory'] ?? '/templates_sites/vierge'),
            'publish_directory' => (string) ($data['publish_directory'] ?? '/'),
            'custom_labels' => $customLabels,
        ];
    }

    private function mergeManagedCustomLabels(string $existingLabels, string $managedLabels): string
    {
        $startMarker = '# AKICLOUD-PROTECTION-START';
        $endMarker = '# AKICLOUD-PROTECTION-END';

        $cleaned = preg_replace(
            '/' . preg_quote($startMarker, '/') . '.*?' . preg_quote($endMarker, '/') . '/s',
            '',
            $existingLabels
        );
        $cleaned = trim((string) $cleaned);

        if ($managedLabels === '') {
            return $cleaned;
        }

        $block = $startMarker . "\n" . trim($managedLabels) . "\n" . $endMarker;

        return $cleaned === '' ? $block : $cleaned . "\n" . $block;
    }

    public function deleteResource(string $uuid): bool
    {
        try {
            $response = $this->httpClient->request('DELETE', $this->coolifyApiUrl . '/applications/' . $uuid, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                ],
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Échec de la suppression Coolify pour %s : %s', $uuid, $e->getMessage()));
            return false;
        }
    }
}
