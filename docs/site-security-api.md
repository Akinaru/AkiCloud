# API sécurité sites (AkiCloud)

Ce module permet de protéger un site **sans Traefik forward-auth**.
Le site protégé délègue l'authentification à AkiCloud, puis valide un token côté backend.

## Vue d'ensemble

1. L'utilisateur arrive sur un site protégé.
2. Le site redirige vers AkiCloud : `GET /security/site/login`.
3. AkiCloud vérifie login + droits au site.
4. AkiCloud redirige vers le site avec `aki_token`.
5. Le backend du site appelle `POST /api/security/site/validate-token`.
6. Si `ok=true`, le site ouvre sa session locale.

## Endpoint 1: login gateway

`GET /security/site/login?site={host_du_site}&return_url={url_callback}`

Exemple:

```text
https://akicloud.example.com/security/site/login?site=demo.cloud.akinaru.fr&return_url=https%3A%2F%2Fdemo.cloud.akinaru.fr%2Fauth%2Fcallback
```

### Paramètres

- `site` (obligatoire): host exact du site (ex: `demo.cloud.akinaru.fr`)
- `return_url` (obligatoire): URL HTTPS (ou HTTP local) du callback sur le même host que `site`

### Comportement

- Non connecté: redirection vers `/login`
- Connecté sans droit: redirection vers `return_url?...aki_status=forbidden`
- Site public: redirection vers `return_url?...aki_status=public`
- Autorisé: redirection vers `return_url?...aki_status=ok&aki_token=...`

## Endpoint 2: validation token

`POST /api/security/site/validate-token`

Headers:

- `Content-Type: application/json`
- `X-Aki-Security-Key: <api_key>` (obligatoire si configurée côté AkiCloud)

Body:

```json
{
  "token": "<aki_token>",
  "site": "demo.cloud.akinaru.fr"
}
```

Réponse succès:

```json
{
  "ok": true,
  "site": {
    "id": 12,
    "name": "Demo",
    "host": "demo.cloud.akinaru.fr",
    "protected": true
  },
  "user": {
    "id": 4,
    "email": "john@acme.fr",
    "first_name": "John",
    "last_name": "Doe",
    "roles": ["ROLE_USER"]
  },
  "exp": 1760000000
}
```

Réponse erreur:

- `401` token invalide/expiré
- `403` utilisateur non autorisé
- `400` payload invalide

## Variables/Settings à configurer

Tu peux les mettre en DB (`setting`) ou en variables d'env.

- `site_security_api_key` (setting) ou `SITE_SECURITY_API_KEY` (env)
  - clé partagée backend->backend
- `site_security_signing_secret` (setting) ou `SITE_SECURITY_SIGNING_SECRET` (env)
  - secret de signature des tokens

## Exemple d'intégration (pseudo-code backend)

```pseudo
if request.path == '/auth/callback':
    token = request.query['aki_token']
    r = POST akicloud/api/security/site/validate-token { token, site: current_host }
    if r.ok:
        create_local_session(r.user)
        redirect('/admin')
    else:
        redirect('/login?error=forbidden')
```

## Notes sécurité

- Le token est court (5 min)
- Toujours valider le token côté backend (jamais seulement en JS)
- Toujours envoyer `X-Aki-Security-Key` si une API key est configurée
- Restreindre `return_url` au host du site (déjà imposé)
