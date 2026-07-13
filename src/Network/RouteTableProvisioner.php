<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

use Aws\Ec2\Ec2Client;
use Aws\Ec2\Exception\Ec2Exception;

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
        $result = $this->ec2->describeRouteTables([
            'Filters' => [
                ['Name' => 'vpc-id', 'Values' => [$vpcId]],
                ['Name' => 'tag:Name', 'Values' => [$name]],
            ],
        ]);

        return $result['RouteTables'][0]['RouteTableId'] ?? null;
    }

    public function create(string $vpcId, string $name): string
    {
        $existingRouteTableId = $this->findByName($vpcId, $name);
        if ($existingRouteTableId !== null) {
            return $existingRouteTableId;
        }

        $result = $this->ec2->createRouteTable([
            'VpcId' => $vpcId,
            'TagSpecifications' => [
                [
                    'ResourceType' => 'route-table',
                    'Tags' => [
                        ['Key' => 'Name', 'Value' => $name],
                    ],
                ],
            ],
        ]);

        return $result['RouteTable']['RouteTableId'];
    }

    /** Safe to call again — a subnet already associated with this table is silently skipped. */
    public function associateSubnet(string $routeTableId, string $subnetId): void
    {
        try {
            $this->ec2->associateRouteTable([
                'RouteTableId' => $routeTableId,
                'SubnetId' => $subnetId,
            ]);
        } catch (Ec2Exception $exception) {
            if ($exception->getAwsErrorCode() !== 'Resource.AlreadyAssociated') {
                throw $exception;
            }
        }
    }

    /** Safe to call again — an existing 0.0.0.0/0 route to this gateway is left as is. */
    public function addInternetGatewayRoute(string $routeTableId, string $internetGatewayId): void
    {
        try {
            $this->ec2->createRoute([
                'RouteTableId' => $routeTableId,
                'DestinationCidrBlock' => '0.0.0.0/0',
                'GatewayId' => $internetGatewayId,
            ]);
        } catch (Ec2Exception $exception) {
            if ($exception->getAwsErrorCode() !== 'RouteAlreadyExists') {
                throw $exception;
            }
        }
    }
}
