<?php

declare(strict_types=1);

namespace AwsProvisioner\Compute;

use Aws\Ssm\SsmClient;

/**
 * Resolves the latest AMI ID for a given OS family via public SSM parameters, so no AMI ID
 * is ever hardcoded (they're region-specific and get replaced as new patches are released).
 */
final class AmiResolver
{
    /** @var array<string, string> */
    private const SSM_PARAMETER_BY_OS_FAMILY = [
        'amazon-linux' => '/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-x86_64',
        'ubuntu' => '/aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id',
    ];

    public function __construct(
        private readonly SsmClient $ssm,
    ) {
    }

    /** @param array{tier?: string, instanceType?: string, osFamily?: string, amiId?: ?string} $computePreferences */
    public function resolve(array $computePreferences): string
    {
        $amiId = $computePreferences['amiId'] ?? null;
        if ($amiId !== null) {
            return $amiId;
        }

        $osFamily = $computePreferences['osFamily'] ?? 'ubuntu';
        $parameterName = self::SSM_PARAMETER_BY_OS_FAMILY[$osFamily] ?? null;
        if ($parameterName === null) {
            throw new \RuntimeException(
                "Unknown compute.osFamily '{$osFamily}'. Accepted values: "
                . implode(', ', array_keys(self::SSM_PARAMETER_BY_OS_FAMILY)) . '.'
            );
        }

        $result = $this->ssm->getParameter(['Name' => $parameterName]);

        return $result['Parameter']['Value'];
    }
}
