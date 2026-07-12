#!/usr/bin/env php
<?php

declare(strict_types=1);

use AwsProvisioner\Aws\ClientFactory;
use AwsProvisioner\Config\Settings;
use AwsProvisioner\Network\AvailabilityZoneResolver;
use AwsProvisioner\Network\VpcProvisioner;

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

echo "Região: {$settings->region()}\n";

$vpcPreferences = $settings->vpcPreferences();
$subnetsPerTier = $vpcPreferences['subnetsPerTier'] ?? 2;

$zones = (new AvailabilityZoneResolver($ec2))->resolve($subnetsPerTier);
echo 'AZs disponíveis para uso: ' . implode(', ', $zones) . "\n";

$projectName = $settings->projectName();
$vpcName = "vpc-{$projectName}";
$cidrBlock = $vpcPreferences['cidrBlock'] ?? '10.0.0.0/16';

$vpcProvisioner = new VpcProvisioner($ec2);

$vpcId = $vpcProvisioner->create($vpcName, $cidrBlock);
echo "VPC pronta: {$vpcId} ({$vpcName})\n";

$igwName = "{$projectName}-ig";
$igwId = $vpcProvisioner->createAndAttachInternetGateway($vpcId, $igwName);
echo "Internet Gateway pronto: {$igwId} ({$igwName})\n";

echo "\nOK. Rode este script de novo — os IDs acima devem se repetir (nada duplicado).\n";
