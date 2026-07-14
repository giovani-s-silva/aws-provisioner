<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

/**
 * Translates the friendly ingress rules from config/settings.php ('my-ip', a CIDR,
 * or 'security-group:tier') into the raw IpPermissions shape the EC2 API expects.
 */
final class SecurityGroupRuleResolver
{
    /**
     * @param array<int, array{protocol: string, port: int, source: string}> $ingressRules
     * @param array<string, string> $groupIdsByTier
     * @return array<int, array<string, mixed>>
     */
    public static function resolve(array $ingressRules, array $groupIdsByTier, string $myIp): array
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
}
