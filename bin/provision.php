#!/usr/bin/env php
<?php

declare(strict_types=1);

use AwsProvisioner\Aws\ClientFactory;
use AwsProvisioner\Config\Settings;
use AwsProvisioner\Console\ProvisionCommand;
use AwsProvisioner\Network\AvailabilityZoneResolver;
use AwsProvisioner\Network\NetworkAclProvisioner;
use AwsProvisioner\Network\NetworkAclRuleBuilder;
use AwsProvisioner\Network\RouteTableProvisioner;
use AwsProvisioner\Network\SecurityGroupProvisioner;
use AwsProvisioner\Network\SecurityGroupRuleResolver;
use AwsProvisioner\Network\SubnetProvisioner;
use AwsProvisioner\Network\VpcProvisioner;
use AwsProvisioner\Provisioning\Orchestrator;
use AwsProvisioner\Provisioning\ProvisioningContext;
use AwsProvisioner\Support\CidrAllocator;
use AwsProvisioner\Support\PublicIp;
use Symfony\Component\Console\Application;

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

$vpcProvisioner = new VpcProvisioner($ec2);
$securityGroupProvisioner = new SecurityGroupProvisioner($ec2);
$networkAclProvisioner = new NetworkAclProvisioner($ec2);
$subnetProvisioner = new SubnetProvisioner($ec2);
$routeTableProvisioner = new RouteTableProvisioner($ec2);

$projectName = $settings->projectName();
$vpcName = "vpc-{$projectName}";
$igwName = "{$projectName}-ig";

$context = new ProvisioningContext();
$orchestrator = new Orchestrator();

$orchestrator->addStep('vpc', function () use ($context, $vpcProvisioner, $vpcName, $settings) {
    $context->vpcId = $vpcProvisioner->create($vpcName, $settings->vpcPreferences()['cidrBlock']);
});

$orchestrator->addStep('internet-gateway', function () use ($context, $vpcProvisioner, $vpcName, $igwName) {
    $context->vpcId ??= $vpcProvisioner->findByName($vpcName);
    $context->internetGatewayId = $vpcProvisioner->createAndAttachInternetGateway($context->vpcId, $igwName);
});

$orchestrator->addStep('security-groups', function () use (
    $context,
    $vpcProvisioner,
    $vpcName,
    $securityGroupProvisioner,
    $settings,
) {
    $context->vpcId ??= $vpcProvisioner->findByName($vpcName);
    $myIp = PublicIp::resolve($settings->caBundlePath());

    foreach (['web', 'db'] as $tier) {
        $config = $settings->securityGroupPreferences()[$tier] ?? null;
        if ($config === null) {
            continue;
        }

        $groupId = $securityGroupProvisioner->create($context->vpcId, $config['name'], $config['description']);
        $context->securityGroupIds[$tier] = $groupId;

        $permissions = SecurityGroupRuleResolver::resolve($config['ingress'], $context->securityGroupIds, $myIp);
        $securityGroupProvisioner->authorizeIngress($groupId, $permissions);
    }
});

$orchestrator->addStep('network-acls', function () use (
    $context,
    $vpcProvisioner,
    $vpcName,
    $networkAclProvisioner,
    $settings,
) {
    $context->vpcId ??= $vpcProvisioner->findByName($vpcName);

    foreach (['web', 'db'] as $tier) {
        $config = $settings->networkAclPreferences()[$tier] ?? null;
        if ($config === null) {
            continue;
        }

        $networkAclId = $networkAclProvisioner->create($context->vpcId, $config['name']);
        $context->networkAclIds[$tier] = $networkAclId;

        foreach (NetworkAclRuleBuilder::build($config['servicePorts'], $config['outboundPorts']) as $entry) {
            $networkAclProvisioner->addEntry($networkAclId, $entry);
        }
    }
});

