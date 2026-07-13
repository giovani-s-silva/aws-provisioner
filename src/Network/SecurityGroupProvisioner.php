<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

use Aws\Ec2\Ec2Client;
use Aws\Ec2\Exception\Ec2Exception;

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
        $result = $this->ec2->describeSecurityGroups([
            'Filters' => [
                ['Name' => 'group-name', 'Values' => [$name]],
            ],
        ]);

        return $result['SecurityGroups'][0]['GroupId'] ?? null;
    }

    public function create(string $vpcId, string $name, string $description): string
    {
        $existingGroupId = $this->findByName($name);
        if ($existingGroupId !== null) {
            return $existingGroupId;
        }

        $result = $this->ec2->createSecurityGroup([
            'GroupName' => $name,
            'Description' => $description,
            'VpcId' => $vpcId,
            'TagSpecifications' => [
                [
                    'ResourceType' => 'security-group',
                    'Tags' => [
                        ['Key' => 'Name', 'Value' => $name],
                    ],
                ],
            ],
        ]);

        return $result['GroupId'];
    }

    /** Safe to call again with the same rules — an already-authorized rule is silently skipped. */
    public function authorizeIngress(string $groupId, array $ipPermissions): void
    {
        try {
            $this->ec2->authorizeSecurityGroupIngress([
                'GroupId' => $groupId,
                'IpPermissions' => $ipPermissions,
            ]);
        } catch (Ec2Exception $exception) {
            if ($exception->getAwsErrorCode() !== 'InvalidPermission.Duplicate') {
                throw $exception;
            }
        }
    }
}
