<?php

declare(strict_types=1);

namespace AwsProvisioner\Provisioning;

/**
 * Owns the execution order between provisioning steps so nobody has to remember it or run
 * files by hand. Each step is idempotent, so re-running after a partial failure only
 * creates what is still missing.
 */
final class Orchestrator
{
    /** @var array<string, callable> */
    private array $steps = [];

    public function addStep(string $name, callable $step): void
    {
        $this->steps[$name] = $step;
    }

    /** @return string[] step names in the order they were registered */
    public function stepNames(): array
    {
        return array_keys($this->steps);
    }

    /**
     * @param string[] $only run every step when empty, otherwise only the named ones, still in dependency order
     * @param callable|null $onStep called with (string $name, bool $dryRun) right before each selected step runs
     */
    public function run(array $only = [], bool $dryRun = false, ?callable $onStep = null): void
    {
        foreach ($this->steps as $name => $step) {
            if ($only !== [] && !in_array($name, $only, true)) {
                continue;
            }

            if ($onStep !== null) {
                $onStep($name, $dryRun);
            }

            if ($dryRun) {
                continue;
            }

            $step();
        }
    }
}
