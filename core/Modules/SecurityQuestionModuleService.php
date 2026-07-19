<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * The activation half mirrors ReportModuleService/WidgetModuleService
 * exactly (no hooks, activation only gates whether a question is offered).
 * The client-facing half is new to this SDK: unlike an addon/widget/report
 * (admin-global, on for everyone once activated), a security question also
 * needs each client to individually opt in and set their own answer —
 * activation alone does nothing until a client calls setup().
 */
final class SecurityQuestionModuleService
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly SecurityQuestionModuleRepository $repository,
        private readonly ClientSecurityAnswerRepository $answers
    ) {
    }

    /** @return array{success: bool, message: string} */
    public function activate(string $slug): array
    {
        if (!$this->find($slug) instanceof SecurityQuestionModule) {
            return ['success' => false, 'message' => "Unknown security question [{$slug}]."];
        }

        $this->repository->activate($slug);

        return ['success' => true, 'message' => 'Security question activated.'];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(string $slug): array
    {
        if (!$this->find($slug) instanceof SecurityQuestionModule) {
            return ['success' => false, 'message' => "Unknown security question [{$slug}]."];
        }

        $this->repository->deactivate($slug);

        return ['success' => true, 'message' => 'Security question deactivated.'];
    }

    public function find(string $slug): ?SecurityQuestionModule
    {
        $module = $this->modules->get(SecurityQuestionModule::class, $slug);

        return $module instanceof SecurityQuestionModule ? $module : null;
    }

    /**
     * @return array<int, array{slug: string, metadata: array, active: bool}>
     */
    public function catalog(): array
    {
        $entries = [];

        foreach ($this->modules->allOfType(SecurityQuestionModule::class) as $slug => $module) {
            if (!$module instanceof SecurityQuestionModule) {
                continue;
            }

            $entries[] = [
                'slug' => $slug,
                'metadata' => $module->metadata(),
                'active' => $this->repository->isActive($slug),
            ];
        }

        return $entries;
    }

    /** Only active modules can be chosen — an admin deactivating one shouldn't block existing setups from being overwritten with a still-active choice. */
    public function activeCatalog(): array
    {
        return array_values(array_filter($this->catalog(), static fn (array $entry) => $entry['active']));
    }

    /** @return array{success: bool, message: string} */
    public function setup(int $clientId, string $slug, string $answer): array
    {
        if (!$this->repository->isActive($slug) || $this->find($slug) === null) {
            return ['success' => false, 'message' => 'That security question is not available.'];
        }

        $trimmed = trim($answer);

        if ($trimmed === '') {
            return ['success' => false, 'message' => 'An answer is required.'];
        }

        $this->answers->set($clientId, $slug, password_hash($trimmed, PASSWORD_ARGON2ID));

        return ['success' => true, 'message' => 'Security question saved.'];
    }

    public function clear(int $clientId): void
    {
        $this->answers->clear($clientId);
    }

    /** @return array{slug: string, question: string}|null */
    public function promptFor(int $clientId): ?array
    {
        $row = $this->answers->find($clientId);

        if ($row === null) {
            return null;
        }

        $module = $this->find((string) $row['module_slug']);

        if ($module === null || !$this->repository->isActive((string) $row['module_slug'])) {
            return null;
        }

        return ['slug' => (string) $row['module_slug'], 'question' => $module->prompt($clientId)];
    }

    public function isConfiguredFor(int $clientId): bool
    {
        return $this->promptFor($clientId) !== null;
    }

    public function verify(int $clientId, string $answer): bool
    {
        $row = $this->answers->find($clientId);

        if ($row === null) {
            return false;
        }

        $module = $this->find((string) $row['module_slug']);

        if ($module === null || !$this->repository->isActive((string) $row['module_slug'])) {
            return false;
        }

        return $module->verify($clientId, $answer);
    }
}
