<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

/**
 * Expands a tier's allowed ports into a full, numbered set of Network ACL entries —
 * each service port paired with the matching ephemeral return-traffic range in both
 * directions, since Network ACLs are stateless (see config/settings.example.php).
 */
final class NetworkAclRuleBuilder
{
    /**
     * @param int[] $servicePorts
     * @param int[] $outboundPorts
     * @return array<int, array<string, mixed>>
     */
    public static function build(array $servicePorts, array $outboundPorts): array
    {
        $entries = [];
        $ruleNumber = 100;

        foreach ($servicePorts as $port) {
            $entries[] = self::entry($ruleNumber, false, $port, $port);
            $ruleNumber += 10;
        }

        if ($servicePorts !== []) {
            // Return traffic for connections this tier initiates outbound.
            $entries[] = self::entry($ruleNumber, true, 1024, 65535);
            $ruleNumber += 10;
        }

        foreach ($outboundPorts as $port) {
            $entries[] = self::entry($ruleNumber, true, $port, $port);
            $ruleNumber += 10;
        }

        if ($outboundPorts !== []) {
            // Return traffic for connections other tiers send to this one.
            $entries[] = self::entry($ruleNumber, false, 1024, 65535);
            $ruleNumber += 10;
        }

        return $entries;
    }

    /** @return array<string, mixed> */
    private static function entry(int $ruleNumber, bool $egress, int $fromPort, int $toPort): array
    {
        return [
            'RuleNumber' => $ruleNumber,
            'Protocol' => '6',
            'RuleAction' => 'allow',
            'Egress' => $egress,
            'CidrBlock' => '0.0.0.0/0',
            'PortRange' => ['From' => $fromPort, 'To' => $toPort],
        ];
    }
}
