<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\ClientSecurityAnswerRepository;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\SecurityQuestionModule;
use CodeVault\Modules\SecurityQuestionModuleRepository;
use CodeVault\Modules\SecurityQuestionModuleService;
use CodeVault\Security\MotherMaidenNameQuestion;
use CodeVault\Tests\Support\DatabaseTestCase;

final class SecurityQuestionModuleTest extends DatabaseTestCase
{
    private SecurityQuestionModuleRepository $repository;
    private ClientSecurityAnswerRepository $answers;
    private ClientRepository $clients;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->repository = new SecurityQuestionModuleRepository($this->db);
        $this->answers = new ClientSecurityAnswerRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->clientId = $this->clients->create([
            'email' => 'secq@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Sec',
            'last_name' => 'Question',
        ]);
    }

    // --- SecurityQuestionModuleRepository (activation) ------------------------

    public function test_a_new_slug_is_inactive_by_default(): void
    {
        $this->assertFalse($this->repository->isActive('never-seen'));
    }

    public function test_activate_then_deactivate_round_trips_correctly(): void
    {
        $this->repository->activate('demo-question');
        $this->assertTrue($this->repository->isActive('demo-question'));

        $this->repository->deactivate('demo-question');
        $this->assertFalse($this->repository->isActive('demo-question'));
    }

    // --- ClientSecurityAnswerRepository ----------------------------------------

    public function test_a_client_with_no_answer_configured_returns_null(): void
    {
        $this->assertNull($this->answers->find($this->clientId));
    }

    public function test_setting_a_new_answer_overwrites_the_prior_one(): void
    {
        $this->answers->set($this->clientId, 'question-a', 'hash-1');
        $this->answers->set($this->clientId, 'question-b', 'hash-2');

        $row = $this->answers->find($this->clientId);
        $this->assertSame('question-b', $row['module_slug']);
        $this->assertSame('hash-2', $row['answer_hash']);
        $this->assertCount(1, $this->db->select('SELECT * FROM client_security_answers'));
    }

    public function test_clear_removes_the_row(): void
    {
        $this->answers->set($this->clientId, 'question-a', 'hash-1');
        $this->answers->clear($this->clientId);

        $this->assertNull($this->answers->find($this->clientId));
    }

    // --- SecurityQuestionModuleService ------------------------------------------

    public function test_activate_rejects_an_unknown_slug(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);

        $result = $service->activate('does-not-exist');

        $this->assertFalse($result['success']);
    }

    public function test_catalog_reflects_activation_state(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(SecurityQuestionModule::class, 'mother-maiden-name', new MotherMaidenNameQuestion($this->answers));
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);

        $catalog = $service->catalog();
        $this->assertCount(1, $catalog);
        $this->assertFalse($catalog[0]['active']);

        $service->activate('mother-maiden-name');
        $this->assertTrue($service->catalog()[0]['active']);
    }

    public function test_active_catalog_excludes_inactive_modules(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(SecurityQuestionModule::class, 'mother-maiden-name', new MotherMaidenNameQuestion($this->answers));
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);

        $this->assertSame([], $service->activeCatalog());

        $service->activate('mother-maiden-name');
        $this->assertCount(1, $service->activeCatalog());
    }

    public function test_setup_rejects_an_inactive_module(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(SecurityQuestionModule::class, 'mother-maiden-name', new MotherMaidenNameQuestion($this->answers));
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);

        $result = $service->setup($this->clientId, 'mother-maiden-name', 'Smith');

        $this->assertFalse($result['success']);
        $this->assertNull($this->answers->find($this->clientId));
    }

    public function test_setup_rejects_a_blank_answer(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(SecurityQuestionModule::class, 'mother-maiden-name', new MotherMaidenNameQuestion($this->answers));
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);
        $service->activate('mother-maiden-name');

        $result = $service->setup($this->clientId, 'mother-maiden-name', '   ');

        $this->assertFalse($result['success']);
    }

    public function test_setup_stores_a_hashed_answer_not_plaintext(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(SecurityQuestionModule::class, 'mother-maiden-name', new MotherMaidenNameQuestion($this->answers));
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);
        $service->activate('mother-maiden-name');

        $result = $service->setup($this->clientId, 'mother-maiden-name', 'Smith');

        $this->assertTrue($result['success']);
        $row = $this->answers->find($this->clientId);
        $this->assertNotSame('Smith', $row['answer_hash']);
        $this->assertTrue(password_verify('Smith', $row['answer_hash']));
    }

    public function test_prompt_for_returns_null_when_not_configured(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);

        $this->assertNull($service->promptFor($this->clientId));
        $this->assertFalse($service->isConfiguredFor($this->clientId));
    }

    public function test_prompt_for_returns_the_question_text_once_configured(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(SecurityQuestionModule::class, 'mother-maiden-name', new MotherMaidenNameQuestion($this->answers));
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);
        $service->activate('mother-maiden-name');
        $service->setup($this->clientId, 'mother-maiden-name', 'Smith');

        $prompt = $service->promptFor($this->clientId);

        $this->assertNotNull($prompt);
        $this->assertSame('mother-maiden-name', $prompt['slug']);
        $this->assertStringContainsString("mother's maiden name", $prompt['question']);
        $this->assertTrue($service->isConfiguredFor($this->clientId));
    }

    public function test_prompt_for_returns_null_once_the_module_is_deactivated_even_if_a_client_configured_it(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(SecurityQuestionModule::class, 'mother-maiden-name', new MotherMaidenNameQuestion($this->answers));
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);
        $service->activate('mother-maiden-name');
        $service->setup($this->clientId, 'mother-maiden-name', 'Smith');

        $service->deactivate('mother-maiden-name');

        $this->assertNull($service->promptFor($this->clientId));
    }

    public function test_verify_returns_false_when_not_configured(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);

        $this->assertFalse($service->verify($this->clientId, 'anything'));
    }

    public function test_verify_returns_true_for_the_correct_answer_and_false_for_the_wrong_one(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(SecurityQuestionModule::class, 'mother-maiden-name', new MotherMaidenNameQuestion($this->answers));
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);
        $service->activate('mother-maiden-name');
        $service->setup($this->clientId, 'mother-maiden-name', 'Smith');

        $this->assertTrue($service->verify($this->clientId, 'Smith'));
        $this->assertFalse($service->verify($this->clientId, 'Jones'));
    }

    public function test_verify_trims_leading_and_trailing_whitespace_from_both_sides(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(SecurityQuestionModule::class, 'mother-maiden-name', new MotherMaidenNameQuestion($this->answers));
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);
        $service->activate('mother-maiden-name');
        $service->setup($this->clientId, 'mother-maiden-name', '  Smith  ');

        $this->assertTrue($service->verify($this->clientId, 'Smith'));
    }

    public function test_clear_removes_a_configured_answer(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(SecurityQuestionModule::class, 'mother-maiden-name', new MotherMaidenNameQuestion($this->answers));
        $service = new SecurityQuestionModuleService($modules, $this->repository, $this->answers);
        $service->activate('mother-maiden-name');
        $service->setup($this->clientId, 'mother-maiden-name', 'Smith');

        $service->clear($this->clientId);

        $this->assertNull($service->promptFor($this->clientId));
        $this->assertFalse($service->verify($this->clientId, 'Smith'));
    }
}
