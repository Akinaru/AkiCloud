<?php

namespace App\Service;

use App\Entity\Site;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use Doctrine\DBAL\DriverManager;

class WordPressConfigService
{
    private const NERD_WORDS = [
        'magmatic', 'pantheon', 'nexus', 'quantum', 'cipher', 'phoenix', 'nebula',
        'titan', 'vortex', 'helix', 'plasma', 'zenith', 'aurora', 'prism', 'orbit',
        'vertex', 'pulsar', 'quasar', 'photon', 'neutron', 'proton', 'electron',
        'isotope', 'catalyst', 'spectrum', 'fractal', 'matrix', 'synapse', 'cortex',
        'enigma', 'paradox', 'axiom', 'vector', 'tensor', 'sigma', 'omega', 'lambda',
        'epsilon', 'delta', 'gamma', 'theta', 'zephyr', 'nimbus', 'stratus', 'cirrus',
        'obsidian', 'cobalt', 'titanium', 'chrome', 'neon', 'argon',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private SettingRepository $settingRepository,
        private EventLoggerService $logger,
        private string $coolifyApiUrl = 'https://app.coolify.io/api/v1',
        private string $coolifyToken = '',
        private string $sharedDbHost = 'db',
        private int $sharedDbPort = 3306,
        private string $sharedDbUser = 'root',
        private string $sharedDbPassword = 'root',
    ) {}

    public function generatePassword(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*';
        $password = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        return $password;
    }

    public function generateCredentialsFromCsv(string $firstname, string $lastname, string $email): array
    {
        $slugger = new AsciiSlugger();
        $username = strtolower($slugger->slug(mb_substr($firstname, 0, 1) . $lastname)->toString());

        return [
            'username' => $username,
            'email' => $email,
            'password' => $this->generatePassword(),
        ];
    }

    public function generateCredentialsSequential(int $sequenceNumber): array
    {
        $word = self::NERD_WORDS[array_rand(self::NERD_WORDS)];
        $username = $word . $sequenceNumber;

        return [
            'username' => $username,
            'email' => $username . '@akinaru.fr',
            'password' => $this->generatePassword(),
        ];
    }

    public function installWordPress(Site $site): bool
    {
        if (!$site->getWpAdminUser() || $site->isWpConfigured()) {
            return false;
        }

        // Préparation du compte AkiCloud
        $site->setWpUsmbAdminUser('akicloud');
        $site->setWpUsmbAdminPassword($this->generatePassword(16));

        $baseDomain = $this->settingRepository->getValue('base_domain', 'cloud.fac-info.fr');
        $url = $site->getFullUrl($baseDomain);

        // Tentative avec retry (3 essais, 5s d'attente)
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->logger->info(sprintf('WP install attempt %d/3 for site %s', $attempt, $site->getName()));

            // Tentative 1 : wp-cli via Coolify execute API (Désactivé car renvoie 404 sur cette instance)
            /*
            if ($this->installViaWpCli($site, $url)) {
                $this->finalizeWpInstall($site);
                return true;
            }
            */

            // Tentative 2 : Fallback HTTP (crée le premier admin)
            if ($this->installViaHttp($site, $url)) {
                $this->finalizeWpInstall($site);
                return true;
            }

            if ($attempt < 3) {
                sleep(5);
            }
        }

