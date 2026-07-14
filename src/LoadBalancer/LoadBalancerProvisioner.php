<?php

declare(strict_types=1);

namespace AwsProvisioner\LoadBalancer;

use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\ElasticLoadBalancingV2\Exception\ElasticLoadBalancingV2Exception;

/**
 * Creates the target group, application load balancer, HTTP->HTTPS redirect listener
 * and the HTTPS listener bound to the ACM certificate. Registers running EC2 instances as targets.
 */
final class LoadBalancerProvisioner
{
    /** Modern, broadly-compatible policy: TLS 1.2/1.3 only, nothing older (see README). */
    private const SSL_POLICY = 'ELBSecurityPolicy-TLS13-1-2-2021-06';

    public function __construct(
        private readonly ElasticLoadBalancingV2Client $elb,
    ) {
    }

    public function findTargetGroupByName(string $name): ?string
    {
        try {
            $result = $this->elb->describeTargetGroups(['Names' => [$name]]);
        } catch (ElasticLoadBalancingV2Exception $exception) {
            if ($exception->getAwsErrorCode() === 'TargetGroupNotFound') {
                return null;
            }
            throw $exception;
        }

        return $result['TargetGroups'][0]['TargetGroupArn'] ?? null;
    }

    public function createTargetGroup(string $name, string $vpcId): string
    {
        $existingArn = $this->findTargetGroupByName($name);
        if ($existingArn !== null) {
            return $existingArn;
        }

        $result = $this->elb->createTargetGroup([
            'Name' => $name,
            'Protocol' => 'HTTP',
            'Port' => 80,
            'VpcId' => $vpcId,
            'HealthCheckProtocol' => 'HTTP',
            'HealthCheckPath' => '/',
            'HealthCheckIntervalSeconds' => 20,
            'HealthCheckTimeoutSeconds' => 5,
            'HealthyThresholdCount' => 3,
            'UnhealthyThresholdCount' => 2,
            'Matcher' => ['HttpCode' => '200'],
        ]);

        return $result['TargetGroups'][0]['TargetGroupArn'];
    }

    public function findByName(string $name): ?string
    {
        try {
            $result = $this->elb->describeLoadBalancers(['Names' => [$name]]);
        } catch (ElasticLoadBalancingV2Exception $exception) {
            if ($exception->getAwsErrorCode() === 'LoadBalancerNotFound') {
                return null;
            }
            throw $exception;
        }

        return $result['LoadBalancers'][0]['LoadBalancerArn'] ?? null;
    }

    /** @param string[] $subnetIds @param string[] $securityGroupIds */
    public function create(string $name, array $subnetIds, array $securityGroupIds, string $scheme): string
    {
        $existingArn = $this->findByName($name);
        if ($existingArn !== null) {
            return $existingArn;
        }

        $result = $this->elb->createLoadBalancer([
            'Name' => $name,
            'Subnets' => $subnetIds,
            'SecurityGroups' => $securityGroupIds,
            'Type' => 'application',
            'IpAddressType' => 'ipv4',
            'Scheme' => $scheme,
        ]);

        return $result['LoadBalancers'][0]['LoadBalancerArn'];
    }

    private function findListenerByPort(string $loadBalancerArn, int $port): ?array
    {
        $result = $this->elb->describeListeners(['LoadBalancerArn' => $loadBalancerArn]);

        foreach ($result['Listeners'] as $listener) {
            if ($listener['Port'] === $port) {
                return $listener;
            }
        }

        return null;
    }

    /** Safe to call again — a listener already on port 80 is left as is. */
    public function ensureHttpToHttpsRedirect(string $loadBalancerArn): void
    {
        if ($this->findListenerByPort($loadBalancerArn, 80) !== null) {
            return;
        }

        $this->elb->createListener([
            'LoadBalancerArn' => $loadBalancerArn,
            'Port' => 80,
            'Protocol' => 'HTTP',
            'DefaultActions' => [
                [
                    'Type' => 'redirect',
                    'RedirectConfig' => [
                        'Protocol' => 'HTTPS',
                        'Port' => '443',
                        'Host' => '#{host}',
                        'Path' => '/#{path}',
                        'Query' => '#{query}',
                        'StatusCode' => 'HTTP_301',
                    ],
                ],
            ],
        ]);
    }

    /** Safe to call again — a listener already on port 443 is left as is. */
    public function ensureHttpsListener(string $loadBalancerArn, string $targetGroupArn, string $certificateArn): void
    {
        if ($this->findListenerByPort($loadBalancerArn, 443) !== null) {
            return;
        }

        $this->elb->createListener([
            'LoadBalancerArn' => $loadBalancerArn,
            'Port' => 443,
            'Protocol' => 'HTTPS',
            'SslPolicy' => self::SSL_POLICY,
            'Certificates' => [['CertificateArn' => $certificateArn]],
            'DefaultActions' => [
                [
                    'Type' => 'forward',
                    'TargetGroupArn' => $targetGroupArn,
                ],
            ],
        ]);
    }

    /** @param string[] $instanceIds */
    public function registerTargets(string $targetGroupArn, array $instanceIds): void
    {
        if ($instanceIds === []) {
            return;
        }

        $this->elb->registerTargets([
            'TargetGroupArn' => $targetGroupArn,
            'Targets' => array_map(static fn (string $id): array => ['Id' => $id], $instanceIds),
        ]);
    }
}
