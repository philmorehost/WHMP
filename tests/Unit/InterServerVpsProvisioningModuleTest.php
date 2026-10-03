<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Provisioning\InterServerVpsProvisioningModule;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Request/response shapes below are copied from InterServer's own live API
 * reference (my.interserver.net/api-docs, v0.9.0), captured during
 * development — not guessed. Two real inconsistencies in that
 * documentation are deliberately exercised here: /vps/order's worked
 * example uses `continue`+`serviceId` (not the `success`+`serviceid` the
 * prose section names), and getVpsSetupVnc's response has no fixed schema
 * at all per InterServer's own docs.
 *
 * Every lifecycle action (suspend/unsuspend/terminate/changePassword/
 * changePackage/singleSignOn/usage) makes two HTTP calls: a GET /vps list
 * to resolve the numeric vps_id by matching hostname (see the module's
 * class docblock for why), then the actual action. FakeHttpClient returns
 * one scripted response for every call until respondWith() is called
 * again, so a single VPS-list-shaped response (which has no `success`/
 * `continue` key, and therefore decodes as a successful 2xx either way)
 * is enough to drive both calls in most of these tests.
 */
final class InterServerVpsProvisioningModuleTest extends TestCase
{
    private FakeHttpClient $http;
    private InterServerVpsProvisioningModule $module;

