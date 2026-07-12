<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

use Aws\Ec2\Ec2Client;

/**
 * Requests, accepts and routes a VPC peering connection between two AWS accounts.
 * Kept separate from the main provisioning flow — it is an opt-in step, not part of --all.
 */
final class VpcPeeringProvisioner
{
    public function __construct(
        private readonly Ec2Client $requesterEc2,
    ) {
    }

    public function requestConnection(string $vpcId, string $peerVpcId, string $peerOwnerId, string $peerRegion): string
    {
    }

    public function accept(Ec2Client $accepterEc2, string $peeringConnectionId): void
    {
    }

    public function addRoute(string $routeTableId, string $destinationCidrBlock, string $peeringConnectionId): void
    {
    }
}
