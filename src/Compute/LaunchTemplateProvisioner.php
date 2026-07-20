<?php

declare(strict_types=1);

namespace AwsProvisioner\Compute;

use Aws\Ec2\Ec2Client;
use Aws\Ec2\Exception\Ec2Exception;

/**
 * Creates/updates the EC2 Launch Template used by the Auto Scaling Group. Launch Templates are
 * versioned rather than mutable in place -- if the desired configuration differs from the
 * current default version, a new version is created and set as default. Existing instances
 * keep running on whatever version they launched with; only new ones pick up the change.
 */
final class LaunchTemplateProvisioner
{
    public function __construct(
        private readonly Ec2Client $ec2,
    ) {
    }

    public function findByName(string $name): ?string
    {
        return $this->describeDefaultVersion($name)['id'] ?? null;
    }

    /** @param array<string, mixed> $launchTemplateData */
    public function ensure(string $name, array $launchTemplateData): string
    {
        $existing = $this->describeDefaultVersion($name);

        if ($existing === null) {
            $result = $this->ec2->createLaunchTemplate([
                'LaunchTemplateName' => $name,
                'LaunchTemplateData' => $launchTemplateData,
            ]);

            return $result['LaunchTemplate']['LaunchTemplateId'];
        }

        if ($this->matches($existing['data'], $launchTemplateData)) {
            return $existing['id'];
        }

        $newVersion = $this->ec2->createLaunchTemplateVersion([
            'LaunchTemplateId' => $existing['id'],
            'LaunchTemplateData' => $launchTemplateData,
        ]);

        $this->ec2->modifyLaunchTemplate([
            'LaunchTemplateId' => $existing['id'],
            'DefaultVersion' => (string) $newVersion['LaunchTemplateVersion']['VersionNumber'],
        ]);

        return $existing['id'];
    }

    /** @return array{id: string, data: array<string, mixed>}|null */
    private function describeDefaultVersion(string $name): ?array
    {
        try {
            $templates = $this->ec2->describeLaunchTemplates(['LaunchTemplateNames' => [$name]]);
        } catch (Ec2Exception $exception) {
            if ($exception->getAwsErrorCode() === 'InvalidLaunchTemplateName.NotFoundException') {
                return null;
            }
            throw $exception;
        }

        $launchTemplateId = $templates['LaunchTemplates'][0]['LaunchTemplateId'];

        $versions = $this->ec2->describeLaunchTemplateVersions([
            'LaunchTemplateId' => $launchTemplateId,
            'Versions' => ['$Default'],
        ]);

        return [
            'id' => $launchTemplateId,
            'data' => $versions['LaunchTemplateVersions'][0]['LaunchTemplateData'],
        ];
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $desired */
    private function matches(array $current, array $desired): bool
    {
        return ($current['ImageId'] ?? null) === ($desired['ImageId'] ?? null)
            && ($current['InstanceType'] ?? null) === ($desired['InstanceType'] ?? null)
            && ($current['UserData'] ?? null) === ($desired['UserData'] ?? null)
            && ($current['SecurityGroupIds'] ?? []) === ($desired['SecurityGroupIds'] ?? []);
    }
}
