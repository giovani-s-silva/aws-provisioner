<?php

declare(strict_types=1);

namespace AwsProvisioner\Provisioning;

/**
 * Owns the execution order between provisioning steps (network -> certificates -> load-balancer -> storage)
 * so nobody has to remember it or run files by hand. Each step is idempotent, so re-running after
 * a partial failure only creates what is still missing.
 */
final class Orchestrator
{
    /** @var array<string, callable> */
    private array $steps = [];

    public function addStep(string $name, callable $step): void
    {
    }

    /** @return string[] step names in the order they were registered */
    public function stepNames(): array
    {
    }

    /** @param string[] $only run every step when empty, otherwise only the named ones, still in dependency order */
    public function run(array $only = [], bool $dryRun = false): void
    {
    }
}
