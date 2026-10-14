<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use Throwable;

/** Phase 2 Cloudflare features: Free-plan Rulesets, analytics, DNSSEC and Origin CA. */
final class CloudflareFeatures
{
    public function __construct(
        private readonly CloudflareService $service,
        private readonly CloudflareZoneRepository $zones,
        private readonly ?NameserverGateway $nameservers = null,
        private readonly ?OriginCertificateInstaller $installer = null,
        private readonly ?OriginCertificateKeyGenerator $keyGenerator = null
    ) {
    }

    /** @param array<string,mixed> $zone @return array<string,array{rules:array<int,array<string,mixed>>,limit:int,error:?string}> */
    public function rules(array $zone): array
    {
        $out = [];
        foreach (CloudflareRules::KINDS as $kind => $meta) {
            try {
                $set = $this->service->apiClient()->entrypoint((string) $zone['cf_zone_id'], $meta['phase']);
                $out[$kind] = ['rules' => array_values(array_filter((array) ($set['rules'] ?? []), 'is_array')), 'limit' => $meta['limit'], 'error' => null];
            } catch (CloudflareApiException $e) {
                $out[$kind] = ['rules' => [], 'limit' => $meta['limit'], 'error' => $e->getMessage()];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $zone @param array<string,mixed> $input @param array{type:string,id:?int} $actor @return array{ok:bool,message:string} */
    public function addRule(array $zone, string $kind, array $input, array $actor): array
    {
        $rule = CloudflareRules::build($kind, $input, (string) $zone['name'], $error);
        if ($rule === null) { return self::fail($error); }
        if (!isset(CloudflareRules::KINDS[$kind])) { return self::fail('Unknown rule type.'); }
        $meta = CloudflareRules::KINDS[$kind];
        $api = $this->service->apiClient();
        $zoneId = (string) $zone['cf_zone_id'];

        try {
            $set = $api->entrypoint($zoneId, $meta['phase']);
            $existing = array_values(array_filter((array) ($set['rules'] ?? []), 'is_array'));
            if (count($existing) >= $meta['limit']) {
                return self::fail('The Cloudflare Free plan allows ' . $meta['limit'] . ' ' . strtolower($meta['label']) . ' per domain. Remove one first.');
            }
            foreach ($existing as $other) {
                if (($other['expression'] ?? null) === $rule['expression'] && ($other['action'] ?? null) === $rule['action']) {
                    return self::fail('An identical rule already exists.');
                }
            }
            if ($set === null || empty($set['id'])) { $api->createEntrypoint($zoneId, $meta['phase'], [$rule]); }
            else { $api->addRulesetRule($zoneId, (string) $set['id'], $rule); }
        } catch (CloudflareApiException $e) { return self::fail($e->getMessage()); }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'ruleset_added', ucfirst($kind) . ' rule: ' . $rule['description']);
        return ['ok' => true, 'message' => 'Rule added. It takes effect within a few seconds.'];
    }

    /** @param array<string,mixed> $zone @param array{type:string,id:?int} $actor @return array{ok:bool,message:string} */
    public function addPreset(array $zone, string $preset, array $actor): array
    {
        $presets = CloudflareRules::presets((string) $zone['name']);
        if (!isset($presets[$preset])) { return self::fail('Unknown preset.'); }
        return $this->addRule($zone, $presets[$preset]['kind'], $presets[$preset]['input'], $actor);
    }

    /** @param array<string,mixed> $zone @param array{type:string,id:?int} $actor @return array{ok:bool,message:string} */
    public function deleteRule(array $zone, string $kind, string $ruleId, array $actor): array
    {
        if (!isset(CloudflareRules::KINDS[$kind])) { return self::fail('Unknown rule type.'); }
        $api = $this->service->apiClient();
        $zoneId = (string) $zone['cf_zone_id'];
        try {
            $set = $api->entrypoint($zoneId, CloudflareRules::KINDS[$kind]['phase']);
            $match = null;
            foreach ((array) ($set['rules'] ?? []) as $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') === $ruleId) { $match = $row; break; }
            }
            if ($set === null || $match === null) { return self::fail('That rule no longer exists.'); }
            $api->deleteRulesetRule($zoneId, (string) $set['id'], $ruleId);
        } catch (CloudflareApiException $e) { return self::fail($e->getMessage()); }
        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'ruleset_deleted', ucfirst($kind) . ' rule removed: ' . mb_substr((string) ($match['description'] ?? ''), 0, 100));
        return ['ok' => true, 'message' => 'Rule removed.'];
    }

    /** @param array<string,mixed> $zone @return array<string,mixed> */
    public function analytics(array $zone, int $days, ?int $now = null): array
    {
        $days = in_array($days, [7, 30], true) ? $days : 7;
        $now ??= time();
        $since = gmdate('Y-m-d', $now - ($days - 1) * 86400);
        $until = gmdate('Y-m-d', $now);
        try { $groups = $this->service->apiClient()->dailyTraffic((string) $zone['cf_zone_id'], $since, $until); }
        catch (CloudflareApiException $e) { return self::fail($e->getMessage()); }
        $byDate = [];
        foreach ($groups as $group) {
            $date = (string) ($group['dimensions']['date'] ?? '');
            if ($date !== '') { $byDate[$date] = $group; }
        }
        $totals = ['requests'=>0,'cached'=>0,'bytes'=>0,'cachedBytes'=>0,'threats'=>0,'pageViews'=>0,'uniques'=>0];
        $countries = []; $rows = [];
        $start = strtotime($since . ' 00:00:00 UTC') ?: $now;
        for ($i=0; $i<$days; $i++) {
            $date = gmdate('Y-m-d', $start + $i*86400);
            $group = (array) ($byDate[$date] ?? []); $sum = (array) ($group['sum'] ?? []);
            $row = ['date'=>$date,'requests'=>(int)($sum['requests']??0),'cached'=>(int)($sum['cachedRequests']??0),
                'bytes'=>(int)($sum['bytes']??0),'cachedBytes'=>(int)($sum['cachedBytes']??0),'threats'=>(int)($sum['threats']??0),
                'pageViews'=>(int)($sum['pageViews']??0),'uniques'=>(int)(($group['uniq']['uniques']??0))];
            foreach ($totals as $key=>$value) { $totals[$key] = $value + $row[$key]; }
            foreach ((array) ($sum['countryMap'] ?? []) as $country) {
                $name = (string) ($country['clientCountryName'] ?? '');
                if ($name !== '' && $name !== 'XX') { $countries[$name] = ($countries[$name] ?? 0) + (int) ($country['requests'] ?? 0); }
            }
            $rows[] = $row;
        }
        arsort($countries);
        return ['ok'=>true,'message'=>'OK','days'=>$rows,'totals'=>$totals,'countries'=>array_slice($countries,0,8,true)];
    }

    /** @param array<string,mixed> $zone @return array<string,mixed> */
    public function dnssec(array $zone): array
    {
        try { $remote = $this->service->apiClient()->dnssec((string) $zone['cf_zone_id']); }
        catch (CloudflareApiException $e) { return self::fail($e->getMessage()); }
        $status = (string) ($remote['status'] ?? 'disabled');
        $disableScheduled=(int)($zone['dnssec_ds_removed']??0)===1 && !empty($zone['dnssec_disable_after']);
        if ($disableScheduled || ($status === 'disabled' && in_array((string)($zone['dnssec_status']??''),['active','pending','pending-disabled'],true)
            && (int)($zone['dnssec_ds_removed']??0) !== 1)) {
            $status = 'pending-disabled';
        }
        $ds = self::dsFrom($remote);
        $fields = ['dnssec_status'=>mb_substr($status,0,20)];
        if ($ds !== null) { $fields['dnssec_ds'] = $ds; }
        $this->zones->update((int) $zone['id'], $fields);
        $domain = $this->service->registeredDomain((string)$zone['name'], (int)$zone['client_id']);
        $canPublish = false;
        if ($domain !== null && $this->nameservers !== null) {
            try { $canPublish = $this->nameservers->supportsDs((int)$domain['id']); } catch (Throwable) {}
        }
        return ['ok'=>true,'message'=>'OK','status'=>$status,'ds'=>$ds ?? ($zone['dnssec_ds'] ?? null),
            'canPublish'=>$canPublish,'publishedByUs'=>(int)($zone['dnssec_ds_by_us']??0)===1];
    }

    /** @param array<string,mixed> $zone @param array{type:string,id:?int} $actor @return array{ok:bool,message:string} */
    public function enableDnssec(array $zone, array $actor): array
    {
        if (($zone['status'] ?? '') !== 'active') { return self::fail('Activate Cloudflare for this domain first.'); }
        if ($zone['delete_after'] !== null) { return self::fail('Choose Keep Cloudflare before enabling DNSSEC.'); }
        try {
            $api = $this->service->apiClient();
            $remote = $api->setDnssec((string)$zone['cf_zone_id'], true);
            if (self::dsFrom($remote) === null) { $remote = $api->dnssec((string)$zone['cf_zone_id']); }
        } catch (CloudflareApiException $e) { return self::fail($e->getMessage()); }
        $ds = self::dsFrom($remote);
        $this->zones->update((int)$zone['id'], ['dnssec_status'=>mb_substr((string)($remote['status']??'pending'),0,20),'dnssec_ds'=>$ds,'dnssec_ds_removed'=>false,'dnssec_disable_after'=>null]);
        if ($ds === null) { return ['ok'=>true,'message'=>'DNSSEC is being set up. Refresh this page shortly for the DS record.']; }
        if ((int)($zone['dnssec_ds_by_us']??0)===1) { return ['ok'=>true,'message'=>'DNSSEC is on and the registrar DS record is already in place.']; }
        $domain = $this->service->registeredDomain((string)$zone['name'], (int)$zone['client_id']);
        $supported = false;
        if ($domain !== null && $this->nameservers !== null) { try { $supported=$this->nameservers->supportsDs((int)$domain['id']); } catch (Throwable) {} }
        if (!$supported) { return ['ok'=>true,'message'=>'DNSSEC is on at Cloudflare. Add the DS record shown below at your domain registrar to finish.']; }
        try { $put = $this->nameservers->changeDs((int)$domain['id'], $ds, true); }
        catch (Throwable $e) { $put=['success'=>false,'message'=>$e->getMessage()]; }
        if (!($put['success']??false)) {
            $this->zones->log((int)$zone['id'],'system',null,'ds_add_failed','Adding registrar DS failed: '.mb_substr((string)($put['message']??''),0,150));
            return self::fail('DNSSEC is on at Cloudflare, but the registrar did not accept the DS record: '.mb_substr((string)($put['message']??'unknown error'),0,120).'. Try again or ask support.');
        }
        $this->zones->update((int)$zone['id'], ['dnssec_ds_by_us'=>true,'dnssec_ds_removed'=>false]);
        $this->zones->log((int)$zone['id'],$actor['type'],$actor['id'],'ds_added','DS record added at registrar (key tag '.$ds['key_tag'].').');
        return ['ok'=>true,'message'=>'DNSSEC is on. We added the DS record at your registrar; activation can take a few hours.'];
    }

    /** @param array<string,mixed> $zone @param array{type:string,id:?int} $actor @return array{ok:bool,message:string} */
    public function disableDnssec(array $zone, bool $confirmedRemoved, array $actor): array
    {
        if ((int)($zone['dnssec_ds_by_us']??0)===1) {
            if ($this->service->removeRegistrarDs($zone,$actor)!=='removed') { return self::fail('The registrar did not remove the DS record, so DNSSEC was left on. Please try again.'); }
            return $this->scheduleDnssecOff($zone,$actor,'We removed the DS record at the registrar.');
        }
        $status=(string)($zone['dnssec_status']??'');
        if ($status==='pending-disabled' && !empty($zone['dnssec_disable_after']) && strtotime((string)$zone['dnssec_disable_after'])>time()) {
            return self::fail('The DNSSEC wait period has not finished yet.');
        }
        $hasDs=is_array($zone['dnssec_ds']??null) && (int)($zone['dnssec_ds_removed']??0)!==1;
        $mayHaveRegistrarDs=in_array($status,['active','pending','pending-disabled'],true) && (int)($zone['dnssec_ds_removed']??0)!==1;
        if ($mayHaveRegistrarDs && !$hasDs) {
            return self::fail('Cloudflare has not returned the DS details needed to verify a safe DNSSEC change. Refresh this page and try again later.');
        }
        if ($mayHaveRegistrarDs && $hasDs) {
            if (!$confirmedRemoved) { return self::fail('Remove the DS record at your registrar, then confirm here to start a '.CloudflareService::DS_SETTLE_HOURS.'-hour DNS cache wait.'); }
            $this->zones->update((int)$zone['id'], ['dnssec_ds_removed'=>true]);
            return $this->scheduleDnssecOff($zone,$actor,'You confirmed the DS record was removed at the registrar.');
        }
        return $this->switchOffDnssec($zone,$actor);
    }

    /** @param array<string,mixed> $zone @param array{type:string,id:?int} $actor @return array{ok:bool,message:string} */
    private function scheduleDnssecOff(array $zone,array $actor,string $why): array
    {
        $when=date('Y-m-d H:i:s',time()+CloudflareService::DS_SETTLE_HOURS*3600);
        $fields=['dnssec_status'=>'pending-disabled','dnssec_ds_removed'=>true,'dnssec_disable_after'=>$when];
        if ($zone['delete_after']!==null) {
            if ((int)($zone['ns_switched_by_us']??0)===1) { $fields['ns_restore_after']=$when; }
            $minimum=date('Y-m-d H:i:s',strtotime($when)+86400);
            if ((string)$zone['delete_after']<$minimum) { $fields['delete_after']=$minimum; }
        }
        $this->zones->update((int)$zone['id'],$fields);
        $this->zones->log((int)$zone['id'],$actor['type'],$actor['id'],'dnssec_off_scheduled',$why.' Signing disabled after '.$when.'.');
        return ['ok'=>true,'message'=>$why.' Cloudflare stops signing in '.CloudflareService::DS_SETTLE_HOURS.' hours, once DNS caches clear.'];
    }

    /** @return array{ok:bool,message:string} */
    public function finishDnssecDisable(int $zoneRowId, ?int $now=null): array
    {
        $now ??= time(); $zone=$this->zones->find($zoneRowId);
        if ($zone===null || $zone['status']==='deleted' || $zone['dnssec_disable_after']===null) { return self::fail('Nothing to do.'); }
        if (strtotime((string)$zone['dnssec_disable_after'])>$now) { return self::fail('The DNSSEC wait period has not finished yet.'); }
        return $this->switchOffDnssec($zone,['type'=>'system','id'=>null]);
    }

    /** @param array<string,mixed> $zone @return array{supported:bool,certId:?string,expires:?string} */
    public function originCertificate(array $zone): array
    {
        $supported=$this->installer!==null && $zone['service_id']!==null && function_exists('openssl_csr_new');
        if ($supported) { try { $supported=$this->installer->supports((int)$zone['service_id']); } catch (Throwable) { $supported=false; } }
        return ['supported'=>$supported,'certId'=>$zone['origin_cert_id']??null,'expires'=>$zone['origin_cert_expires']??null];
    }

    /** @param array<string,mixed> $zone @param array{type:string,id:?int} $actor @return array{ok:bool,message:string} */
    public function installOriginCertificate(array $zone, bool $switchToStrict, array $actor): array
    {
        if (!$this->originCertificate($zone)['supported']) { return self::fail('Automatic origin certificates are available only for cPanel hosting.'); }
        if ($zone['status']==='deleted' || $zone['delete_after']!==null) { return self::fail('Cloudflare is not active for this domain.'); }
        $name=(string)$zone['name'];
        if ($this->keyGenerator===null) { return self::fail('Origin certificates are not configured on this server.'); }
        $keypair=$this->keyGenerator->generate($name);
        if (!($keypair['success']??false)) { return self::fail((string)($keypair['message']??'Could not create a certificate request.')); }
        $csr=(string)($keypair['csr']??''); $keyPem=(string)($keypair['privateKey']??'');
        if ($csr==='' || $keyPem==='') { return self::fail('The certificate request was incomplete.'); }
        try { $issued=$this->service->apiClient()->createOriginCertificate($csr,[$name,'*.'.$name]); }
        catch (CloudflareApiException $e) { return self::fail($e->getMessage()); }
        $certId=(string)($issued['id']??''); $cert=(string)($issued['certificate']??'');
        if ($certId==='' || !str_contains($cert,'BEGIN CERTIFICATE')) { return self::fail('Cloudflare did not return a certificate.'); }
        $installed=$this->installer->install((int)$zone['service_id'],$name,$cert,$keyPem);
        if (!($installed['success']??false)) {
            try { $this->service->apiClient()->revokeOriginCertificate($certId); } catch (Throwable) {}
            $this->zones->log((int)$zone['id'],$actor['type'],$actor['id'],'origin_cert_install_failed','cPanel did not accept the Origin CA certificate.');
            return self::fail('Your cPanel hosting could not install the Origin CA certificate. Please contact your reseller or hosting support.');
        }
        if (!empty($zone['origin_cert_id']) && $zone['origin_cert_id']!==$certId) { try { $this->service->apiClient()->revokeOriginCertificate((string)$zone['origin_cert_id']); } catch (Throwable) {} }
        $expiry=strtotime((string)($issued['expires_on']??''));
        $this->zones->update((int)$zone['id'],['origin_cert_id'=>$certId,'origin_cert_expires'=>$expiry?date('Y-m-d H:i:s',$expiry):null]);
        $this->zones->log((int)$zone['id'],$actor['type'],$actor['id'],'origin_cert','Cloudflare Origin CA certificate installed on hosting.');
        $message='Origin certificate installed on your hosting.';
        if ($switchToStrict) { $r=$this->service->changeSetting($zone,'ssl','strict',$actor); $message.=$r['ok']?' SSL mode is now Full (strict).':' SSL mode could not be changed: '.$r['message']; }
        else { $message.=' You can now set SSL mode to Full (strict).'; }
        return ['ok'=>true,'message'=>$message];
    }

    /** @param array<string,mixed> $zone @param array{type:string,id:?int} $actor @return array{ok:bool,message:string} */
    private function switchOffDnssec(array $zone,array $actor): array
    {
        try { $remote=$this->service->apiClient()->setDnssec((string)$zone['cf_zone_id'],false); }
        catch (CloudflareApiException $e) { return self::fail($e->getMessage()); }
        $this->zones->update((int)$zone['id'],['dnssec_status'=>mb_substr((string)($remote['status']??'disabled'),0,20),'dnssec_ds'=>null,'dnssec_ds_removed'=>false,'dnssec_disable_after'=>null]);
        $this->zones->log((int)$zone['id'],$actor['type'],$actor['id'],'dnssec_off','DNSSEC turned off at Cloudflare.');
        return ['ok'=>true,'message'=>'DNSSEC has been turned off.'];
    }

    /** @param array<string,mixed> $remote @return array{key_tag:int,algorithm:int,digest_type:int,digest:string,ds:string}|null */
    private static function dsFrom(array $remote): ?array
    {
        $digest=strtoupper(preg_replace('/\s+/','',(string)($remote['digest']??''))??'');
        if (!isset($remote['key_tag']) || !preg_match('/^[A-F0-9]{40,128}$/',$digest)) { return null; }
        return ['key_tag'=>(int)$remote['key_tag'],'algorithm'=>(int)($remote['algorithm']??13),'digest_type'=>(int)($remote['digest_type']??2),'digest'=>$digest,'ds'=>mb_substr((string)($remote['ds']??''),0,300)];
    }

    /** @return array{ok:false,message:string} */
    private static function fail(?string $message): array { return ['ok'=>false,'message'=>(string)($message??'Something went wrong.')]; }
}
