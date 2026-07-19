<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Import\ClientImportService;
use CodeVault\Import\CsvParser;
use CodeVault\Import\ImportRunRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

final class ImportTest extends DatabaseTestCase
{
    private CsvParser $parser;
    private ClientImportService $importer;
    private ClientRepository $clients;
    private ImportRunRepository $runs;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->parser = new CsvParser();
        $this->clients = new ClientRepository($this->db);
        $this->importer = new ClientImportService($this->clients);
        $this->runs = new ImportRunRepository($this->db);
    }

    // --- CsvParser ---------------------------------------------------

    public function test_parses_simple_headers_and_rows(): void
    {
        $result = $this->parser->parse("email,first_name,last_name\na@example.test,Alice,Anderson\nb@example.test,Bob,Baker\n");

        $this->assertSame(['email', 'first_name', 'last_name'], $result['headers']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame(['a@example.test', 'Alice', 'Anderson'], $result['rows'][0]);
    }

    public function test_handles_quoted_fields_with_embedded_commas(): void
    {
        $result = $this->parser->parse("email,company_name\na@example.test,\"Acme, Inc.\"\n");

        $this->assertSame(['a@example.test', 'Acme, Inc.'], $result['rows'][0]);
    }

    public function test_handles_crlf_line_endings_and_strips_bom(): void
    {
        $content = "\xEF\xBB\xBFemail,first_name\r\na@example.test,Alice\r\n";

        $result = $this->parser->parse($content);

        $this->assertSame(['email', 'first_name'], $result['headers']);
        $this->assertSame(['a@example.test', 'Alice'], $result['rows'][0]);
    }

    public function test_skips_blank_lines(): void
    {
        $result = $this->parser->parse("email,first_name\na@example.test,Alice\n\n\nb@example.test,Bob\n");

        $this->assertCount(2, $result['rows']);
    }

    public function test_empty_content_returns_empty_headers_and_rows(): void
    {
        $result = $this->parser->parse('');

        $this->assertSame([], $result['headers']);
        $this->assertSame([], $result['rows']);
    }

    // --- ClientImportService ------------------------------------------

    public function test_imports_valid_rows_with_mapped_fields(): void
    {
        $parsed = $this->parser->parse("email,first_name,last_name,company_name,city\na@example.test,Alice,Anderson,Acme Inc,Lagos\n");

        $result = $this->importer->import($parsed['headers'], $parsed['rows']);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['skipped']);

        $client = $this->clients->findByEmail('a@example.test');
        $this->assertNotNull($client);
        $this->assertSame('Alice', $client['first_name']);
        $this->assertSame('Acme Inc', $client['company_name']);
        $this->assertSame('Lagos', $client['city']);
    }

    public function test_matches_header_aliases_case_insensitively(): void
    {
        $parsed = $this->parser->parse("E-Mail,First Name,Last Name\na@example.test,Alice,Anderson\n");

        $result = $this->importer->import($parsed['headers'], $parsed['rows']);

        $this->assertSame(1, $result['imported']);
        $this->assertNotNull($this->clients->findByEmail('a@example.test'));
    }

    public function test_rejects_the_whole_file_when_a_required_column_is_missing(): void
    {
        $parsed = $this->parser->parse("first_name,last_name\nAlice,Anderson\n");

        $result = $this->importer->import($parsed['headers'], $parsed['rows']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('email', $result['error']);
        $this->assertNull($this->clients->findByEmail('a@example.test'));
    }

    public function test_skips_a_row_with_an_invalid_email_and_reports_why(): void
    {
        $parsed = $this->parser->parse("email,first_name,last_name\nnot-an-email,Alice,Anderson\n");

        $result = $this->importer->import($parsed['headers'], $parsed['rows']);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(2, $result['errors'][0]['row']);
    }

    public function test_skips_a_row_missing_first_or_last_name(): void
    {
        $parsed = $this->parser->parse("email,first_name,last_name\na@example.test,,Anderson\n");

        $result = $this->importer->import($parsed['headers'], $parsed['rows']);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_skips_a_duplicate_email_within_the_same_file_keeping_only_the_first(): void
    {
        $parsed = $this->parser->parse("email,first_name,last_name\na@example.test,Alice,Anderson\na@example.test,Alicia,Anders\n");

        $result = $this->importer->import($parsed['headers'], $parsed['rows']);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $client = $this->clients->findByEmail('a@example.test');
        $this->assertSame('Alice', $client['first_name'], 'the first occurrence must win');
    }

    public function test_skips_an_email_that_already_exists_in_the_database(): void
    {
        $this->clients->create(['email' => 'a@example.test', 'password' => 'whatever123', 'first_name' => 'Existing', 'last_name' => 'Client']);

        $parsed = $this->parser->parse("email,first_name,last_name\na@example.test,Alice,Anderson\n");

        $result = $this->importer->import($parsed['headers'], $parsed['rows']);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $client = $this->clients->findByEmail('a@example.test');
        $this->assertSame('Existing', $client['first_name'], 'the existing record must not be overwritten');
    }

    public function test_ignores_unrecognized_extra_columns(): void
    {
        $parsed = $this->parser->parse("email,first_name,last_name,favorite_color\na@example.test,Alice,Anderson,blue\n");

        $result = $this->importer->import($parsed['headers'], $parsed['rows']);

        $this->assertSame(1, $result['imported']);
    }

    public function test_mixed_batch_reports_accurate_counts(): void
    {
        $parsed = $this->parser->parse(
            "email,first_name,last_name\n"
            . "a@example.test,Alice,Anderson\n"
            . "bad-email,Bob,Baker\n"
            . "c@example.test,Carol,Clark\n"
        );

        $result = $this->importer->import($parsed['headers'], $parsed['rows']);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['skipped']);
    }

    // --- ImportRunRepository -------------------------------------------

    public function test_import_run_create_and_recent_round_trip(): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $adminId = 1;
        $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['importadmin', 'importadmin@example.test', password_hash('x', PASSWORD_ARGON2ID), 'Import Admin', $now, $now]
        );

        $this->runs->create($adminId, 'clients', 'clients.csv', 3, 2, 1, [['row' => 3, 'reason' => 'bad email']]);

        $recent = $this->runs->recent();
        $this->assertCount(1, $recent);
        $this->assertSame('clients', $recent[0]['entity_type']);
        $this->assertSame('clients.csv', $recent[0]['filename']);
        $this->assertSame(2, (int) $recent[0]['imported_count']);
        $this->assertSame(1, (int) $recent[0]['skipped_count']);
        $decoded = json_decode((string) $recent[0]['errors'], true);
        $this->assertSame('bad email', $decoded[0]['reason']);
    }

    public function test_recent_by_type_filters_to_only_that_entity_type(): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $adminId = (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['importadmin2', 'importadmin2@example.test', password_hash('x', PASSWORD_ARGON2ID), 'Import Admin', $now, $now]
        );

        $this->runs->create($adminId, 'clients', 'clients.csv', 1, 1, 0, []);
        $this->runs->create($adminId, 'services', 'services.csv', 1, 1, 0, []);
        $this->runs->create($adminId, 'invoices', 'invoices.csv', 1, 1, 0, []);

        $servicesOnly = $this->runs->recentByType('services');
        $this->assertCount(1, $servicesOnly);
        $this->assertSame('services.csv', $servicesOnly[0]['filename']);
    }
}
