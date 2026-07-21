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
        $body = $this->request('GET', 'zones', ['query' => ['name' => rtrim($domain, '.')]]);

        return $body['result'][0]['id'] ?? null;
    }

    public function recordExists(string $zoneId, string $recordName): bool
    {
        return $this->findRecord($zoneId, $recordName) !== null;
    }

    /** Safe to call again — creates the record if missing, updates it in place if the value changed. */
    public function upsertCname(string $zoneId, string $recordName, string $recordValue): bool
    {
        $existing = $this->findRecord($zoneId, $recordName);

        $payload = [
            'type' => 'CNAME',
            'name' => rtrim($recordName, '.'),
            'content' => rtrim($recordValue, '.'),
            'ttl' => 300,
            // Must stay DNS-only -- a proxied record hides the real CNAME target behind
            // Cloudflare's own edge IPs, and ACM's validator can never see what it needs to.
            'proxied' => false,
        ];

        $body = $existing !== null
            ? $this->request('PUT', "zones/{$zoneId}/dns_records/{$existing['id']}", ['json' => $payload])
            : $this->request('POST', "zones/{$zoneId}/dns_records", ['json' => $payload]);

        return $body['success'] ?? false;
    }

    /** @return array<string, mixed>|null */
    private function findRecord(string $zoneId, string $recordName): ?array
    {
        $body = $this->request('GET', "zones/{$zoneId}/dns_records", [
            'query' => ['type' => 'CNAME', 'name' => rtrim($recordName, '.')],
        ]);

        return $body['result'][0] ?? null;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function request(string $method, string $uri, array $options): array
    {
        $response = $this->http->request($method, $uri, $options);

        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
