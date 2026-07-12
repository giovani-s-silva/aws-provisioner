<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

use Aws\Ec2\Ec2Client;

/**
 * Creates route tables and associates them with subnets, or reuses an existing table by name.
 */
final class RouteTableProvisioner
{
    public function __construct(
        private readonly Ec2Client $ec2,
    ) {
    }

    public function findByName(string $vpcId, string $name): ?string
    {
    }

    public function create(string $vpcId, string $name): string
    {
    }

    public function associateSubnet(string $routeTableId, string $subnetId): void
    {
    }
}
