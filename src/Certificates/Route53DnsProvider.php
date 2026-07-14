<?php

declare(strict_types=1);

namespace AwsProvisioner\Certificates;

use Aws\Route53\Route53Client;

final class Route53DnsProvider implements DnsProviderInterface
{
    public function __construct(
        private readonly Route53Client $route53,
    ) {
    }

    public function resolveZoneId(string $domain): ?string
    {
        $normalizedName = rtrim($domain, '.') . '.';

        $result = $this->route53->listHostedZonesByName(['DNSName' => $normalizedName]);

        foreach ($result['HostedZones'] as $zone) {
            if ($zone['Name'] === $normalizedName) {
                return $zone['Id'];
            }
        }

        return null;
    }

    public function recordExists(string $zoneId, string $recordName): bool
    {
        $normalizedName = rtrim($recordName, '.') . '.';

        $result = $this->route53->listResourceRecordSets([
            'HostedZoneId' => $zoneId,
            'StartRecordName' => $normalizedName,
            'StartRecordType' => 'CNAME',
            'MaxItems' => 1,
        ]);

        foreach ($result['ResourceRecordSets'] as $recordSet) {
            if ($recordSet['Name'] === $normalizedName && $recordSet['Type'] === 'CNAME') {
                return true;
            }
        }

        return false;
    }

    /** Safe to call again — UPSERT creates the record if missing or leaves it as is if unchanged. */
    public function upsertCname(string $zoneId, string $recordName, string $recordValue): bool
    {
        $result = $this->route53->changeResourceRecordSets([
            'HostedZoneId' => $zoneId,
            'ChangeBatch' => [
                'Changes' => [
                    [
                        'Action' => 'UPSERT',
                        'ResourceRecordSet' => [
                            'Name' => $recordName,
                            'Type' => 'CNAME',
                            'TTL' => 300,
                            'ResourceRecords' => [
                                ['Value' => $recordValue],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return ($result['ChangeInfo']['Status'] ?? null) !== null;
    }
}
