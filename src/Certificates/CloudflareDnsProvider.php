<?php

declare(strict_types=1);

namespace AwsProvisioner\Certificates;

use GuzzleHttp\ClientInterface;

final class CloudflareDnsProvider implements DnsProviderInterface
{
    public function __construct(
        private readonly ClientInterface $http,
    ) {
    }

    public function resolveZoneId(string $domain): ?string
    {
    }

    public function recordExists(string $zoneId, string $recordName): bool
    {
    }

    public function upsertCname(string $zoneId, string $recordName, string $recordValue): bool
    {
    }
}
