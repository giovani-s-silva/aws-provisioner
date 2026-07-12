<?php

declare(strict_types=1);

namespace AwsProvisioner\Certificates;

/**
 * Common contract so CertificateProvisioner can validate a domain through Route 53
 * or Cloudflare without knowing which one it is talking to.
 */
interface DnsProviderInterface
{
    public function resolveZoneId(string $domain): ?string;

    public function recordExists(string $zoneId, string $recordName): bool;

    public function upsertCname(string $zoneId, string $recordName, string $recordValue): bool;
}
