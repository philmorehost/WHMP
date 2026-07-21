<?php

declare(strict_types=1);

// When running under PHP's built-in dev server with an explicit router
// script (php -S host:port -t public public/index.php — needed so
// dynamic routes like /sitemap.xml and /robots.txt aren't intercepted
// as 404s before reaching the app), the built-in server calls this
// script for every request, including static assets. Without this
// guard, /assets/css/*.css would 404 (no app route registers it) even
// though the file exists on disk. This is the standard documented
// pattern for php -S; it's a no-op under a real web server (Apache/
// nginx/PHP-FPM), which never executes this script for static files.
if (PHP_SAPI === 'cli-server') {
    $path = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if ($path !== __DIR__ . '/index.php' && is_file($path)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

use CodeVault\Config;
use CodeVault\Kernel;
use CodeVault\Request;

// Security hardening: outside local dev, a PHP fatal error must never leak
// a stack trace + filesystem paths to the visitor who triggered it — that's
// real information disclosure (path layout, code structure) handed to
// anyone who can make the app throw. Errors still always get logged.
$bootConfig = new Config(dirname(__DIR__));
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/storage/cache/php-error.log');

if ((string) $bootConfig->env('APP_ENV', 'production') === 'local') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

$kernel = new Kernel(dirname(__DIR__));
$kernel->loadRoutes(
    'install.php', 'auth.php', 'web.php', 'security.php', 'staff.php', 'clients.php',
    'custom-fields.php', 'email-templates.php', 'catalog.php', 'client-auth.php',
    'cart.php', 'gateways.php', 'orders.php', 'invoices.php', 'services.php',
    'tax-rules.php', 'promotions.php', 'servers.php', 'client-services.php', 'domains.php',
    'client-domains.php', 'support.php', 'knowledgebase.php', 'downloads.php', 'affiliates.php', 'reports.php', 'seo.php', 'ai.php', 'api.php',
    'currencies.php', 'localization.php', 'notifications.php', 'theme.php', 'backup.php', 'marketing.php',
    'account-security.php', 'client-account.php', 'gdpr.php', 'import.php', 'credit-notes.php', 'addons.php', 'widgets.php', 'quotes.php',
    'security-questions.php', 'cpanel-tools.php', 'system.php'
);

$response = $kernel->handle(Request::capture());
$response->send();
