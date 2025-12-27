<!DOCTYPE html>
<html lang="en" data-theme="auto">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName ?? 'FlowMonk') ?></title>

    <!-- Pico CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Custom styles -->
    <link rel="stylesheet" href="/assets/app.css">

    <!-- Mobile nav styles -->
    <style>
        /* Hamburger toggle button */
        header nav .nav-toggle {
            display: none;
            background: var(--pico-primary);
            border: 2px solid var(--pico-primary);
            border-radius: var(--pico-border-radius);
            cursor: pointer;
            padding: 0.5rem 0.75rem;
            font-size: 1.25rem;
            color: var(--pico-primary-inverse);
            margin: 0;
            width: auto;
        }
        header nav .nav-toggle:hover {
            background: var(--pico-primary-hover);
            border-color: var(--pico-primary-hover);
        }

        @media (max-width: 768px) {
            header nav .nav-toggle {
                display: block !important;
            }
            header nav .nav-links {
                display: none !important;
                flex-direction: column;
                width: 100%;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: var(--pico-background-color);
                padding: 1rem;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                z-index: 100;
                border-top: 1px solid var(--pico-muted-border-color);
            }
            header nav .nav-links.open {
                display: flex !important;
            }
            header nav .nav-links li {
                margin: 0.25rem 0;
                padding: 0;
            }
            header nav .nav-links li a {
                display: block;
                padding: 0.5rem 0;
            }
            header nav {
                position: relative;
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <header class="container" x-data="{ navOpen: false }">
        <nav>
            <ul>
                <li><a href="/"><strong><?= htmlspecialchars($appName ?? 'FlowMonk') ?></strong></a></li>
            </ul>
            <button class="nav-toggle" @click="navOpen = !navOpen" aria-label="Toggle menu">
                <span x-text="navOpen ? '✕' : '☰'">☰</span>
            </button>
            <ul class="nav-links" :class="{ 'open': navOpen }" @click="navOpen = false">
                <li><a href="/">Query Builder</a></li>
                <li><a href="/manage">Manage Lists</a></li>
                <li><a href="/drip">Drip Config</a></li>
                <li><a href="/queue">Drip Queue</a></li>
                <li><a href="/stats">Drip Stats</a></li>
                <li><a href="/audit">Audit</a></li>
            </ul>
        </nav>
    </header>

    <main class="container">
        <?= $content ?>
    </main>

    <footer class="container">
        <small>FlowMonk - Listmonk Automation Suite</small>
    </footer>
</body>
</html>
