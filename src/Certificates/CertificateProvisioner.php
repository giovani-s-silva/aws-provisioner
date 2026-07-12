<?php

declare(strict_types=1);

namespace AwsProvisioner\Certificates;

use Aws\Acm\AcmClient;

/**
 * Requests ACM certificates and drives DNS validation through whichever DnsProviderInterface
 * the domain is configured for (Route 53 or Cloudflare), per config/settings.php.
 */
final class CertificateProvisioner
{
    public function __construct(
        private readonly AcmClient $acm,
    ) {
    }

    public function findIssuedByDomain(string $domainName): ?string
    {
    }

    public function request(array $domains): string
    {
    }

    public function getValidationOptions(string $certificateArn): array
    {
    }

    public function validateThrough(DnsProviderInterface $dnsProvider, array $validationOptions): void
    {
    }
}
