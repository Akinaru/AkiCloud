<?php

namespace App\Service;

use App\Entity\Site;
use App\Entity\EmailTemplate;
use App\Repository\SettingRepository;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class EmailNotificationService
{
    public function __construct(
        private MailerInterface $mailer,
        private SettingRepository $settingRepository,
        private Packages $assetPackages,
        private RequestStack $requestStack,
    ) {}

    public function sendSiteReadyEmail(Site $site, EmailTemplate $template): void
    {
        if (!$site->getOwnerEmail()) {
            return;
        }

        $baseDomain = $this->settingRepository->getValue('base_domain', 'cloud.akinaru.fr');
        $fromEmail = $this->settingRepository->getValue('sender_email', 'noreply@akiagency.fr');

        $placeholders = [
            '[prenom]' => $site->getOwnerFirstname(),
            '[nom]' => $site->getOwnerLastname(),
            '[email]' => $site->getOwnerEmail(),
            '[url]' => 'https://' . $site->getFullUrl($baseDomain),
            '[site_name]' => $site->getName(),
            '[wp_user]' => $site->getWpAdminUser() ?? '',
            '[wp_password]' => $site->getWpAdminPassword() ?? '',
        ];

        $content = str_replace(array_keys($placeholders), array_values($placeholders), $template->getContent());
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $template->getSubject());

        $siteUrl = 'https://' . $site->getFullUrl($baseDomain);
        $logoUrl = $this->getAbsoluteLogoUrl();
        $htmlBody = $this->wrapInDefaultTemplate($content, $siteUrl, $site->getName(), $site->getOwnerFirstname(), $site->getOwnerLastname(), $site->getOwnerEmail(), $logoUrl, $site->getWpAdminUser(), $site->getWpAdminPassword());

        $email = (new Email())
            ->from($fromEmail)
            ->to($site->getOwnerEmail())
            ->subject($subject)
            ->html($htmlBody);

        $this->mailer->send($email);
    }

    private function getAbsoluteLogoUrl(): string
    {
        $assetPath = $this->assetPackages->getUrl('img/Logo_AKI.png');
        $request = $this->requestStack->getCurrentRequest();
        if ($request && !str_starts_with($assetPath, 'http')) {
            return $request->getSchemeAndHttpHost() . $assetPath;
        }
        return $assetPath;
    }

    private function wrapInDefaultTemplate(string $content, string $url, string $siteName, ?string $firstname, ?string $lastname, ?string $ownerEmail, string $logoUrl, ?string $wpUser = null, ?string $wpPassword = null): string
    {
        $contentHtml = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        // **bold**, __underline__, *italic* (order matters: ** before *)
        $contentHtml = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $contentHtml);
        $contentHtml = preg_replace('/__(.+?)__/', '<u>$1</u>', $contentHtml);
        $contentHtml = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $contentHtml);
        $contentHtml = nl2br($contentHtml);
        $urlEsc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $siteNameEsc = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $ownerName = htmlspecialchars(trim(($firstname ?? '') . ' ' . ($lastname ?? '')), ENT_QUOTES, 'UTF-8');
        $ownerEmailEsc = htmlspecialchars($ownerEmail ?? '', ENT_QUOTES, 'UTF-8');
        $wpUserEsc = htmlspecialchars($wpUser ?? '', ENT_QUOTES, 'UTF-8');
        $wpPasswordEsc = htmlspecialchars($wpPassword ?? '', ENT_QUOTES, 'UTF-8');

        $wpCredentialsRows = '';
        if ($wpUser) {
            $wpCredentialsRows = <<<WPROWS
                            <tr>
                                <td style="padding:3px 10px 3px 0;color:#5c6484;vertical-align:top;">Identifiant WP</td>
                                <td style="padding:3px 0;color:#151d32;font-weight:600;">{$wpUserEsc}</td>
                            </tr>
                            <tr>
                                <td style="padding:3px 10px 3px 0;color:#5c6484;vertical-align:top;">Mot de passe WP</td>
                                <td style="padding:3px 0;color:#151d32;font-weight:600;font-family:monospace;">{$wpPasswordEsc}</td>
                            </tr>
WPROWS;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;background:linear-gradient(to bottom,#11069f 0%,#03214D 100%);font-family:'Sora',-apple-system,BlinkMacSystemFont,sans-serif;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:48px 0;">
<tr><td align="center">

<!-- Logo -->
<table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
<tr><td align="center">
    <img src="{$logoUrl}" alt="AKI" style="max-height:70px;width:auto;">
</td></tr>
</table>

<!-- Card -->
<table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:14px;box-shadow:0 12px 36px rgba(15,25,50,.14),0 4px 10px rgba(15,25,50,.07);overflow:hidden;max-width:520px;width:100%;">
    <tr>
        <td style="padding:32px 32px 24px;font-size:15px;line-height:1.7;color:#151d32;">
            {$contentHtml}
        </td>
    </tr>
    <tr>
        <td style="padding:0 32px 24px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fd;border:1px solid #e2e6f0;border-radius:10px;">
                <tr>
                    <td style="padding:16px 20px;">
                        <p style="margin:0 0 10px;font-weight:700;font-size:13px;color:#151d32;">Informations de connexion</p>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
                            <tr>
                                <td style="padding:3px 10px 3px 0;color:#5c6484;width:130px;vertical-align:top;">Adresse du site</td>
                                <td style="padding:3px 0;color:#151d32;font-weight:600;"><a href="{$urlEsc}" style="color:#11069f;text-decoration:none;">{$urlEsc}</a></td>
                            </tr>
                            <tr>
                                <td style="padding:3px 10px 3px 0;color:#5c6484;vertical-align:top;">Nom du projet</td>
                                <td style="padding:3px 0;color:#151d32;font-weight:600;">{$siteNameEsc}</td>
                            </tr>
                            <tr>
                                <td style="padding:3px 10px 3px 0;color:#5c6484;vertical-align:top;">Propriétaire</td>
                                <td style="padding:3px 0;color:#151d32;font-weight:600;">{$ownerName}</td>
                            </tr>
                            <tr>
                                <td style="padding:3px 10px 3px 0;color:#5c6484;vertical-align:top;">Email</td>
                                <td style="padding:3px 0;color:#151d32;font-weight:600;">{$ownerEmailEsc}</td>
                            </tr>
                            {$wpCredentialsRows}
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td style="padding:0 32px 32px;text-align:center;">
            <a href="{$urlEsc}" style="display:inline-block;background:#11069f;color:#ffffff;text-decoration:none;padding:11px 28px;border-radius:6px;font-size:14px;font-weight:600;letter-spacing:-0.2px;">Accéder à mon site</a>
        </td>
    </tr>
</table>

<!-- Footer -->
<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:20px;">
<tr><td align="center" style="font-size:11px;color:rgba(255,255,255,.35);font-weight:500;">
    AkiCloud — Infrastructure Cloud
</td></tr>
</table>

</td></tr>
</table>
</body>
</html>
HTML;
    }
}
