<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

use Aws\Ec2\Ec2Client;

/**
 * Creates security groups and their ingress rules, or reuses an existing group by name.
 * Rules (ports, source IP/SG) come from config/settings.php, not hardcoded here.
 */
final class SecurityGroupProvisioner
{
    public function __construct(
        private readonly Ec2Client $ec2,
    ) {
    }

    public function findByName(string $name): ?string
    {
    }

    public function create(string $vpcId, string $name, string $description): string
    {
    }

    public function authorizeIngress(string $groupId, array $ipPermissions): void
    {
    }
}
