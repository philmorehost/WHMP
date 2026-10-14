<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Domains\ResellerClubRegistrarModule;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ResellerClubDnssecTest extends TestCase
{
    public function test_ds_changes_use_documented_order_and_attribute_parameters(): void
    {
        $http=new FakeHttpClient();
        $module=new ResellerClubRegistrarModule($http);
        $http->respondInSequence([
            ['status'=>200,'body'=>'"81342"'],
            ['status'=>200,'body'=>'{}'],
            ['status'=>200,'body'=>'"81342"'],
            ['status'=>200,'body'=>'{}'],
        ]);
        $registrar=['reseller_id'=>'12345','api_key'=>'test-api-key'];
        $ds=['key_tag'=>2371,'algorithm'=>13,'digest_type'=>2,'digest'=>str_repeat('A1B2C3D4',8)];

        $this->assertTrue($module->supportsDsRecord(['domain'=>'example.com']));
        $this->assertFalse($module->supportsDsRecord(['domain'=>'example.co.uk']));
        $this->assertTrue($module->addDsRecord(['domain'=>'example.com','registrar'=>$registrar,'ds'=>$ds])['success']);
        $this->assertTrue($module->deleteDsRecord(['domain'=>'example.com','registrar'=>$registrar,'ds'=>$ds])['success']);
        $this->assertCount(4,$http->requests);

        parse_str((string)parse_url($http->requests[1]['url'],PHP_URL_QUERY),$add);
        $this->assertStringContainsString('/domains/add-dnssec.json',$http->requests[1]['url']);
        $this->assertSame('81342',$add['order-id']);
        $this->assertSame('keytag',$add['attr-name1']);
        $this->assertSame('2371',$add['attr-value1']);
        $this->assertSame('algorithm',$add['attr-name2']);
        $this->assertSame('13',$add['attr-value2']);
        $this->assertSame('digesttype',$add['attr-name3']);
        $this->assertSame('2',$add['attr-value3']);
        $this->assertSame('digest',$add['attr-name4']);
        $this->assertSame($ds['digest'],$add['attr-value4']);

        parse_str((string)parse_url($http->requests[3]['url'],PHP_URL_QUERY),$delete);
        $this->assertStringContainsString('/domains/del-dnssec.json',$http->requests[3]['url']);
        $this->assertSame('81342',$delete['order-id']);
    }

    public function test_unsupported_tld_or_malformed_digest_never_calls_registrar(): void
    {
        $http=new FakeHttpClient();
        $module=new ResellerClubRegistrarModule($http);
        $params=['domain'=>'example.co.uk','registrar'=>['reseller_id'=>'12345','api_key'=>'secret'],
            'ds'=>['key_tag'=>2371,'algorithm'=>13,'digest_type'=>2,'digest'=>str_repeat('A1B2C3D4',8)]];

        $this->assertFalse($module->addDsRecord($params)['success']);
        $params['domain']='example.com';
        $params['ds']['digest']='bad';
        $this->assertFalse($module->deleteDsRecord($params)['success']);
        $this->assertSame([],$http->requests);
    }
}