    /** @var array<string, mixed> */
    private array $server = [
        'api_token' => 'ISK123',
        'default_platform' => 'kvm',
        'default_slices' => '1',
        'default_location' => '1',
    ];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->module = new InterServerVpsProvisioningModule($this->http);
    }

    public function test_create_builds_the_correct_order_request(): void
    {
        $this->http->respondWith(200, json_encode([
            'continue' => true,
            'errors' => [],
            'total_cost' => '5.00',
            'iid' => '25296600',
            'iids' => ['SERVICE12345'],
            'real_iids' => ['25296600'],
            'serviceId' => 12345,
            'invoice_description' => 'New Service Order',
        ]));

        $this->module->create(['username' => 'cv100', 'server' => $this->server, 'password' => 'RootPass123!']);

        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/order', $request['url']);
        $this->assertSame('ISK123', $request['headers']['X-API-KEY']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);

        $body = json_decode((string) $request['body'], true);
        $this->assertSame('cv100', $body['hostname']);
        $this->assertSame('kvm', $body['vpsPlatform']);
        $this->assertSame(1, $body['slices']);
        $this->assertSame('RootPass123!', $body['rootpass']);
        $this->assertSame('none', $body['controlpanel']);
    }

    public function test_create_reports_success_on_the_continue_response_shape(): void
    {
        $this->http->respondWith(200, json_encode(['continue' => true, 'serviceId' => 12345]));

        $result = $this->module->create(['username' => 'cv101', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('12345', $result['message']);
    }

    public function test_create_reports_failure_when_continue_is_false(): void
    {
        $this->http->respondWith(200, json_encode(['continue' => false, 'errors' => ['Invalid hostname']]));

        $result = $this->module->create(['username' => 'cv102', 'server' => $this->server]);

        $this->assertFalse($result['success']);
    }

    public function test_test_connection_lists_vps_services(): void
    {
        $this->http->respondWith(200, json_encode([
            ['vps_id' => '100', 'vps_hostname' => 'vps100', 'vps_status' => 'active'],
        ]));

        $result = $this->module->testConnection(['server' => $this->server]);

        $this->assertTrue($result['success']);
        $request = $this->http->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://my.interserver.net/apiv2/vps', $request['url']);
    }

    public function test_test_connection_fails_on_unauthorized(): void
    {
        $this->http->respondWith(401, json_encode(['error' => 'Unauthorized']));

        $result = $this->module->testConnection(['server' => $this->server]);

        $this->assertFalse($result['success']);
    }

    /**
     * Every lifecycle action first resolves the numeric vps_id by listing
     * services and matching hostname, then acts on the resolved id — both
     * calls share the one scripted list-shaped response here.
     */
    public function test_suspend_resolves_the_vps_id_by_hostname_then_calls_stop(): void
    {
        $this->http->respondWith(200, json_encode([
            ['vps_id' => '777', 'vps_hostname' => 'cv200', 'vps_status' => 'active'],
        ]));

        $result = $this->module->suspend(['username' => 'cv200', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $this->http->requests, 'one resolve call, one stop call');
        $this->assertSame('https://my.interserver.net/apiv2/vps', $this->http->requests[0]['url']);
        $this->assertSame('GET', $this->http->requests[1]['method']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/777/stop', $this->http->requests[1]['url']);
    }

    public function test_unsuspend_hits_the_start_endpoint(): void
    {
        $this->http->respondWith(200, json_encode([['vps_id' => '42', 'vps_hostname' => 'cv300']]));

        $result = $this->module->unsuspend(['username' => 'cv300', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/42/start', $this->http->lastRequest()['url']);
    }

    public function test_terminate_sends_a_delete_to_the_resolved_vps_id(): void
    {
        $this->http->respondWith(200, json_encode([['vps_id' => '55', 'vps_hostname' => 'cv400']]));

        $result = $this->module->terminate(['username' => 'cv400', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame('DELETE', $this->http->lastRequest()['method']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/55', $this->http->lastRequest()['url']);
    }

    public function test_change_password_sends_json_body_to_change_root_password(): void
    {
        $this->http->respondWith(200, json_encode([['vps_id' => '88', 'vps_hostname' => 'cv500']]));

        $result = $this->module->changePassword(['username' => 'cv500', 'server' => $this->server, 'password' => 'NewPass1!']);

        $this->assertTrue($result['success']);
        $request = $this->http->lastRequest();
        $this->assertSame('https://my.interserver.net/apiv2/vps/88/change_root_password', $request['url']);
        $body = json_decode((string) $request['body'], true);
        $this->assertSame('NewPass1!', $body['password']);
    }

    public function test_change_package_sends_slices_to_the_slices_endpoint(): void
    {
        $this->http->respondWith(200, json_encode([['vps_id' => '99', 'vps_hostname' => 'cv600']]));

        $result = $this->module->changePackage(['username' => 'cv600', 'server' => $this->server, 'package' => 4]);

        $this->assertTrue($result['success']);
        $request = $this->http->lastRequest();
        $this->assertSame('https://my.interserver.net/apiv2/vps/99/slices', $request['url']);
        $body = json_decode((string) $request['body'], true);
        $this->assertSame(4, $body['slices']);
    }

    public function test_change_package_rejects_a_missing_target_slice_count(): void
    {
        $this->http->respondWith(200, json_encode([['vps_id' => '99', 'vps_hostname' => 'cv601']]));

        $result = $this->module->changePackage(['username' => 'cv601', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        // Only the resolve call happens — no slices request without a target count.
        $this->assertCount(1, $this->http->requests);
    }

    /**
     * usage() makes two calls (resolve, then traffic_usage) but
     * FakeHttpClient only scripts one response for both. This body is
     * deliberately shaped to satisfy both reads at once: resolveVpsId()'s
     * `foreach ($decoded['data'] as $row)` iterates every top-level key —
     * including the numeric-keyed row carrying vps_hostname — while the
     * traffic_usage read pulls `totals.month` straight off the same
     * decoded object. Not a realistic single real response, but a valid
     * way to exercise the real byte→MB conversion math against this test
     * double's one-response-per-script limitation.
     */
    public function test_usage_converts_month_totals_from_bytes_to_megabytes(): void
    {
        $this->http->respondWith(200, json_encode([
            'totals' => ['month' => ['in' => 1048576, 'out' => 1048576]],
            '0' => ['vps_id' => '11', 'vps_hostname' => 'cv700'],
        ]));

        $result = $this->module->usage(['username' => 'cv700', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame(2.0, $result['bandwidthUsedMb']);
    }

    public function test_usage_reports_failure_when_vps_cannot_be_resolved(): void
    {
        $this->http->respondWith(200, json_encode([['vps_id' => '1', 'vps_hostname' => 'someone-elses-vps']]));

        $result = $this->module->usage(['username' => 'cv701-not-found', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not linked', $result['message']);
    }

    public function test_single_sign_on_reports_not_provisioned_when_vnc_info_is_empty(): void
    {
        $this->http->respondWith(200, json_encode([['vps_id' => '23', 'vps_hostname' => 'cv801']]));

        $result = $this->module->singleSignOn(['username' => 'cv801', 'server' => $this->server]);

        // The shared list-shaped response has no ip/vnc field, so this
        // correctly reports "not provisioned" rather than fabricating a URL.
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No VNC console', $result['message']);
    }

    public function test_lifecycle_action_fails_gracefully_when_hostname_is_not_found(): void
    {
        $this->http->respondWith(200, json_encode([['vps_id' => '1', 'vps_hostname' => 'someone-elses-vps']]));

        $result = $this->module->suspend(['username' => 'cv999-not-found', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not linked', $result['message']);
        // Only the resolve call happens — no action call once resolution fails.
        $this->assertCount(1, $this->http->requests);
    }

    public function test_unreachable_api_reports_failure_without_throwing(): void
    {
        $this->http->respondWith(0, '');

        $result = $this->module->testConnection(['server' => $this->server]);

        $this->assertFalse($result['success']);
    }

    public function test_reinstall_posts_template_to_reinstall_os_path(): void
    {
        $this->http->respondWith(200, json_encode([
            '0' => ['vps_id' => '12', 'vps_hostname' => 'cv900'],
            'success' => true,
            'text' => 'Reinstalling OS'
        ]));

        $result = $this->module->reinstall([
            'username' => 'cv900',
            'server' => $this->server,
            'template' => 'centos-7-x86_64.qcow2',
            'localPassword' => 'myadmin-secret',
        ]);

        $this->assertTrue($result['success']);
        // `/vps/{id}/reinstall` is not a route on InterServer — it 404s. The
        // documented path is `/reinstall_os`, keyed by a `template_file`.
        $this->assertSame('https://my.interserver.net/apiv2/vps/12/reinstall_os', $this->http->lastRequest()['url']);
        $this->assertSame('POST', $this->http->lastRequest()['method']);
        $this->assertSame(
            ['template' => 'centos-7-x86_64.qcow2', 'localPassword' => 'myadmin-secret'],
            json_decode($this->http->lastRequest()['body'], true)
        );
    }

    /**
     * Reinstall is destructive and InterServer re-checks the MyAdmin account
     * password on every call. WHMP has no column for it, so the module must
     * refuse locally rather than fire a call that gets rejected anyway.
     */
    public function test_reinstall_without_local_password_makes_no_destructive_call(): void
    {
        $this->http->respondWith(200, json_encode([
            '0' => ['vps_id' => '12', 'vps_hostname' => 'cv900'],
        ]));

        $result = $this->module->reinstall([
            'username' => 'cv900',
            'server' => $this->server,
            'template' => 'centos-7-x86_64.qcow2',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('account password', $result['message']);
        // Only the hostname->id lookup should have happened, never the POST.
        $this->assertSame('GET', $this->http->lastRequest()['method']);
    }

    public function test_set_reverse_dns_sends_ip_keyed_map(): void
    {
        $this->http->respondWith(200, json_encode([
            '0' => ['vps_id' => '12', 'vps_hostname' => 'cv900'],
            'success' => true,
            'text' => 'rDNS updated'
        ]));

        $result = $this->module->setReverseDns([
            'username' => 'cv900',
            'server' => $this->server,
            'ip' => '10.0.0.7',
            'rdns' => 'ptr.example.com',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/12/reverse_dns', $this->http->lastRequest()['url']);
        $this->assertSame('POST', $this->http->lastRequest()['method']);
        // A flat {rdns: "..."} body names no IP, so InterServer applied
        // nothing and still returned 200 — the bug this pins shut.
        $this->assertSame(
            ['ips' => ['10.0.0.7' => 'ptr.example.com']],
            json_decode($this->http->lastRequest()['body'], true)
        );
    }

    public function test_set_reverse_dns_requires_an_ip(): void
    {
        $this->http->respondWith(200, json_encode([
            '0' => ['vps_id' => '12', 'vps_hostname' => 'cv900'],
        ]));

        $result = $this->module->setReverseDns([
            'username' => 'cv900',
            'server' => $this->server,
            'rdns' => 'ptr.example.com',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('GET', $this->http->lastRequest()['method']);
    }

    public function test_power_restart_calls_documented_restart_endpoint(): void
    {
        $this->http->respondWith(200, json_encode([
            '0' => ['vps_id' => '12', 'vps_hostname' => 'cv900'],
            'text' => 'Action has been sent to the server.',
            'queueId' => 991,
        ]));

        $result = $this->module->power(['username' => 'cv900', 'server' => $this->server], 'restart');

        $this->assertTrue($result['success']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/12/restart', $this->http->lastRequest()['url']);
        $this->assertSame('GET', $this->http->lastRequest()['method']);
    }

    public function test_power_rejects_unknown_action_without_calling_the_api(): void
    {
        $result = $this->module->power(['username' => 'cv900', 'server' => $this->server], 'obliterate');

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->http->requests);
    }

    public function test_create_backup_calls_backup_endpoint(): void
    {
        $this->http->respondWith(200, json_encode([
            '0' => ['vps_id' => '12', 'vps_hostname' => 'cv900'],
            'text' => 'Action has been sent to the server.',
            'queueId' => 55,
        ]));

        $result = $this->module->createBackup(['username' => 'cv900', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/12/backup', $this->http->lastRequest()['url']);
    }

    /** A restore is keyed by the composite `<type>:<service>:<name>`, not the bare filename. */
    public function test_list_backups_builds_composite_restore_reference(): void
    {
        $this->http->respondInSequence([
            ['status' => 200, 'body' => json_encode(['0' => ['vps_id' => '12', 'vps_hostname' => 'cv900']])],
            ['status' => 200, 'body' => json_encode([
                ['name' => 'vps-12-2026-05-12.tar.gz', 'type' => 'minio', 'service' => 12, 'size' => 2048],
            ])],
        ]);

        $result = $this->module->listBackups(['username' => 'cv900', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame('minio:12:vps-12-2026-05-12.tar.gz', $result['backups'][0]['ref']);
        $this->assertSame(2048, $result['backups'][0]['sizeBytes']);
    }

    public function test_info_reports_real_vps_status(): void
    {
        $this->http->respondInSequence([
            ['status' => 200, 'body' => json_encode(['0' => ['vps_id' => '12', 'vps_hostname' => 'cv900']])],
            ['status' => 200, 'body' => json_encode([
                'vps_id' => 12,
                'vps_hostname' => 'cv900',
                'vps_ip' => '10.0.0.7',
                'vps_status' => 'suspended',
                'services_name' => 'KVM',
            ])],
        ]);

        $result = $this->module->info(['username' => 'cv900', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame('suspended', $result['info']['status']);
        $this->assertSame('10.0.0.7', $result['info']['ip']);
    }

    /**
     * WHMCS-imported VPS services carry the real hostname in
     * services.hostname with a generic username like "root". The lifecycle
     * lookup must resolve by that hostname, not the useless username — this
     * is the failure behind "your VPS wasn't found at the provider" for
     * every imported service.
     */
    public function test_whmcs_imported_vps_resolves_by_service_hostname(): void
    {
        $this->http->respondWith(200, json_encode([
            ['vps_id' => '12', 'vps_hostname' => 'vps200.example.com', 'vps_status' => 'active'],
        ]));

        $result = $this->module->power(
            ['username' => 'root', 'hostname' => 'vps200.example.com', 'server' => $this->server],
            'restart'
        );

        $this->assertTrue($result['success']);
        $this->assertCount(2, $this->http->requests, 'one resolve call, one restart call');
        $this->assertSame('https://my.interserver.net/apiv2/vps', $this->http->requests[0]['url']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/12/restart', $this->http->requests[1]['url']);
    }

    /**
     * When both identifiers are present, the recorded hostname must win
     * even if a different VPS happens to be literally named like the
     * generic username. A first-match-wins scan against either would
     * hijack the imported service onto the wrong VPS.
     */
    public function test_recorded_hostname_wins_over_a_vps_named_like_the_username(): void
    {
        $this->http->respondWith(200, json_encode([
            ['vps_id' => '77', 'vps_hostname' => 'root'],
            ['vps_id' => '88', 'vps_hostname' => 'vps200.example.com'],
        ]));

        $result = $this->module->power(
            ['username' => 'root', 'hostname' => 'vps200.example.com', 'server' => $this->server],
            'restart'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/88/restart', $this->http->lastRequest()['url']);
    }

    public function test_username_still_resolves_when_hostname_is_unset(): void
    {
        $this->http->respondWith(200, json_encode([
            ['vps_id' => '99', 'vps_hostname' => 'cv300', 'vps_status' => 'active'],
        ]));

        // App-created services: services.hostname is null and the username
        // IS the hostname create() sent to InterServer.
        $result = $this->module->unsuspend(['username' => 'cv300', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/99/start', $this->http->lastRequest()['url']);
    }

    /* ---- manually set-up VPSes: explicit link, IP matching, UUID paths ---- */

    public function test_a_linked_remote_id_is_used_directly_without_listing(): void
    {
        $this->http->respondWith(200, json_encode(['text' => 'Action has been sent to the server.', 'queueId' => 1]));

        $result = $this->module->power([
            'username' => null,
            'remote_id' => '0196bd0a-5b4c-7e2a-91d3-f1a4c7b90e55',
            'server' => $this->server,
        ], 'restart');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->http->requests, 'no /vps list call when the service is linked');
        $this->assertSame('https://my.interserver.net/apiv2/vps/0196bd0a-5b4c-7e2a-91d3-f1a4c7b90e55/restart', $this->http->lastRequest()['url']);
    }

    public function test_a_remote_id_that_is_not_an_id_is_never_put_in_a_path(): void
    {
        $this->http->respondWith(200, json_encode([['vps_id' => '5', 'vps_hostname' => 'other']]));

        $result = $this->module->power(['remote_id' => '../account', 'hostname' => 'box.example.com', 'server' => $this->server], 'stop');

        $this->assertFalse($result['success']);
        foreach ($this->http->requests as $request) {
            $this->assertStringNotContainsString('..', $request['url']);
        }
    }

    public function test_a_hand_built_vps_is_found_by_its_ip_and_addressed_by_uuid(): void
    {
        $this->http->respondInSequence([
            ['status' => 200, 'body' => json_encode([
                ['vps_id' => '100', 'vps_uuid' => 'aaaaaaaa-0000-0000-0000-000000000001', 'vps_hostname' => 'vps100', 'vps_ip' => '64.20.46.220'],
                ['vps_id' => '101', 'vps_uuid' => 'aaaaaaaa-0000-0000-0000-000000000002', 'vps_hostname' => 'vps101', 'vps_ip' => '64.20.46.221'],
            ])],
            ['status' => 200, 'body' => json_encode(['text' => 'queued', 'queueId' => 7])],
        ]);

        // WHMP knows it as "server1.client.com" with IP .221; InterServer calls it vps101.
        $result = $this->module->createBackup([
            'username' => null,
            'hostname' => 'server1.client.com',
            'dedicated_ip' => '64.20.46.221',
            'server' => $this->server,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/aaaaaaaa-0000-0000-0000-000000000002/backup', $this->http->lastRequest()['url']);
    }

    public function test_an_assigned_sub_ip_also_identifies_the_vps(): void
    {
        $this->http->respondWith(200, json_encode([
            ['vps_id' => '100', 'vps_uuid' => 'aaaaaaaa-0000-0000-0000-000000000009', 'vps_hostname' => 'vps100', 'vps_ip' => '64.20.46.230'],
        ]));

        $resolved = $this->module->resolveRemote([
            'hostname' => '', 'assigned_ips' => "10.1.1.1\n64.20.46.230", 'server' => $this->server,
        ]);

        $this->assertSame(['ref' => 'aaaaaaaa-0000-0000-0000-000000000009', 'via' => 'ip'], $resolved);
    }

    public function test_remote_services_lists_the_account_for_the_admin_picker(): void
    {
        $this->http->respondWith(200, json_encode([
            ['vps_id' => '100', 'vps_uuid' => 'u-1', 'vps_name' => 'Builder', 'vps_hostname' => 'vps100', 'vps_ip' => '64.20.46.220', 'vps_status' => 'active'],
            ['vps_id' => '101', 'vps_hostname' => 'vps101', 'vps_ip' => '64.20.46.221', 'vps_status' => 'suspended'],
        ]));

        $result = $this->module->remoteServices($this->server);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['services']);
        $this->assertSame('u-1', $result['services'][0]['ref']);
        $this->assertSame('vps100 (64.20.46.220) · Builder', $result['services'][0]['label']);
        $this->assertSame('101', $result['services'][1]['ref'], 'no uuid: the legacy id is the ref');
        $this->assertStringContainsString('suspended', $result['services'][1]['label']);
    }

    public function test_info_reads_the_nested_service_info_shape(): void
    {
        $this->http->respondWith(200, json_encode([
            'serviceInfo' => ['vps_id' => '85872', 'vps_hostname' => 'vps85872', 'vps_ip' => '1.2.3.4', 'vps_status' => 'active', 'vps_server_status' => 'running', 'vps_os' => 'ubuntu24', 'vps_slices' => '2'],
            'serviceMaster' => ['vps_ip' => '10.11.12.13'],
            'services_name' => 'KVM',
        ]));

        $result = $this->module->info(['remote_id' => 'u-1', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame('running', $result['info']['status']);
        $this->assertSame('active', $result['info']['serviceStatus']);
        $this->assertSame('1.2.3.4', $result['info']['ip']);
        $this->assertSame(2, $result['info']['slices']);
    }

    public function test_console_allows_the_client_ip_then_reads_host_and_port(): void
    {
        $this->http->respondInSequence([
            ['status' => 200, 'body' => json_encode(['text' => 'Action has been sent', 'queueId' => 3])],
            ['status' => 200, 'body' => json_encode([
                'serviceInfo' => ['vps_vnc' => '203.0.113.9', 'vps_vnc_port' => '5902'],
                'serviceMaster' => ['vps_ip' => '66.45.1.10'],
            ])],
        ]);

        $result = $this->module->console(['remote_id' => 'u-1', 'server' => $this->server], '203.0.113.9');

        $setup = $this->http->requests[0];
        $this->assertSame('POST', $setup['method']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/u-1/setup_vnc', $setup['url']);
        $this->assertSame(['vnc' => '203.0.113.9'], json_decode((string) $setup['body'], true));

        $this->assertTrue($result['success']);
        $this->assertSame('vnc://66.45.1.10:5902', $result['url']);
        $this->assertStringContainsString('66.45.1.10:5902', $result['message']);
        $this->assertStringContainsString('203.0.113.9', $result['message']);
    }

    public function test_console_does_not_try_to_allow_a_private_address(): void
    {
        $this->http->respondWith(200, json_encode(['serviceInfo' => ['vps_vnc_port' => '5901'], 'serviceMaster' => ['vps_ip' => '66.45.1.10']]));

        $result = $this->module->console(['remote_id' => 'u-1', 'server' => $this->server], '192.168.1.5');

        foreach ($this->http->requests as $request) {
            $this->assertSame('GET', $request['method']);
        }
        $this->assertTrue($result['success']);
        $this->assertSame('vnc://66.45.1.10:5901', $result['url']);
    }

    public function test_reinstall_uses_the_saved_account_password(): void
    {
        $this->http->respondWith(200, json_encode(['text' => 'Reinstall sent', 'queueId' => 9]));
        $server = $this->server + ['account_password' => 'MyAdminPass!'];

        $result = $this->module->reinstall(['remote_id' => 'u-1', 'server' => $server, 'template' => 'ubuntu-22.04', 'password' => 'NewRoot#2026']);

        $this->assertTrue($result['success']);
        $request = $this->http->lastRequest();
        $this->assertSame('https://my.interserver.net/apiv2/vps/u-1/reinstall_os', $request['url']);
        $this->assertSame(['template' => 'ubuntu-22.04', 'localPassword' => 'MyAdminPass!', 'password' => 'NewRoot#2026'], json_decode((string) $request['body'], true));
    }

    public function test_restore_posts_the_backup_ref_with_the_account_password(): void
    {
        $this->http->respondWith(200, json_encode(['text' => 'Restore sent', 'queueId' => 10]));
        $server = $this->server + ['account_password' => 'MyAdminPass!'];

        $result = $this->module->restore(['remote_id' => 'u-1', 'server' => $server, 'backup' => 'zfs:2722890:first']);

        $this->assertTrue($result['success']);
        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://my.interserver.net/apiv2/vps/u-1/restore', $request['url']);
        $this->assertSame(['backup' => 'zfs:2722890:first', 'password' => 'MyAdminPass!'], json_decode((string) $request['body'], true));
    }

    public function test_restore_without_an_account_password_makes_no_destructive_call(): void
    {
        $result = $this->module->restore(['remote_id' => 'u-1', 'server' => $this->server, 'backup' => 'zfs:1:first']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('account password', $result['message']);
        $this->assertCount(0, $this->http->requests);
    }
}
