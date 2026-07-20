<?php

declare(strict_types=1);

namespace AwsProvisioner\Network;

/**
 * Expands a tier's allowed ports into a full, numbered set of Network ACL entries —
 * each service port paired with the matching ephemeral return-traffic range in both
 * directions, since Network ACLs are stateless (see config/settings.example.php).
 *
 * Rule numbers are derived from the port itself (100 + port), not from array position --
 * adding/reordering a port never shifts an already-created rule's number, which would
 * otherwise make CreateNetworkAclEntry collide with an unrelated existing rule and get
 * silently skipped as "already exists" (see NetworkAclProvisioner::addEntry()). Assumes
 * configured ports stay below 32666, well above anything used in practice.
 */
final class NetworkAclRuleBuilder
{
    /** Fixed slot for the ephemeral return-traffic rule, below the lowest valid port number. */
    private const EPHEMERAL_RULE_NUMBER = 99;

    /**
     * @param int[] $servicePorts
     * @param int[] $outboundPorts
     * @return array<int, array<string, mixed>>
     */
    public static function build(array $servicePorts, array $outboundPorts): array
    {
        $entries = [];

        foreach ($servicePorts as $port) {
            $entries[] = self::entry(100 + $port, false, $port, $port);
        }

        if ($servicePorts !== []) {
            // Return traffic for connections this tier accepts inbound.
            $entries[] = self::entry(self::EPHEMERAL_RULE_NUMBER, true, 1024, 65535);
        }

        foreach ($outboundPorts as $port) {
            $entries[] = self::entry(100 + $port, true, $port, $port);
        }

        if ($outboundPorts !== []) {
            // Return traffic for connections this tier initiates outbound.
            $entries[] = self::entry(self::EPHEMERAL_RULE_NUMBER, false, 1024, 65535);
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
