<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Mail\SendEmailJob;
use CodeVault\Queue\Job;
use CodeVault\Queue\QueueInterface;
use CodeVault\Support\FormattedText;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The HTML an email actually ends up containing, for a body written as plain text.
 *
 * This exists because a REAL admin email arrived with its markup shown as text:
 * every line ended in a literal `<br />`, and the client's address rendered as
 * `&lt;souhail.m111@gmail.com&gt;` instead of `<souhail.m111@gmail.com>`.
 *
 * The cause was not a bad template. It was the same string being converted from
 * plain text to HTML TWICE:
 *
 *  1. the caller did nl2br(htmlspecialchars($body)) and handed that to sendRaw();
 *  2. sendRaw() -> wrapInModernLayout() -> EmailContent::toHtml() converts again.
 *
 * FormattedText::toHtml() is supposed to skip step 2 when the input is already
 * HTML, and it decides that with isHtml(), which matches only BLOCK tags
 * (p, div, table, ...). `br` is not one of them — so a body whose only markup was
 * `<br />` was classified as plain prose and escaped a second time. That is what
 * turned `<br />` into `&lt;br /&gt;` and `&lt;` into `&amp;lt;`.
 *
 * So these tests pin the CONTRACT rather than the symptom: sendRaw() takes text
 * as the author typed it, and converts it exactly once. The assertions are on the
 * HTML handed to the mailer, captured through a real dispatcher and a recording
 * queue — not on the converter in isolation, because the bug lived in the seam
 * BETWEEN the two layers and each layer was individually correct.
 */
final class EmailBodyRenderingTest extends DatabaseTestCase
{
    /** @var array<int, Job> */
    private array $jobs = [];

    private EmailDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->jobs = [];
        $sink = &$this->jobs;

