<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo getenv('APP_NAME') ?: 'Projet en construction'; ?> — AkiCloud</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root {
            --violet: #8f7ae6;
            --violet-dark: #7a64d6;
            --violet-soft: #f1edff;
            --surface: #ffffff;
            --border: #e4def8;
            --text: #1c1830;
            --text-2: #5f577e;
            --warn: #b45309;
            --warn-bg: #fff7e8;
            --r-lg: 16px;
            --r-md: 12px;
            --shadow-lg: 0 18px 48px rgba(22, 17, 45, .18);
            --font: 'Sora', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font);
            background: radial-gradient(circle at 20% 10%, #9687de 0%, #6f5bcf 38%, #322954 100%);
            color: var(--text);
            font-size: 14px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .wrap {
            width: 100%;
            max-width: 900px;
        }

        .card {
            background: var(--surface);
            border-radius: var(--r-lg);
            box-shadow: var(--shadow-lg);
            width: 100%;
            padding: 30px;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .grid {
            display: grid;
            grid-template-columns: 1.2fr .8fr;
            gap: 22px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 22px;
        }

        .logo {
            max-height: 52px;
            width: auto;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--warn-bg);
            color: var(--warn);
            padding: 6px 14px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            background: var(--warn);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.4; }
            100% { opacity: 1; }
        }

        h1 {
            font-size: 30px;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 10px;
            letter-spacing: -0.5px;
            line-height: 1.2;
        }

        .intro {
            color: var(--text-2);
            font-size: 15px;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .info-box {
            background: #f8f5ff;
            border: 1px solid var(--border);
            border-radius: var(--r-md);
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
            color: var(--violet);
        }

        .meta {
            background: linear-gradient(180deg, #f8f5ff 0%, #f2ecff 100%);
            border: 1px solid var(--border);
            border-radius: var(--r-md);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .meta-item {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            font-size: 12px;
            color: var(--text-2);
        }

        .meta-item strong {
            color: var(--text);
            font-weight: 700;
        }

        .illu {
            position: relative;
            min-height: 260px;
            border-radius: var(--r-md);
            background: linear-gradient(160deg, #2e2550 0%, #6f5bcf 52%, #9788df 100%);
            overflow: hidden;
        }

        .illu::before,
        .illu::after {
            content: "";
            position: absolute;
            border-radius: 999px;
            filter: blur(0.5px);
        }

        .illu::before {
            width: 220px;
            height: 220px;
            right: -40px;
            top: -40px;
            background: rgba(255,255,255,.18);
        }

        .illu::after {
            width: 180px;
            height: 180px;
            left: -30px;
            bottom: -30px;
            background: rgba(255,255,255,.12);
        }

        .illu-content {
            position: absolute;
            inset: 0;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            color: #fff;
        }

        .illu-tag {
            align-self: flex-start;
            background: rgba(255,255,255,.2);
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 8px;
        }

        .illu-title {
            font-size: 18px;
            font-weight: 700;
            line-height: 1.3;
            max-width: 240px;
        }

        .footer {
            margin-top: 14px;
            font-size: 11px;
            color: rgba(255,255,255,0.56);
            font-weight: 500;
            text-align: center;
        }

        @media (max-width: 860px) {
            .grid { grid-template-columns: 1fr; }
            .illu { min-height: 180px; }
            h1 { font-size: 25px; }
        }
    </style>
</head>
<body>

<div class="wrap">
    <div class="card">
        <div class="grid">
            <div>
                <div class="brand">
                    <img src="Logo_AKI.png" alt="AKI" class="logo">
                </div>
                <div>
                    <span class="status-badge">
                        <span class="status-dot"></span>
                        Maintenance projet
                    </span>
                </div>

                <h1><?php echo getenv('APP_NAME') ?: 'Projet en construction'; ?></h1>

                <p class="intro">
                    Cette page est temporaire. Le projet est en cours de construction et une version complete arrive bientot.
                </p>

                <div class="info-box">
                    <i class="bi bi-tools"></i>
                    <span>On peaufine l'experience. Merci pour ta patience.</span>
                </div>

                <div class="meta" style="margin-top: 14px;">
                    <div class="meta-item">
                        <span>Etat</span>
                        <strong>En developpement</strong>
                    </div>
                    <div class="meta-item">
                        <span>Priorite actuelle</span>
                        <strong>Stabilisation & finition</strong>
                    </div>
                    <div class="meta-item">
                        <span>Prochaine mise a jour</span>
                        <strong>Bientot disponible</strong>
                    </div>
                </div>
            </div>

            <div class="illu">
                <div class="illu-content">
                    <span class="illu-tag">AkiCloud</span>
                    <div class="illu-title">On construit quelque chose de propre. Le site arrive tres vite.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        Propulse par Coolify v4 - AkiCloud
    </div>
</div>

</body>
</html>
