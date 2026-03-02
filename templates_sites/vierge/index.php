<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo getenv('APP_NAME') ?: 'Projet Étudiant'; ?> — AkiCloud</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root {
            --blue: #11069f;
            --surface: #ffffff;
            --border: #e2e6f0;
            --text: #151d32;
            --text-2: #5c6484;
            --green: #00a854;
            --green-bg: #e6f7ed;
            --r-lg: 14px;
            --shadow-lg: 0 12px 36px rgba(15,25,50,.14);
            --font: 'Sora', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font);
            background: linear-gradient(to bottom, #11069f 0%, #03214D 100%);
            color: var(--text);
            font-size: 14px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .card {
            background: var(--surface);
            border-radius: var(--r-lg);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 420px;
            padding: 40px 32px;
            text-align: center;
        }

        .logo {
            max-height: 70px;
            width: auto;
            margin-bottom: 32px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--green-bg);
            color: var(--green);
            padding: 6px 14px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 24px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            background: var(--green);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.4; }
            100% { opacity: 1; }
        }

        h1 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        p {
            color: var(--text-2);
            font-size: 14px;
            margin-bottom: 32px;
            line-height: 1.6;
        }

        .info-box {
            background: #f8f9fd;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            font-size: 13px;
            color: var(--text-2);
            display: flex;
            align-items: center;
            gap: 12px;
            text-align: left;
        }

        .info-box i {
            font-size: 20px;
            color: var(--blue);
        }

        .footer {
            margin-top: 24px;
            font-size: 11px;
            color: rgba(255,255,255,0.4);
            font-weight: 500;
        }
    </style>
</head>
<body>

<div>
    <div class="card">
        <img src="Logo_AKI.png" alt="AKI" class="logo">

        <div>
            <span class="status-badge">
                <span class="status-dot"></span>
                Site en ligne
            </span>
        </div>

        <h1><?php echo getenv('APP_NAME') ?: 'Nouveau Projet'; ?></h1>

        <p>
            Votre instance a été déployée avec succès sur l'infrastructure <strong>AkiCloud</strong>. Vous pouvez maintenant commencer à travailler.
        </p>

        <div class="info-box">
            <i class="bi bi-info-circle-fill"></i>
            <span>Pour remplacer cette page, envoyez vos fichiers à la racine de votre projet.</span>
        </div>
    </div>

    <div class="footer" style="text-align: center;">
        Propulsé par Coolify v4 & Symfony — Infrastructure Cloud USMB
    </div>
</div>

</body>
</html>
