<?php

declare(strict_types=1);

namespace AwsProvisioner\LoadBalancer;

use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;

/**
 * Creates the target group, application load balancer, HTTP->HTTPS redirect listener
 * and the HTTPS listener bound to the ACM certificate. Registers running EC2 instances as targets.
 */
final class LoadBalancerProvisioner
{
    public function __construct(
        private readonly ElasticLoadBalancingV2Client $elb,
    ) {
    }

    public function createTargetGroup(string $name, string $vpcId): string
    {
    }

    public function create(string $name, array $subnetIds, array $securityGroupIds): string
    {
    }

    public function addHttpToHttpsRedirect(string $loadBalancerArn): void
    {
    }

    public function addHttpsListener(string $loadBalancerArn, string $targetGroupArn, array $certificateArns): void
    {
    }

    public function registerTargets(string $targetGroupArn, array $instanceIds): void
    {
    }
}
