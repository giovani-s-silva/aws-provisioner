#!/usr/bin/env php
<?php

declare(strict_types=1);

use AwsProvisioner\Aws\ClientFactory;
use AwsProvisioner\Config\Settings;
use AwsProvisioner\Network\NetworkAclProvisioner;
use AwsProvisioner\Network\SecurityGroupProvisioner;
use AwsProvisioner\Network\VpcProvisioner;
use AwsProvisioner\Support\PublicIp;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array<int, array<string, mixed>> */
function resolveIngressPermissions(array $ingressRules, array $groupIdsByTier, string $myIp): array
{
    $permissions = [];

    foreach ($ingressRules as $rule) {
        $permission = [
            'IpProtocol' => $rule['protocol'],
            'FromPort' => $rule['port'],
            'ToPort' => $rule['port'],
        ];

        $source = $rule['source'];
        if ($source === 'my-ip') {
            $permission['IpRanges'] = [['CidrIp' => "{$myIp}/32"]];
        } elseif (str_starts_with($source, 'security-group:')) {
            $tier = substr($source, strlen('security-group:'));
            $permission['UserIdGroupPairs'] = [['GroupId' => $groupIdsByTier[$tier]]];
        } else {
            $permission['IpRanges'] = [['CidrIp' => $source]];
        }

        $permissions[] = $permission;
    }

    return $permissions;
}

/** @return array<int, array<string, mixed>> */
function buildNetworkAclEntries(array $servicePorts, array $outboundPorts): array
{
    $entries = [];
    $ruleNumber = 100;

    foreach ($servicePorts as $port) {
        $entries[] = [
            'RuleNumber' => $ruleNumber,
            'Protocol' => '6',
            'RuleAction' => 'allow',
            'Egress' => false,
            'CidrBlock' => '0.0.0.0/0',
            'PortRange' => ['From' => $port, 'To' => $port],
        ];
        $ruleNumber += 10;
    }

    if ($servicePorts !== []) {
        // Return traffic for connections this tier initiates outbound.
        $entries[] = [
            'RuleNumber' => $ruleNumber,
            'Protocol' => '6',
            'RuleAction' => 'allow',
            'Egress' => true,
            'CidrBlock' => '0.0.0.0/0',
            'PortRange' => ['From' => 1024, 'To' => 65535],
        ];
        $ruleNumber += 10;
    }

    foreach ($outboundPorts as $port) {
        $entries[] = [
            'RuleNumber' => $ruleNumber,
            'Protocol' => '6',
            'RuleAction' => 'allow',
            'Egress' => true,
            'CidrBlock' => '0.0.0.0/0',
            'PortRange' => ['From' => $port, 'To' => $port],
        ];
        $ruleNumber += 10;
    }

    if ($outboundPorts !== []) {
        // Return traffic for connections other tiers send to this one.
        $entries[] = [
            'RuleNumber' => $ruleNumber,
            'Protocol' => '6',
            'RuleAction' => 'allow',
            'Egress' => false,
            'CidrBlock' => '0.0.0.0/0',
            'PortRange' => ['From' => 1024, 'To' => 65535],
        ];
        $ruleNumber += 10;
    }

    return $entries;
}

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

$vpcId = (new VpcProvisioner($ec2))->findByName($vpcName);
if ($vpcId === null) {
    fwrite(STDERR, "VPC '{$vpcName}' not found. Run bin/verify-vpc.php first.\n");
    exit(1);
}
echo "Using existing VPC: {$vpcId} ({$vpcName})\n";

$myIp = PublicIp::resolve($settings->caBundlePath());
echo "Your public IP: {$myIp}\n\n";

$securityGroupProvisioner = new SecurityGroupProvisioner($ec2);
$groupIdsByTier = [];

// Tiers are processed in the order declared under network.tiers in config/settings.php —
// 'web' must come before 'db' there, since the db rule references the web security group.
foreach ($settings->tierNames() as $tier) {
    $config = $settings->securityGroupPreferences()[$tier] ?? null;
    if ($config === null) {
        continue;
    }

    $groupId = $securityGroupProvisioner->create($vpcId, $config['name'], $config['description']);
    $groupIdsByTier[$tier] = $groupId;

    $permissions = resolveIngressPermissions($config['ingress'], $groupIdsByTier, $myIp);
    $securityGroupProvisioner->authorizeIngress($groupId, $permissions);

    echo "Security group '{$tier}' ready: {$groupId} ({$config['name']})\n";
}

echo "\n";

$networkAclProvisioner = new NetworkAclProvisioner($ec2);

foreach ($settings->tierNames() as $tier) {
    $config = $settings->networkAclPreferences()[$tier] ?? null;
    if ($config === null) {
        continue;
    }

    $networkAclId = $networkAclProvisioner->create($vpcId, $config['name']);

    $entries = buildNetworkAclEntries($config['servicePorts'], $config['outboundPorts']);
    foreach ($entries as $entry) {
        $networkAclProvisioner->addEntry($networkAclId, $entry);
    }

    echo "Network ACL '{$tier}' ready: {$networkAclId} ({$config['name']}), " . count($entries) . " rule(s)\n";
}

echo "\nOK. Run this script again — nothing should be duplicated (idempotency).\n";
