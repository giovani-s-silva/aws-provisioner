<?php

declare(strict_types=1);

namespace AwsProvisioner\Support;

/**
 * Carves sequential, non-overlapping IPv4 CIDR blocks out of a VPC's CIDR block, so the
 * user never has to hand-write enough subnet CIDRs to match whatever subnetsPerTier they pick.
 */
final class CidrAllocator
{
    /**
     * @return string[] $count CIDR blocks of size /$newPrefixLength, starting at $startIndex
     * @throws \InvalidArgumentException if there isn't enough room in $baseCidrBlock
     */
    public static function allocate(
        string $baseCidrBlock,
        int $count,
        int $newPrefixLength = 24,
        int $startIndex = 0,
    ): array {
        [$networkAddress, $basePrefixLength] = explode('/', $baseCidrBlock);
        $basePrefixLength = (int) $basePrefixLength;

        if ($newPrefixLength <= $basePrefixLength) {
            throw new \InvalidArgumentException(
                "newPrefixLength (/{$newPrefixLength}) must be smaller (a bigger number) than the base block's "
                . "prefix (/{$basePrefixLength})."
            );
        }

        $maxBlocks = 2 ** ($newPrefixLength - $basePrefixLength);
        if ($startIndex + $count > $maxBlocks) {
            throw new \InvalidArgumentException(
                "{$baseCidrBlock} only fits {$maxBlocks} block(s) of size /{$newPrefixLength}, "
                . "but startIndex {$startIndex} + count {$count} was requested."
            );
        }

        $networkLong = ip2long($networkAddress);
        $blockSize = 2 ** (32 - $newPrefixLength);

        $blocks = [];
        for ($i = 0; $i < $count; $i++) {
            $blockLong = $networkLong + (($startIndex + $i) * $blockSize);
            $blocks[] = long2ip($blockLong) . "/{$newPrefixLength}";
        }

        return $blocks;
    }
}
