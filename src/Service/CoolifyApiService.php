<?php

namespace App\Service;

use App\Entity\Site;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class CoolifyApiService
{
    private const FACTORY_GIT_REPO = 'git@github.com:Akinaru/AkiCloud.git';

    public function __construct(
        private HttpClientInterface $httpClient,
        private EventLoggerService $logger,
        private string $coolifyApiUrl = 'https://app.coolify.io/api/v1',
        private string $coolifyToken = '',
        private string $coolifyServerUuid = 'localhost',
        private string $coolifyProjectUuid = '',
        private string $coolifyEnvironmentName = 'production',
        private string $coolifyPrivateKeyUuid = ''
    ) {
    }

    public function deploy(Site $site): array
    {
        $type = $site->getType();
        $uuid = null;

        try {
            if ($type === 'wordpress') {
                $this->logger->info('Création mode Docker Image (WordPress)');

                $body = [
                    'project_uuid' => $this->coolifyProjectUuid,
                    'server_uuid' => $this->coolifyServerUuid,
                    'environment_name' => $this->coolifyEnvironmentName,
                    'destination_uuid' => $this->coolifyServerUuid,
                    'name' => $site->getName(),
                    'domains' => sprintf('https://%s.akinaru.fr', $site->getSubdomain()),
                    'docker_registry_image_name' => 'wordpress',
                    'docker_registry_image_tag' => 'latest',
                    'ports_exposes' => '80',
                ];

                $response = $this->httpClient->request('POST', $this->coolifyApiUrl . '/applications/dockerimage', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->coolifyToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $body,
                ]);
            } else {
                $this->logger->info('Création mode Git Privé (Vierge)');

                $body = [
                    'name' => $site->getName(),
                    'domains' => sprintf('https://%s.akinaru.fr', $site->getSubdomain()),
                    'project_uuid' => $this->coolifyProjectUuid,
                    'server_uuid' => $this->coolifyServerUuid,
                    'environment_name' => $this->coolifyEnvironmentName,
                    'destination_uuid' => $this->coolifyServerUuid,
                    'git_branch' => 'prod',
                    'ports_exposes' => '80',
                    'private_key_uuid' => $this->coolifyPrivateKeyUuid,
                    'is_static' => false,
                    'git_repository' => self::FACTORY_GIT_REPO,
                    'build_pack' => 'nixpacks',
                    'base_directory' => '/templates_sites/vierge',
                    'publish_directory' => '/',
                ];

                $response = $this->httpClient->request('POST', $this->coolifyApiUrl . '/applications/private-deploy-key', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->coolifyToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $body,
                ]);
            }

            $data = $response->toArray();
            $uuid = $data['uuid'] ?? null;

            if (!$uuid) {
                throw new \Exception('UUID non reçu de Coolify');
            }

            $this->logger->info(sprintf('Application créée avec succès (UUID: %s).', $uuid));

            // Étape 2 : Variables d'Environnement
            $envs = [
                'APP_NAME' => $site->getName(),
            ];

            if ($type === 'wordpress') {
                $envs['WORDPRESS_DB_HOST'] = $site->getDbHost();
                $envs['WORDPRESS_DB_USER'] = $site->getDbUser();
                $envs['WORDPRESS_DB_PASSWORD'] = $site->getDbPassword();
                $envs['WORDPRESS_DB_NAME'] = $site->getDbName();
                $envs['WORDPRESS_LOCALE'] = 'fr_FR';
            }

            foreach ($envs as $key => $value) {
                $envBody = [
                    'key' => $key,
                    'value' => (string) $value,
                    'is_preview' => false,
                    'is_literal' => true
                ];

                // "is_build_time" est interdit pour les images Docker (WordPress)
                // mais requis/utile pour les déploiements Git (Static)
                if ($type !== 'wordpress') {
                    $envBody['is_build_time'] = false;
                }

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
            $coolifyStatus = strtolower($data['status'] ?? '');

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
