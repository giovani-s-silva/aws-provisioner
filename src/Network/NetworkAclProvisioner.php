<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

use Aws\Ec2\Ec2Client;
use Aws\Ec2\Exception\Ec2Exception;

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
        $result = $this->ec2->describeNetworkAcls([
            'Filters' => [
                ['Name' => 'vpc-id', 'Values' => [$vpcId]],
                ['Name' => 'tag:Name', 'Values' => [$name]],
            ],
        ]);

        return $result['NetworkAcls'][0]['NetworkAclId'] ?? null;
    }

    public function create(string $vpcId, string $name): string
    {
        $existingNetworkAclId = $this->findByName($vpcId, $name);
        if ($existingNetworkAclId !== null) {
            return $existingNetworkAclId;
        }

        $result = $this->ec2->createNetworkAcl([
            'VpcId' => $vpcId,
            'TagSpecifications' => [
                [
                    'ResourceType' => 'network-acl',
                    'Tags' => [
                        ['Key' => 'Name', 'Value' => $name],
                    ],
                ],
            ],
        ]);

        return $result['NetworkAcl']['NetworkAclId'];
    }

    /** Safe to call again with the same entry — an already-existing rule number is silently skipped. */
    public function addEntry(string $networkAclId, array $entry): void
    {
        try {
            $this->ec2->createNetworkAclEntry($entry + ['NetworkAclId' => $networkAclId]);
        } catch (Ec2Exception $exception) {
            if ($exception->getAwsErrorCode() !== 'NetworkAclEntryAlreadyExists') {
                throw $exception;
            }
        }
    }
}
