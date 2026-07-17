<?php

declare(strict_types=1);

namespace AwsProvisioner\Config;

/**
 * Cross-checks config/settings.php for tiers that exist in one section but not another —
 * e.g. a tier declared under network.tiers with no matching securityGroups entry (it will
 * silently fall back to the VPC's default instead), or a typo'd tier name in one of the
 * per-tier sections that doesn't match anything under network.tiers (silently ignored).
 * Returns warnings instead of throwing: an intentional gap (no security group for a tier,
 * for example) is a valid choice, not necessarily a mistake — the point is to surface it.
 */
final class TierConsistencyChecker
{
    /** @return string[] */
    public static function check(Settings $settings): array
    {
        $tierNames = $settings->tierNames();

        $sections = [
            'securityGroups' => $settings->securityGroupPreferences(),
            'networkAcls' => $settings->networkAclPreferences(),
            'routeTables' => $settings->routeTablePreferences(),
        ];

        $warnings = [];

        foreach ($sections as $sectionName => $section) {
            $sectionTierNames = array_keys($section);

            foreach (array_diff($tierNames, $sectionTierNames) as $tier) {
                $warnings[] = "Tier '{$tier}' has no entry under '{$sectionName}' "
                    . '-- it will rely on the VPC default instead of a dedicated one.';
            }

            foreach (array_diff($sectionTierNames, $tierNames) as $tier) {
                $warnings[] = "'{$sectionName}' has an entry for '{$tier}', but no such tier exists "
                    . 'under network.tiers -- it will be ignored.';
            }
        }

        return $warnings;
    }
}