        $queue = new class ($sink) implements QueueInterface {
            /** @param array<int, Job> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function push(Job $job): void
            {
                $this->sink[] = $job;
            }

            public function pop(string $queue = 'default'): ?Job
            {
                return null;
            }

            public function size(string $queue = 'default'): int
            {
                return count($this->sink);
            }
        };

        $this->dispatcher = new EmailDispatcher(
            new EmailTemplateRepository($this->db),
            new EmailLogRepository($this->db),
            $queue
        );
    }

    /** The body from the report that started this, verbatim. */
    private function reportBody(): string
    {
        $lines = [
            'CANCELLATION REPORT',
            '-------------------',
            'Status: approved',
            'Mode: Scheduled',
            '',
            'SERVICE',
            '  ID: #307',
            '  Product: IDS-1',
            '  Billing: monthly @ 52.99',
            '  Domain/Hostname: 192.151.157.58',
            '  Next due date: 2026-09-30',
            '  Current status: active',
            '',
            'CLIENT',
            '  souhail el meriouli <souhail.m111@gmail.com> (ID #86)',
            '',
            'REASON',
            '  Requested from client portal',
        ];

        return implode("\n", $lines);
    }

    /** The HTML that would be delivered, captured from the queued job. */
    private function deliver(string $body, string $subject = 'Cancellation completed'): string
    {
        $this->dispatcher->sendRaw($subject, $body, 'admin@example.test', null);

        $this->assertNotSame([], $this->jobs, 'sendRaw() queued nothing, so there is nothing to inspect');

        /** @var SendEmailJob $job */
        $job = $this->jobs[0];

        return $job->html;
    }

    // ------------------------------------------------------------- the defect

    public function test_a_plain_text_body_becomes_real_markup_exactly_once(): void
    {
        $html = $this->deliver($this->reportBody());

        // Real line breaks, not the word "<br />" printed at the end of a line.
        $this->assertStringContainsString('<br', $html, 'line breaks must be real markup');
        $this->assertStringNotContainsString('&lt;br', $html, 'the <br /> tags must not have been escaped into visible text');

        // The address is escaped exactly once, which is what makes it DISPLAY as
        // <souhail.m111@gmail.com>. Escaped twice, it displays as &lt;...&gt;.
        $this->assertStringContainsString('&lt;souhail.m111@gmail.com&gt;', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html, 'nothing may be escaped twice');
        $this->assertStringNotContainsString('&amp;gt;', $html);

        // The section headings survive as content rather than being swallowed.
        $this->assertStringContainsString('CANCELLATION REPORT', $html);
        $this->assertStringContainsString('Requested from client portal', $html);
    }

    public function test_the_plain_text_headings_are_not_turned_into_markup(): void
    {
        // A body with no blank lines is one paragraph; the single newlines become
        // line breaks and NOTHING in it is interpreted. This is the safety half of
        // the contract: fixing the double-escape must not open an injection hole
        // where a body can introduce its own tags.
        $html = $this->deliver("First\nsecond <script>alert(1)</script>");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_reported_symptom_is_reproducible_from_the_old_conversion(): void
    {
        // NEGATIVE CONTROL, and the reason the other assertions mean anything: the
        // old chain — pre-convert, then let the layout convert again — is what
        // produced the reported email. If this stopped reproducing, the tests above
        // would be passing because the harness changed rather than because the bug
        // was fixed.
        $body = $this->reportBody();

        $doubleConverted = FormattedText::toHtml(
            nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
            'margin:0 0 16px 0;'
        );

        $this->assertStringContainsString(
            '&lt;br',
            $doubleConverted,
            'the old chain must still reproduce the literal <br /> that was reported'
        );
        $this->assertStringContainsString(
            '&amp;lt;souhail.m111@gmail.com&amp;gt;',
            $doubleConverted,
            'the old chain must still double-escape the address that was reported'
        );
    }

    // ------------------------------------------------------------- the contract

    public function test_body_content_is_placed_inside_the_branded_shell(): void
    {
        $html = $this->deliver("Hello\n\nThere");

        // Both paragraphs, and the wrapper, so this is the real delivered document
        // and not a fragment.
        $this->assertStringContainsString('<p style="margin:0 0 16px 0;line-height:1.6;">Hello</p>', $html);
        $this->assertStringContainsString('There', $html);
        $this->assertStringContainsString('<!DOCTYPE html', $html);
    }

    public function test_a_body_that_is_already_a_full_document_is_left_alone(): void
    {
        // sendRaw() bypasses the shell for content that brings its own — pinned so
        // the escaping fix cannot be "achieved" by escaping everything blindly.
        $document = '<!DOCTYPE html><html><body><p>Already a document</p></body></html>';

        $this->assertSame($document, $this->deliver($document));
    }

    public function test_no_call_site_converts_a_body_before_handing_it_to_send_raw(): void
    {
        // sendRaw()'s contract is that the body arrives as the author typed it, and
        // wrapInModernLayout() states it converts plain prose itself — so a caller
        // that pre-escapes or pre-marks it up makes it convert twice.
        //
        // A source check is deliberate: `EmailDispatcher` is final, so a call site
        // cannot be spied on, and the regression is an ARGUMENT rather than a return
        // value. Same reason the route tables carry a seam test.
        //
        // It matches the call expression by PARENTHESIS MATCHING rather than per line.
        // The version of this test I wrote first scanned line-by-line, which would
        // NOT have caught the real defect: the offending call was written across four
        // lines, with `sendRaw(` and `nl2br(` on different ones. I only found that by
        // re-introducing the old code and watching the test pass when it should have
        // failed — a guard nobody has seen fail is not a guard.
        //
        // What it does NOT cover: a caller that converts into a variable first and
        // passes the variable. That shape cannot be told apart from any other string
        // statically, and catching it needs a runtime spy the final dispatcher
        // prevents. Stated here so the limit is known rather than assumed.
        $offenders = [];

        foreach ($this->phpFilesUnder(dirname(__DIR__, 2) . '/core') as $path) {
            $source = (string) file_get_contents($path);
            $offset = 0;

            while (($pos = strpos($source, 'sendRaw(', $offset)) !== false) {
                $offset = $pos + 1;

                $call = $this->callExpression($source, $pos);

                if ($call === null) {
                    continue;
                }

                foreach (['nl2br', 'htmlspecialchars', 'e('] as $converter) {
                    if (!str_contains($call, $converter)) {
                        continue;
                    }

                    $line = substr_count(substr($source, 0, $pos), "\n") + 1;
                    $offenders[] = str_replace(dirname(__DIR__, 2) . '/', '', $path)
                        . ':' . $line . ' passes ' . $converter . '() to sendRaw()';

                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "sendRaw() converts plain text itself; converting it first converts it twice:\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * The full `sendRaw(...)` call starting at $start, matched by balancing
     * parentheses so a call split across lines is captured whole.
     */
    private function callExpression(string $source, int $start): ?string
    {
        $open = $start + strlen('sendRaw');
        $length = strlen($source);
        $depth = 0;

        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '(') {
                $depth++;
                continue;
            }

            if ($source[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];
        $walker = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walker as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function test_the_dispatcher_still_logs_and_queues(): void
    {
        // The escaping fix touches how the body is wrapped, not whether the email is
        // recorded — a regression there would silently stop delivery.
        $logId = $this->dispatcher->sendRaw('Subject', 'Body', 'someone@example.test', null);

        $this->assertGreaterThan(0, $logId);
        $this->assertSame(1, count($this->jobs));

        $logged = $this->db->selectOne('SELECT to_email, subject FROM email_log WHERE id = ?', [$logId]);
        $this->assertSame('someone@example.test', (string) $logged['to_email']);
        $this->assertSame('Subject', (string) $logged['subject']);
    }
}
