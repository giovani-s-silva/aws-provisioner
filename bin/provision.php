#!/usr/bin/env php
<?php

declare(strict_types=1);

use AwsProvisioner\Aws\ClientFactory;
use AwsProvisioner\Certificates\CertificateProvisioner;
use AwsProvisioner\Certificates\Route53DnsProvider;
use AwsProvisioner\Config\Settings;
use AwsProvisioner\Console\ProvisionCommand;
use AwsProvisioner\LoadBalancer\LoadBalancerProvisioner;
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

    foreach ($settings->tierNames() as $tier) {
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

    foreach ($settings->tierNames() as $tier) {
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

    foreach ($settings->tierNames() as $tier) {
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

    foreach ($settings->tierNames() as $tier) {
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

$acmDomains = $settings->acmDomainList();
$certificateProvisioner = new CertificateProvisioner($clientFactory->acm());

if ($acmDomains !== []) {
    $route53Credentials = $settings->route53Credentials() !== []
        ? $settings->route53Credentials()
        : $settings->awsCredentials();
    $route53DnsProvider = new Route53DnsProvider($clientFactory->route53($route53Credentials));

    $orchestrator->addStep('certificate', function () use (
        $context,
        $certificateProvisioner,
        $route53DnsProvider,
        $acmDomains,
    ) {
        foreach ($acmDomains as $rootDomain => $domainConfig) {
            $dnsProvider = $domainConfig['dnsProvider'] ?? 'route53';
            if ($dnsProvider !== 'route53') {
                throw new \RuntimeException(
                    "DNS provider '{$dnsProvider}' for '{$rootDomain}' is not implemented yet (only 'route53' is)."
                );
            }

            $domains = ($domainConfig['subdomain'] ?? null) === '*'
                ? [$rootDomain, "*.{$rootDomain}"]
                : [$rootDomain];

            $certificateArn = $certificateProvisioner->request($domains);
            $context->certificateArns[$rootDomain] = $certificateArn;

            $status = $certificateProvisioner->status($certificateArn);
            if ($status === 'ISSUED') {
                echo "Certificate for '{$rootDomain}' is already ISSUED ({$certificateArn}).\n";
                continue;
            }

            $records = $certificateProvisioner->validationRecords($certificateArn);
            if ($records === []) {
                echo "Certificate for '{$rootDomain}' requested ({$certificateArn}), "
                    . "validation details not ready yet -- run this step again shortly.\n";
                continue;
            }

            $zoneId = $route53DnsProvider->resolveZoneId($rootDomain);
            if ($zoneId === null) {
                throw new \RuntimeException(
                    "No Route 53 Hosted Zone found for '{$rootDomain}'. "
                    . 'It must already exist there before requesting a certificate.'
                );
            }

            foreach ($records as $domainName => $record) {
                if ($route53DnsProvider->recordExists($zoneId, $record['cnameName'])) {
                    continue;
                }
                $route53DnsProvider->upsertCname($zoneId, $record['cnameName'], $record['cnameValue']);
                echo "DNS validation record created for '{$domainName}'.\n";
            }

            echo "Certificate for '{$rootDomain}' status: {$status}. "
                . "DNS validation can take several minutes -- run this step again later to confirm.\n";
        }
    });
}

$loadBalancerPreferences = $settings->loadBalancerPreferences();

if ($loadBalancerPreferences['enabled'] ?? false) {
    $loadBalancerProvisioner = new LoadBalancerProvisioner($clientFactory->elasticLoadBalancingV2());

    $orchestrator->addStep('load-balancer', function () use (
        $context,
        $vpcProvisioner,
        $vpcName,
        $securityGroupProvisioner,
        $subnetProvisioner,
        $loadBalancerProvisioner,
        $loadBalancerPreferences,
        $certificateProvisioner,
        $acmDomains,
        $settings,
        $ec2,
    ) {
        $context->vpcId ??= $vpcProvisioner->findByName($vpcName);

        // Which tier's subnets/security group host the load balancer — configurable since
        // renaming the public tier, or a future 'internal' scheme, changes which one applies.
        $tier = $loadBalancerPreferences['tier'] ?? 'web';
        $projectName = $settings->projectName();
        $subnetsPerTier = $settings->vpcPreferences()['subnetsPerTier'] ?? 2;

        $securityGroupConfig = $settings->securityGroupPreferences()[$tier] ?? null;
        if ($securityGroupConfig === null) {
            throw new \RuntimeException("No security group configured for tier '{$tier}'.");
        }

        $securityGroupId = $context->securityGroupIds[$tier]
            ?? $securityGroupProvisioner->findByName($securityGroupConfig['name']);
        if ($securityGroupId === null) {
            throw new \RuntimeException(
                "Security group '{$securityGroupConfig['name']}' not found. Run the security-groups step first."
            );
        }

        $subnetIds = $context->subnetIds[$tier] ?? [];
        if ($subnetIds === []) {
            for ($index = 0; $index < $subnetsPerTier; $index++) {
                $subnetName = "sub-{$projectName}-{$tier}-" . ($index + 1);
                $subnetId = $subnetProvisioner->findByName($context->vpcId, $subnetName);
                if ($subnetId !== null) {
                    $subnetIds[] = $subnetId;
                }
            }
        }
        if ($subnetIds === []) {
            throw new \RuntimeException("No subnets found for tier '{$tier}'. Run the subnets step first.");
        }

        $scheme = $settings->networkProfile() === 'private-with-cloudfront' ? 'internal' : 'internet-facing';

        $targetGroupArn = $loadBalancerProvisioner->createTargetGroup(
            "{$loadBalancerPreferences['name']}-tg",
            $context->vpcId,
        );
        $loadBalancerArn = $loadBalancerProvisioner->create(
            $loadBalancerPreferences['name'],
            $subnetIds,
            [$securityGroupId],
            $scheme,
        );
        $loadBalancerProvisioner->ensureHttpToHttpsRedirect($loadBalancerArn);

        $rootDomain = array_key_first($acmDomains);
        if ($rootDomain !== null) {
            $certificateArn = $context->certificateArns[$rootDomain]
                ?? $certificateProvisioner->findByDomain($rootDomain);

            if ($certificateArn !== null && $certificateProvisioner->status($certificateArn) === 'ISSUED') {
                $loadBalancerProvisioner->ensureHttpsListener($loadBalancerArn, $targetGroupArn, $certificateArn);
            }
        }

        $context->loadBalancerArn = $loadBalancerArn;
        $context->targetGroupArn = $targetGroupArn;

        // Best-effort: register whatever EC2 instances already exist in this VPC. Fine if there are none yet.
        $instancesResult = $ec2->describeInstances([
            'Filters' => [
                ['Name' => 'vpc-id', 'Values' => [$context->vpcId]],
                ['Name' => 'instance-state-name', 'Values' => ['running']],
            ],
        ]);

        $instanceIds = [];
        foreach ($instancesResult['Reservations'] as $reservation) {
            foreach ($reservation['Instances'] as $instance) {
                $instanceIds[] = $instance['InstanceId'];
            }
        }

        $loadBalancerProvisioner->registerTargets($targetGroupArn, $instanceIds);
    });
}

$application = new Application('AWS Provisioner', 'dev');
$application->add(new ProvisionCommand($orchestrator));
$application->setDefaultCommand('provision', true);
$application->run();
