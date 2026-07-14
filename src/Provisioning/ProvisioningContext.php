<?php

declare(strict_types=1);

namespace AwsProvisioner\Provisioning;

/**
 * Shared, typed bag of resource IDs that one step creates and a later step needs
 * (e.g. the subnets step needs the VPC ID and the Network ACL IDs from earlier steps).
 */
final class ProvisioningContext
{
    public ?string $vpcId = null;

    public ?string $internetGatewayId = null;

    /** @var array<string, string> tier name => security group ID */
    public array $securityGroupIds = [];

    /** @var array<string, string> tier name => network ACL ID */
    public array $networkAclIds = [];

    /** @var array<string, string[]> tier name => subnet IDs */
    public array $subnetIds = [];

    /** @var array<string, string> tier name => route table ID */
    public array $routeTableIds = [];
}
