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
    }

    public function recordExists(string $zoneId, string $recordName): bool
    {
    }

    public function upsertCname(string $zoneId, string $recordName, string $recordValue): bool
    {
    }
}