$orchestrator->addStep('subnets', function () use (
    $context,
    $vpcProvisioner,
    $vpcName,
    $networkAclProvisioner,
    $subnetProvisioner,
    $ec2,
    $settings,
) {
    $context->vpcId ??= $vpcProvisioner->findByName($vpcName);

    $vpcPreferences = $settings->vpcPreferences();
    $subnetsPerTier = $vpcPreferences['subnetsPerTier'] ?? 2;
    $subnetMaskBits = $vpcPreferences['subnetMaskBits'] ?? 24;
    $zones = (new AvailabilityZoneResolver($ec2))->resolve($subnetsPerTier);

    $tierOffset = 0;

    foreach (['web', 'db'] as $tier) {
        $tierConfig = $vpcPreferences['tiers'][$tier] ?? null;
        $naclConfig = $settings->networkAclPreferences()[$tier] ?? null;
        if ($tierConfig === null || $naclConfig === null) {
            continue;
        }

        $networkAclId = $context->networkAclIds[$tier]
            ?? $networkAclProvisioner->findByName($context->vpcId, $naclConfig['name']);
        if ($networkAclId === null) {
            throw new \RuntimeException(
                "Network ACL '{$naclConfig['name']}' not found. Run the network-acls step first."
            );
        }
        $context->networkAclIds[$tier] = $networkAclId;

        $cidrBlocks = CidrAllocator::allocate(
            $vpcPreferences['cidrBlock'],
            $subnetsPerTier,
            $subnetMaskBits,
            $tierOffset,
        );
        $tierOffset += $subnetsPerTier;

        $subnetIds = [];
        foreach ($zones as $index => $availabilityZone) {
            $subnetName = "sub-{$settings->projectName()}-{$tier}-" . ($index + 1);
            $subnetIds[] = $subnetProvisioner->create(
                $context->vpcId,
                $cidrBlocks[$index],
                $availabilityZone,
                $tierConfig['mapPublicIpOnLaunch'],
                $networkAclId,
                $subnetName,
            );
        }
        $context->subnetIds[$tier] = $subnetIds;
    }
});

$orchestrator->addStep('route-tables', function () use (
    $context,
    $vpcProvisioner,
    $vpcName,
    $igwName,
    $subnetProvisioner,
    $routeTableProvisioner,
    $settings,
) {
    $context->vpcId ??= $vpcProvisioner->findByName($vpcName);
    $context->internetGatewayId ??= $vpcProvisioner->createAndAttachInternetGateway($context->vpcId, $igwName);

    $vpcPreferences = $settings->vpcPreferences();
    $subnetsPerTier = $vpcPreferences['subnetsPerTier'] ?? 2;

    foreach (['web', 'db'] as $tier) {
        $tierConfig = $vpcPreferences['tiers'][$tier] ?? null;
        $routeTableConfig = $settings->routeTablePreferences()[$tier] ?? null;
        if ($tierConfig === null || $routeTableConfig === null) {
            continue;
        }

        $subnetIds = $context->subnetIds[$tier] ?? [];
        if ($subnetIds === []) {
            // This step may run on its own, in a separate invocation from 'subnets' — look up
            // subnets already created earlier instead of assuming this run made them.
            for ($index = 0; $index < $subnetsPerTier; $index++) {
                $subnetName = "sub-{$settings->projectName()}-{$tier}-" . ($index + 1);
                $subnetId = $subnetProvisioner->findByName($context->vpcId, $subnetName);
                if ($subnetId !== null) {
                    $subnetIds[] = $subnetId;
                }
            }
        }

        if ($subnetIds === []) {
            throw new \RuntimeException("No subnets found for tier '{$tier}'. Run the subnets step first.");
        }
        $context->subnetIds[$tier] = $subnetIds;

        $routeTableId = $routeTableProvisioner->create($context->vpcId, $routeTableConfig['name']);
        $context->routeTableIds[$tier] = $routeTableId;

        if ($tierConfig['mapPublicIpOnLaunch'] === true) {
            $routeTableProvisioner->addInternetGatewayRoute($routeTableId, $context->internetGatewayId);
        }

        foreach ($subnetIds as $subnetId) {
            $routeTableProvisioner->associateSubnet($routeTableId, $subnetId);
        }
    }
});

$application = new Application('AWS Provisioner', 'dev');
$application->add(new ProvisionCommand($orchestrator));
$application->setDefaultCommand('provision', true);
$application->run();
