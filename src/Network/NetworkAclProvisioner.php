<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

use Aws\Ec2\Ec2Client;

/**
 * Creates Network ACLs and their inbound/outbound entries, or reuses an existing one by name.
 */
final class NetworkAclProvisioner
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

    public function addEntry(string $networkAclId, array $entry): void
    {
    }
}
