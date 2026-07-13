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
        $result = $this->ec2->describeSubnets([
            'Filters' => [
                ['Name' => 'vpc-id', 'Values' => [$vpcId]],
                ['Name' => 'tag:Name', 'Values' => [$name]],
            ],
        ]);

        return $result['Subnets'][0]['SubnetId'] ?? null;
    }

    public function create(
        string $vpcId,
        string $cidrBlock,
        string $availabilityZone,
        bool $mapPublicIpOnLaunch,
        string $networkAclId,
        string $name,
    ): string {
        $existingSubnetId = $this->findByName($vpcId, $name);
        if ($existingSubnetId !== null) {
            return $existingSubnetId;
        }

        $result = $this->ec2->createSubnet([
            'VpcId' => $vpcId,
            'CidrBlock' => $cidrBlock,
            'AvailabilityZone' => $availabilityZone,
            'TagSpecifications' => [
                [
                    'ResourceType' => 'subnet',
                    'Tags' => [
                        ['Key' => 'Name', 'Value' => $name],
                    ],
                ],
            ],
        ]);

        $subnetId = $result['Subnet']['SubnetId'];

        // MapPublicIpOnLaunch and the Network ACL are NOT CreateSubnet parameters — every new
        // subnet starts attached to the VPC's default ACL and must be reassigned afterwards.
        $this->ec2->modifySubnetAttribute([
            'SubnetId' => $subnetId,
            'MapPublicIpOnLaunch' => ['Value' => $mapPublicIpOnLaunch],
        ]);

        $this->replaceNetworkAclAssociation($subnetId, $networkAclId);

        return $subnetId;
    }

    /** Safe to call again with the same target ACL — replacing an association with itself is a no-op. */
    public function replaceNetworkAclAssociation(string $subnetId, string $networkAclId): void
    {
        $result = $this->ec2->describeNetworkAcls([
            'Filters' => [
                ['Name' => 'association.subnet-id', 'Values' => [$subnetId]],
            ],
        ]);

        $associationId = null;
        foreach ($result['NetworkAcls'][0]['Associations'] ?? [] as $association) {
            if ($association['SubnetId'] === $subnetId) {
                $associationId = $association['NetworkAclAssociationId'];
                break;
            }
        }

        if ($associationId === null) {
            return;
        }

        $this->ec2->replaceNetworkAclAssociation([
            'AssociationId' => $associationId,
            'NetworkAclId' => $networkAclId,
        ]);
    }
}