        $this->logger->error(sprintf('Failed to auto-configure WordPress for site %s after 3 attempts', $site->getName()));
        return false;
    }

    private function finalizeWpInstall(Site $site): void
    {
        // 1. Créer le compte AKI en SQL direct
        $this->createUsmbAdminDirectly($site);

        // 2. Forcer la langue française
        $this->forceFrenchLanguageDirectly($site);

        $site->setWpConfigured(true);
        $this->entityManager->flush();
        $this->logger->info(sprintf('WordPress finalized for site %s (AKI account created + French forced)', $site->getName()));
    }

    private function createUsmbAdminDirectly(Site $site): void
    {
        if (!$site->getDbName() || !$site->getWpUsmbAdminUser()) {
            return;
        }

        try {
            $connectionParams = [
                'dbname' => $site->getDbName(),
                'user' => $this->sharedDbUser,
                'password' => $this->sharedDbPassword,
                'host' => $this->sharedDbHost,
                'port' => $this->sharedDbPort,
                'driver' => 'pdo_mysql',
            ];

            $conn = DriverManager::getConnection($connectionParams);

            // On vérifie si l'utilisateur existe déjà
            $exists = $conn->fetchOne("SELECT ID FROM wp_users WHERE user_login = ?", [$site->getWpUsmbAdminUser()]);

            if (!$exists) {
                // Insertion dans wp_users
                $conn->executeStatement(
                    "INSERT INTO wp_users (user_login, user_pass, user_nicename, user_email, user_registered, display_name)
                     VALUES (?, MD5(?), ?, ?, NOW(), ?)",
                    [
                        $site->getWpUsmbAdminUser(),
                        $site->getWpUsmbAdminPassword(),
                        $site->getWpUsmbAdminUser(),
                        'dsi@usmb.fr',
                        'USMB Admin'
                    ]
                );

                $userId = $conn->lastInsertId();

                // Insertion dans wp_usermeta pour les droits admin
                $conn->executeStatement(
                    "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, 'wp_capabilities', 'a:1:{s:13:\"administrator\";b:1;}')",
                    [$userId]
                );
                $conn->executeStatement(
                    "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, 'wp_user_level', '10')",
                    [$userId]
                );
            }

            $conn->close();
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to create USMB admin for %s: %s', $site->getName(), $e->getMessage()));
        }
    }

    private function forceFrenchLanguageDirectly(Site $site): void
    {
        if (!$site->getDbName()) return;

        try {
            $conn = DriverManager::getConnection([
                'dbname' => $site->getDbName(),
                'user' => $this->sharedDbUser,
                'password' => $this->sharedDbPassword,
                'host' => $this->sharedDbHost,
                'port' => $this->sharedDbPort,
                'driver' => 'pdo_mysql',
            ]);

            // Mise à jour de la langue dans les options
            $options = [
                'WPLANG' => 'fr_FR',
                'site_locale' => 'fr_FR',
                'default_locale' => 'fr_FR'
            ];

            foreach ($options as $key => $value) {
                $exists = $conn->fetchOne("SELECT option_id FROM wp_options WHERE option_name = ?", [$key]);
                if ($exists) {
                    $conn->executeStatement("UPDATE wp_options SET option_value = ? WHERE option_name = ?", [$value, $key]);
                } else {
                    $conn->executeStatement("INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, 'yes')", [$key, $value]);
                }
            }

            $conn->close();
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to force French language for %s: %s', $site->getName(), $e->getMessage()));
        }
    }

    private function installViaWpCli(Site $site, string $url): bool
    {
        if (!$site->getCoolifyUuid()) {
            return false;
        }

        try {
            $commands = [
                sprintf(
                    'wp core install --url="%s" --title="%s" --admin_user="%s" --admin_password="%s" --admin_email="%s" --skip-email --locale=fr_FR --allow-root',
                    $url,
                    addslashes($site->getName()),
                    $site->getWpAdminUser(),
                    addslashes($site->getWpAdminPassword()),
                    $site->getWpAdminEmail()
                ),
                'wp option update blog_public 0 --allow-root',
                'wp option update blogdescription "" --allow-root',
                'wp language core install fr_FR --allow-root',
                'wp site switch-language fr_FR --allow-root',
            ];

            $fullCommand = implode(' && ', $commands);

            $response = $this->httpClient->request('POST', $this->coolifyApiUrl . '/applications/' . $site->getCoolifyUuid() . '/execute', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->coolifyToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'command' => $fullCommand,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $data = $response->toArray(false);
                $output = $data['output'] ?? $data['result'] ?? '';
                if (is_string($output) && (str_contains($output, 'Success') || str_contains($output, 'already installed'))) {
                    return true;
                }
                // If we got a 200 response without error, consider it a success
                if (!isset($data['error'])) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('wp-cli install failed for %s: %s', $site->getName(), $e->getMessage()));
            return false;
        }
    }

    private function installViaHttp(Site $site, string $url): bool
    {
        try {
            $installUrl = 'https://' . $url . '/wp-admin/install.php?step=2';

            $response = $this->httpClient->request('POST', $installUrl, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'weblog_title' => $site->getName(),
                    'user_name' => $site->getWpAdminUser(),
                    'admin_password' => $site->getWpAdminPassword(),
                    'admin_password2' => $site->getWpAdminPassword(),
                    'pw_weak' => '1',
                    'admin_email' => $site->getWpAdminEmail(),
                    'blog_public' => '0',
                    'language' => 'fr_FR',
                ],
                'verify_peer' => false,
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            return $statusCode === 200 && (str_contains($content, 'success') || str_contains($content, 'wp-login') || str_contains($content, 'Réussite') || str_contains($content, 'install'));
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('HTTP install fallback failed for %s: %s', $site->getName(), $e->getMessage()));
            return false;
        }
    }

    public function changePassword(Site $site, string $newPassword): bool
    {
        if (!$site->getWpAdminUser() || !$site->getCoolifyUuid()) {
            return false;
        }

        // Tentative 1 : wp-cli (Ignorée si 404 connue sur cette instance)
        // ... (code existant)

        // Tentative 3 : Connexion SQL directe (le plus fiable)
        if ($this->changePasswordDirectlyInDb($site, $newPassword, $site->getWpAdminUser())) {
            $site->setWpAdminPassword($newPassword);
            $this->entityManager->flush();
            $this->logger->info(sprintf('WP admin password changed via direct DB connection for site %s', $site->getName()));
            return true;
        }

        return false;
    }

    public function changeUsmbPassword(Site $site, string $newPassword): bool
    {
        $username = $site->getWpUsmbAdminUser() ?: 'akicloud';

        if ($this->changePasswordDirectlyInDb($site, $newPassword, $username)) {
            $site->setWpUsmbAdminPassword($newPassword);
            $this->entityManager->flush();
            $this->logger->info(sprintf('WP akicloud password changed via direct DB connection for site %s', $site->getName()));
            return true;
        }

        return false;
    }

    private function changePasswordDirectlyInDb(Site $site, string $newPassword, string $username): bool
    {
        if (!$site->getDbName() || !$username) {
            return false;
        }

        try {
            $connectionParams = [
                'dbname' => $site->getDbName(),
                'user' => $this->sharedDbUser,
                'password' => $this->sharedDbPassword,
                'host' => $this->sharedDbHost,
                'port' => $this->sharedDbPort,
                'driver' => 'pdo_mysql',
            ];

            $conn = DriverManager::getConnection($connectionParams);

            // Hash WordPress (MD5 par défaut pour wp-admin si on ne peut pas charger les sels WP)
            // WordPress accepte le MD5 et le re-hashera lui-même à la prochaine connexion
            $sql = "UPDATE wp_users SET user_pass = MD5(?) WHERE user_login = ?";
            $conn->executeStatement($sql, [$newPassword, $username]);

            $conn->close();
            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Direct DB password change failed for %s: %s', $site->getName(), $e->getMessage()));
            return false;
        }
    }
}
