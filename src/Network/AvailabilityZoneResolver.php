<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

use Aws\Ec2\Ec2Client;

/**
 * Asks AWS which Availability Zones actually exist in the configured region and validates
 * the requested subnet count against that number — regions differ (some have 3 AZs, others 5+),
 * so this must never be a hardcoded list.
 */
final class AvailabilityZoneResolver
{
    public function __construct(
        private readonly Ec2Client $ec2,
    ) {
    }

    /**
     * @return string[] up to $count zone names, e.g. ['sa-east-1a', 'sa-east-1b']
     * @throws \InvalidArgumentException when $count exceeds the AZs available in this region
     */
    public function resolve(int $count): array
    {
        $result = $this->ec2->describeAvailabilityZones([
            'Filters' => [
                ['Name' => 'state', 'Values' => ['available']],
            ],
        ]);

        $zoneNames = array_column($result['AvailabilityZones'], 'ZoneName');
        sort($zoneNames);

        if ($count > count($zoneNames)) {
            throw new \InvalidArgumentException(sprintf(
                'Requested %d availability zone(s), but this region only has %d available: %s',
                $count,
                count($zoneNames),
                implode(', ', $zoneNames),
            ));
        }

        return array_slice($zoneNames, 0, $count);
    }
}
