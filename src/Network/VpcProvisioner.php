<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

use Aws\Ec2\Ec2Client;

/**
 * Creates the VPC and its Internet Gateway, or reuses them by "Name" tag if they already exist.
 * Idempotent on purpose: re-running after a partial failure must not create duplicates.
 */
final class VpcProvisioner
{
    public function __construct(
        private readonly Ec2Client $ec2,
    ) {
    }

    public function findByName(string $name): ?string
    {
        $result = $this->ec2->describeVpcs([
            'Filters' => [
                ['Name' => 'tag:Name', 'Values' => [$name]],
            ],
        ]);

        return $result['Vpcs'][0]['VpcId'] ?? null;
    }

    public function create(string $name, string $cidrBlock): string
    {
        $existingVpcId = $this->findByName($name);
        if ($existingVpcId !== null) {
            return $existingVpcId;
        }

        $result = $this->ec2->createVpc([
            'CidrBlock' => $cidrBlock,
            'InstanceTenancy' => 'default',
            'TagSpecifications' => [
                [
                    'ResourceType' => 'vpc',
                    'Tags' => [
                        ['Key' => 'Name', 'Value' => $name],
                    ],
                ],
            ],
        ]);

        $vpcId = $result['Vpc']['VpcId'];

        // DNS support/hostnames are NOT valid CreateVpc parameters — despite older examples
        // suggesting otherwise, the API rejects them there. They must be set afterwards
        // through ModifyVpcAttribute, one attribute per call.
        $this->ec2->modifyVpcAttribute([
            'VpcId' => $vpcId,
            'EnableDnsSupport' => ['Value' => true],
        ]);
        $this->ec2->modifyVpcAttribute([
            'VpcId' => $vpcId,
            'EnableDnsHostnames' => ['Value' => true],
        ]);

        return $vpcId;
    }

    public function createAndAttachInternetGateway(string $vpcId, string $name): string
    {
        $existing = $this->ec2->describeInternetGateways([
            'Filters' => [
                ['Name' => 'tag:Name', 'Values' => [$name]],
                ['Name' => 'attachment.vpc-id', 'Values' => [$vpcId]],
            ],
        ]);

        if (!empty($existing['InternetGateways'])) {
            return $existing['InternetGateways'][0]['InternetGatewayId'];
        }

        $result = $this->ec2->createInternetGateway([
            'TagSpecifications' => [
                [
                    'ResourceType' => 'internet-gateway',
                    'Tags' => [
                        ['Key' => 'Name', 'Value' => $name],
                    ],
                ],
            ],
        ]);

        $internetGatewayId = $result['InternetGateway']['InternetGatewayId'];

        $this->ec2->attachInternetGateway([
            'InternetGatewayId' => $internetGatewayId,
            'VpcId' => $vpcId,
        ]);

        return $internetGatewayId;
    }
}
