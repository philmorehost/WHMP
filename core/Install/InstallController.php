<?php

declare(strict_types=1);

namespace CodeVault\Install;

use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Integrity\IntegrityManager;
use CodeVault\Kernel;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;
use DateTimeImmutable;
use Throwable;

/**
 * The 4-stage installer (blueprint §5/§8 R1): welcome + requirements +
 * activation key -> DB config -> admin account -> finish/lock. Each
 * stage's POST guards against skipping ahead by checking the previous
 * stage's artifact exists (.env, then an admins row) rather than trusting
 * client-submitted state.
 */
final class InstallController
{
    public function __construct(
        private readonly Kernel $kernel,
        private readonly View $view
    ) {
    }

    public function requirements(Request $request): Response
    {
        if ($denied = $this->refuseIfAlreadyInstalled()) {
            return $denied;
        }

        $checker = new RequirementsChecker($this->kernel->basePath());
        $errors = [];
        $activationKey = '';

        if ($request->method() === 'POST') {
            $activationKey = trim((string) $request->input('activation_key', ''));

            if ($activationKey === '') {
                $errors[] = 'Activation key is required.';
            } else {
                /** @var IntegrityManager $integrity */
                $integrity = $this->kernel->container->make(IntegrityManager::class);
                if (!$integrity->validateKeyRemotely($activationKey)) {
                    $errors[] = 'The activation key is invalid or could not be verified by the validation server.';
                } else {
                    $integrity->storeActivationKey($activationKey);
                }
            }

            if ($errors === [] && $checker->allPassed()) {
                return Response::redirect('/install/database');
            }
        }

        return $this->page('install.requirements', [
            'checks' => $checker->checkAll(),
            'allPassed' => $checker->allPassed(),
            'errors' => $errors,
            'old' => ['activation_key' => $activationKey],
        ], 'Welcome');
    }

    public function database(Request $request): Response
    {
        if ($denied = $this->refuseIfAlreadyInstalled()) {
            return $denied;
        }

        if ($request->method() === 'GET') {
            return $this->page('install.database', ['errors' => [], 'old' => []], 'Database Setup');
        }

        $host = (string) $request->input('db_host', '127.0.0.1');
        $port = (string) $request->input('db_port', '3306');
        $name = (string) $request->input('db_database', 'codevault');
        $user = (string) $request->input('db_username', 'root');
        $pass = (string) $request->input('db_password', '');
        $appUrl = (string) $request->input('app_url', 'http://localhost');

        $errors = [];

        if ($name === '' || $user === '') {
            $errors[] = 'Database name and username are required.';
        }

        if ($errors === []) {
            try {
                $testDb = new Database($host, $port, $name, $user, $pass);
                $testDb->connection(); // triggers the actual connection attempt
            } catch (Throwable $e) {
                $errors[] = 'Could not connect: ' . $e->getMessage();
            }
        }

        if ($errors !== []) {
            return $this->page('install.database', [
                'errors' => $errors,
                'old' => compact('host', 'port', 'name', 'user', 'appUrl'),
            ], 'Database Setup');
        }

        EnvWriter::write(
            $this->kernel->basePath('.env.example'),
            $this->kernel->basePath('.env'),
            [
                'APP_URL' => $appUrl,
                'APP_KEY' => EnvWriter::generateAppKey(),
                'DB_HOST' => $host,
                'DB_PORT' => $port,
                'DB_DATABASE' => $name,
                'DB_USERNAME' => $user,
                'DB_PASSWORD' => $pass,
            ]
        );

        $freshDb = new Database($host, $port, $name, $user, $pass);
        $migrator = new Migrator($freshDb, $this->kernel->basePath('database/migrations'));
        $migrator->run();

        return Response::redirect('/install/admin');
    }

    public function admin(Request $request): Response
    {
        if ($denied = $this->refuseIfAlreadyInstalled()) {
            return $denied;
        }

        if (!is_file($this->kernel->basePath('.env'))) {
            return Response::redirect('/install/database');
        }

        if ($request->method() === 'GET') {
            return $this->page('install.admin', ['errors' => [], 'old' => []], 'Create Admin Account');
        }

        $username = trim((string) $request->input('username', ''));
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $passwordConfirm = (string) $request->input('password_confirmation', '');
        $displayName = trim((string) $request->input('display_name', ''));

        $errors = [];

        if ($username === '' || strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }

        if ($errors !== []) {
            return $this->page('install.admin', [
                'errors' => $errors,
                'old' => compact('username', 'email', 'displayName'),
            ], 'Create Admin Account');
        }

        /** @var Database $db */
        $db = $this->kernel->container->make(Database::class);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        // Migration 0013 backfills role_id onto any admin that predates
        // it — but on a fresh install, all migrations (including 0013)
        // already ran in Stage 2, before this account exists, so that
        // backfill runs against zero rows and never reaches this one.
        // Assign the seeded super-admin role explicitly here instead of
        // relying on a backfill that structurally can't reach a
        // not-yet-created row.
        $superAdminRole = $db->selectOne("SELECT id FROM roles WHERE is_super_admin = 1 ORDER BY id LIMIT 1");

        $db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$username, $email, password_hash($password, PASSWORD_ARGON2ID), $displayName !== '' ? $displayName : $username, $superAdminRole['id'] ?? null, $now, $now]
        );

        return Response::redirect('/install/finish');
    }

    public function finish(Request $request): Response
    {
        // Deliberately not gated by refuseIfAlreadyInstalled() — this is
        // the method that creates .installed.lock on a legitimate first
        // run, so the lock file doesn't exist yet the one time this call
        // is supposed to succeed. Re-visits after that first call still
        // correctly no-op (the is_file check below skips re-writing the
        // lock) and just re-render the same congratulations page rather
        // than doing anything destructive, so no separate guard is needed.
        if (!is_file($this->kernel->basePath('.env'))) {
            return Response::redirect('/install/database');
        }

        if (!is_file($this->kernel->basePath('.installed.lock'))) {
            file_put_contents($this->kernel->basePath('.installed.lock'), (new DateTimeImmutable())->format(DATE_ATOM));
        }

        return $this->page('install.done', [], 'Setup Complete');
    }

    /**
     * Every other installer action is a real state mutation (writing
     * .env, running migrations, creating an admin account) — once
     * .installed.lock exists, none of them should still be reachable, or
     * a visitor could re-run the wizard and, for example, create a second
     * admin account with a password of their choosing.
     */
    private function refuseIfAlreadyInstalled(): ?Response
    {
        if (is_file($this->kernel->basePath('.installed.lock'))) {
            return Response::redirect('/');
        }

        return null;
    }

    private function page(string $template, array $data, string $title): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.installer', [
            'title' => "CodeVault Installer — {$title}",
            'content' => $content,
        ]));
    }
}
