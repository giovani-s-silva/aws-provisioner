<?php

declare(strict_types=1);

namespace AwsProvisioner\Compute;

use Aws\AutoScaling\AutoScalingClient;

/**
 * Creates/updates the Auto Scaling Group and keeps it attached to the load balancer's target
 * group. Unlike the Launch Template, this resource is mutable in place -- safe to call
 * repeatedly with the same values, so no diffing is needed here.
 */
final class AutoScalingGroupProvisioner
{
    public function __construct(
        private readonly AutoScalingClient $autoScaling,
    ) {
    }

    /** @param string[] $subnetIds */
    public function ensure(
        string $name,
        string $launchTemplateId,
        array $subnetIds,
        int $minSize,
        int $maxSize,
        int $desiredCapacity,
    ): void {
        $parameters = [
            'AutoScalingGroupName' => $name,
            // '$Default' always follows whatever version LaunchTemplateProvisioner just set as
            // default -- new instances (scale-out, replacements) pick it up automatically.
            // Existing instances are untouched; roll out to them via an instance refresh.
            'LaunchTemplate' => ['LaunchTemplateId' => $launchTemplateId, 'Version' => '$Default'],
            'MinSize' => $minSize,
            'MaxSize' => $maxSize,
            'DesiredCapacity' => $desiredCapacity,
            'VPCZoneIdentifier' => implode(',', $subnetIds),
            // Replace instances the target group reports unhealthy, not just ones that fail
            // EC2's own status checks -- the grace period gives User Data time to finish
            // installing packages before health checks start counting against the instance.
            'HealthCheckType' => 'ELB',
            'HealthCheckGracePeriod' => 300,
        ];

        if ($this->exists($name)) {
            $this->autoScaling->updateAutoScalingGroup($parameters);

            return;
        }

        $this->autoScaling->createAutoScalingGroup($parameters);
    }

    /** Safe to call again -- attaching a target group that's already attached is a no-op. */
    public function attachToTargetGroup(string $name, string $targetGroupArn): void
    {
        $this->autoScaling->attachLoadBalancerTargetGroups([
            'AutoScalingGroupName' => $name,
            'TargetGroupARNs' => [$targetGroupArn],
        ]);
    }

    private function exists(string $name): bool
    {
        $result = $this->autoScaling->describeAutoScalingGroups(['AutoScalingGroupNames' => [$name]]);

        return ($result['AutoScalingGroups'][0] ?? null) !== null;
    }
}
