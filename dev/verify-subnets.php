#!/usr/bin/env php
<?php

declare(strict_types=1);

use AwsProvisioner\Aws\ClientFactory;
use AwsProvisioner\Config\Settings;
use AwsProvisioner\Network\AvailabilityZoneResolver;
use AwsProvisioner\Network\NetworkAclProvisioner;
use AwsProvisioner\Network\RouteTableProvisioner;
use AwsProvisioner\Network\SubnetProvisioner;
use AwsProvisioner\Network\VpcProvisioner;
use AwsProvisioner\Support\CidrAllocator;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Settings::load(
    dirname(__DIR__) . '/.env',
    dirname(__DIR__) . '/config/settings.php',
);

$clientFactory = new ClientFactory(
    $settings->awsCredentials(),
    $settings->region(),
    $settings->caBundlePath(),
);

$ec2 = $clientFactory->ec2();

$projectName = $settings->projectName();
$vpcName = "vpc-{$projectName}";

$vpcProvisioner = new VpcProvisioner($ec2);
$vpcId = $vpcProvisioner->findByName($vpcName);
if ($vpcId === null) {
    fwrite(STDERR, "VPC '{$vpcName}' not found. Run bin/verify-vpc.php first.\n");
    exit(1);
}
echo "Using existing VPC: {$vpcId} ({$vpcName})\n";

$igwName = "{$projectName}-ig";
$internetGatewayId = $vpcProvisioner->createAndAttachInternetGateway($vpcId, $igwName);
echo "Internet Gateway: {$internetGatewayId} ({$igwName})\n\n";

$vpcPreferences = $settings->vpcPreferences();
$subnetsPerTier = $vpcPreferences['subnetsPerTier'] ?? 2;

$zones = (new AvailabilityZoneResolver($ec2))->resolve($subnetsPerTier);
echo 'Availability Zones in use: ' . implode(', ', $zones) . "\n\n";

$networkAclProvisioner = new NetworkAclProvisioner($ec2);
$subnetProvisioner = new SubnetProvisioner($ec2);
$routeTableProvisioner = new RouteTableProvisioner($ec2);

$subnetMaskBits = $vpcPreferences['subnetMaskBits'] ?? 24;
$tierOffset = 0;

foreach ($settings->tierNames() as $tier) {
    $tierConfig = $vpcPreferences['tiers'][$tier] ?? null;
    $naclConfig = $settings->networkAclPreferences()[$tier] ?? null;
    $routeTableConfig = $settings->routeTablePreferences()[$tier] ?? null;

    if ($tierConfig === null || $naclConfig === null || $routeTableConfig === null) {
        continue;
    }

    $networkAclId = $networkAclProvisioner->findByName($vpcId, $naclConfig['name']);
    if ($networkAclId === null) {
        fwrite(STDERR, "Network ACL '{$naclConfig['name']}' not found. Run bin/verify-network.php first.\n");
        exit(1);
    }

    $cidrBlocks = CidrAllocator::allocate($vpcPreferences['cidrBlock'], $subnetsPerTier, $subnetMaskBits, $tierOffset);
    $tierOffset += $subnetsPerTier;

    $subnetIds = [];
    foreach ($zones as $index => $availabilityZone) {
        $cidrBlock = $cidrBlocks[$index];
        $subnetName = "sub-{$projectName}-{$tier}-" . ($index + 1);

        $subnetId = $subnetProvisioner->create(
            $vpcId,
            $cidrBlock,
            $availabilityZone,
            $tierConfig['mapPublicIpOnLaunch'],
            $networkAclId,
            $subnetName,
        );

        $subnetIds[] = $subnetId;
        echo "Subnet '{$tier}' #" . ($index + 1) . ": {$subnetId} ({$subnetName}) in {$availabilityZone}\n";
    }

    $routeTableId = $routeTableProvisioner->create($vpcId, $routeTableConfig['name']);

    if ($tierConfig['mapPublicIpOnLaunch'] === true) {
        $routeTableProvisioner->addInternetGatewayRoute($routeTableId, $internetGatewayId);
    }

    foreach ($subnetIds as $subnetId) {
        $routeTableProvisioner->associateSubnet($routeTableId, $subnetId);
    }

    echo "Route table '{$tier}' ready: {$routeTableId} ({$routeTableConfig['name']}), "
        . count($subnetIds) . " subnet(s) associated\n\n";
}

echo "OK. Run this script again — nothing should be duplicated (idempotency).\n";
