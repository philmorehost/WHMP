<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Logging\ErrorTracker;
use CodeVault\Logging\LogManager;
use CodeVault\Support\App;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The logging pipeline: JSON lines per channel, shared context, redaction of
 * credentials, and the bounded error tracker. These are the parts a person
 * cannot eyeball in production — a broken formatter or an unredacted token is
 * only discovered by reading the file — so they are pinned here.
 */
final class LoggingTest extends TestCase
{
    private string $basePath;

    /** @var array<int, string> env keys this test set, restored in tearDown() */
    private array $touchedEnv = [];

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/codevault-logging-' . uniqid();
        mkdir($this->basePath . '/storage/logs', 0777, true);
        mkdir($this->basePath . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }

        $this->removeDirectory($this->basePath);
    }

    /**
     * A manager over a scratch storage directory. LOG_STDERR is off by default so
     * the JSON the pipeline writes does not land in the middle of PHPUnit's own
     * output — the file handler is what these tests read. Env values are restored
     * in tearDown(), so a level set here cannot leak into the next test.
     */
    private function manager(array $env = []): LogManager
    {
        foreach ($env + ['LOG_STDERR' => '0'] as $key => $value) {
            if (!in_array($key, $this->touchedEnv, true)) {
                $this->touchedEnv[] = $key;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }

        return new LogManager(new Config($this->basePath), $this->basePath);
    }

    /** The handler names rotated files `<channel>-YYYY-MM-DD.log`. */
    private function lines(string $channel): array
    {
        $files = glob($this->basePath . '/storage/logs/' . $channel . '*.log') ?: [];
        sort($files);
        $lines = [];

        foreach ($files as $file) {
            foreach (explode("\n", trim((string) file_get_contents($file))) as $line) {
                $decoded = json_decode($line, true);

                if (is_array($decoded)) {
                    $lines[] = $decoded;
                }
            }
        }

        return $lines;
    }

    public function test_a_channel_writes_one_json_object_per_line(): void
    {
        $logger = $this->manager()->channel('payments');
        $logger->info('payment.captured', ['invoice' => 41, 'amount' => '19.99']);

        $lines = $this->lines('payments');

        $this->assertCount(1, $lines);
        $this->assertSame('payment.captured', $lines[0]['message']);
        $this->assertSame('INFO', $lines[0]['level_name']);
        $this->assertSame(41, $lines[0]['context']['invoice']);
        $this->assertSame('payments', $lines[0]['channel']);
    }

    public function test_channels_are_separate_files_and_share_declared_context(): void
    {
        $logs = $this->manager();
        $logs->shareContext(['request_id' => 'abc123', 'entrypoint' => 'http']);
        $logs->channel('app')->info('one');
        $logs->channel('mail')->info('two');

        $this->assertSame('abc123', $this->lines('app')[0]['extra']['request_id']);
        $this->assertSame('abc123', $this->lines('mail')[0]['extra']['request_id']);
        $this->assertSame('http', $this->lines('mail')[0]['extra']['entrypoint']);
    }

    public function test_credential_shaped_context_is_redacted(): void
    {
        $logger = $this->manager()->channel('app');
        $logger->info('gateway.request', [
            'api_key' => 'sk_live_1234567890',
            'password' => 'hunter2',
            'card_number' => '4242424242424242',
            'nested' => ['session_id' => 'sess-abc', 'invoice' => 41],
            'safe' => 'kept',
        ]);

        $context = $this->lines('app')[0]['context'];

        $this->assertSame('[redacted]', $context['api_key']);
        $this->assertSame('[redacted]', $context['password']);
        $this->assertSame('[redacted]', $context['card_number']);
        $this->assertSame('[redacted]', $context['nested']['session_id']);
        $this->assertSame(41, $context['nested']['invoice']);
        $this->assertSame('kept', $context['safe']);
    }

    public function test_the_configured_level_filters_records(): void
    {
        $logger = $this->manager(['LOG_LEVEL' => 'warning'])->channel('app');
        $logger->info('not.written');
        $logger->warning('written');

        $lines = $this->lines('app');

        $this->assertCount(1, $lines);
        $this->assertSame('written', $lines[0]['message']);
    }

    public function test_an_exception_is_recorded_with_its_trace(): void
    {
        $logger = $this->manager()->channel('errors');
        $logger->error('boot.migrations_failed', [
            'exception' => new \RuntimeException('relation "invoices" does not exist'),
        ]);

        $line = $this->lines('errors')[0];

        $this->assertSame('RuntimeException', $line['context']['exception']['class']);
        $this->assertStringContainsString('does not exist', $line['context']['exception']['message']);
        $this->assertNotEmpty($line['context']['exception']['trace']);
    }

    public function test_stack_traces_can_be_switched_off(): void
    {
        $logger = $this->manager(['LOG_TRACES' => '0'])->channel('errors');
        $logger->error('payment.failed', ['exception' => new \RuntimeException('declined')]);

        $exception = $this->lines('errors')[0]['context']['exception'];

        $this->assertSame('RuntimeException', $exception['class']);
        $this->assertArrayNotHasKey('trace', $exception);
    }

    public function test_the_error_tracker_counts_repeats_of_the_same_failure(): void
    {
        $tracker = new ErrorTracker($this->basePath . '/storage/system/errors.json');

        $first = new \RuntimeException('deadlock on invoice 9');
        $same = new \RuntimeException('deadlock on invoice 9');
        $other = new \LogicException('something else entirely');

        $tracker->record($first, ['path' => '/admin/invoices']);
        $tracker->record($same);
        $tracker->record($other);

        $this->assertSame(2, $tracker->count());
        $this->assertSame(3, $tracker->totalOccurrences());

        $recent = $tracker->recent();
        $messages = array_column($recent, 'message');

        $this->assertContains('deadlock on invoice 9', $messages);

        $deadlock = $recent[array_search('deadlock on invoice 9', $messages, true)];
        $this->assertSame(2, $deadlock['count']);
        $this->assertSame('/admin/invoices', $deadlock['context']['path']);
    }

    public function test_the_error_tracker_redacts_secrets_it_is_given(): void
    {
        $tracker = new ErrorTracker($this->basePath . '/storage/system/errors.json');
        $tracker->record(new \RuntimeException('gateway rejected'), ['api_key' => 'sk_live_do_not_store']);

        $entry = $tracker->recent()[0];

        $this->assertSame('[redacted]', $entry['context']['api_key']);
    }

    public function test_the_error_tracker_stays_bounded(): void
    {
        $tracker = new ErrorTracker($this->basePath . '/storage/system/errors.json');

        for ($i = 0; $i < 80; $i++) {
            $tracker->record(new \RuntimeException("distinct failure {$i}"));
        }

        $this->assertLessThanOrEqual(50, $tracker->count());
    }

    public function test_clearing_the_tracker_empties_it(): void
    {
        $tracker = new ErrorTracker($this->basePath . '/storage/system/errors.json');
        $tracker->record(new \RuntimeException('gone in a moment'));
        $tracker->clear();

        $this->assertSame(0, $tracker->count());
    }

    public function test_the_logger_helper_returns_a_null_logger_before_the_app_boots(): void
    {
        // A view that merely wanted to log a line must not be the reason a page
        // 500s, so with no container the helper hands back a NullLogger instead
        // of throwing. The container is cleared explicitly: other tests in the
        // same process boot one, and this test is about the unbooted state.
        $previous = App::hasContainer() ? App::container() : null;
        App::setContainer(null);

        try {
            $this->assertInstanceOf(NullLogger::class, logger());
            $this->assertInstanceOf(LoggerInterface::class, logger('mail'));
        } finally {
            App::setContainer($previous);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
