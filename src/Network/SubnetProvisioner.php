<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

use Aws\Ec2\Ec2Client;

/**
 * Creates subnets across the configured Availability Zones and associates each one
 * with the correct Network ACL. Reuses existing subnets by name instead of duplicating them.
 */
final class SubnetProvisioner
{
    public function __construct(
        private readonly Ec2Client $ec2,
    ) {
    }

    public function findByName(string $vpcId, string $name): ?string
    {
    }

    public function create(
        string $vpcId,
        string $cidrBlock,
        string $availabilityZone,
        bool $mapPublicIpOnLaunch,
        string $networkAclId,
        string $name,
    ): string {
    }

    public function replaceNetworkAclAssociation(string $subnetId, string $networkAclId): void
    {
    }
}
