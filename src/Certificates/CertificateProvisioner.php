<?php

declare(strict_types=1);

namespace AwsProvisioner\Certificates;

use Aws\Acm\AcmClient;

/**
 * Requests ACM certificates and reports their DNS validation records. Driving those records
 * into a specific DNS provider (Route 53 today) is orchestrated by whoever calls this class.
 */
final class CertificateProvisioner
{
    public function __construct(
        private readonly AcmClient $acm,
    ) {
    }

    /**
     * Matches on any status on purpose (not just ISSUED) — a certificate still
     * PENDING_VALIDATION from an earlier run must be reused, not requested again.
     */
    public function findByDomain(string $domainName): ?string
    {
        $paginator = $this->acm->getPaginator('ListCertificates');

        foreach ($paginator as $page) {
            foreach ($page['CertificateSummaryList'] as $certificate) {
                if ($certificate['DomainName'] === $domainName) {
                    return $certificate['CertificateArn'];
                }
            }
        }

        return null;
    }

    /** @param string[] $domains */
    public function request(array $domains): string
    {
        $existingArn = $this->findByDomain($domains[0]);
        if ($existingArn !== null) {
            return $existingArn;
        }

        $result = $this->acm->requestCertificate([
            'DomainName' => $domains[0],
            'SubjectAlternativeNames' => array_slice($domains, 1),
            'ValidationMethod' => 'DNS',
        ]);

        return $result['CertificateArn'];
    }

    public function status(string $certificateArn): string
    {
        $result = $this->acm->describeCertificate(['CertificateArn' => $certificateArn]);

        return $result['Certificate']['Status'];
    }

    /**
     * @return array<string, array{cnameName: string, cnameValue: string}> keyed by domain name
     *
     * Empty right after requesting a certificate — AWS needs a moment to generate the
     * validation record details, so this may need to be checked again on a later run.
     */
    public function validationRecords(string $certificateArn): array
    {
        $result = $this->acm->describeCertificate(['CertificateArn' => $certificateArn]);

        $records = [];
        foreach ($result['Certificate']['DomainValidationOptions'] as $option) {
            if (!isset($option['ResourceRecord'])) {
                continue;
            }

            $records[$option['DomainName']] = [
                'cnameName' => $option['ResourceRecord']['Name'],
                'cnameValue' => $option['ResourceRecord']['Value'],
            ];
        }

        return $records;
    }
}
