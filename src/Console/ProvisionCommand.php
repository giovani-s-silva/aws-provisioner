<?php

declare(strict_types=1);

namespace AwsProvisioner\Console;

use AwsProvisioner\Provisioning\Orchestrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php bin/provision.php [steps...] [--dry-run]`
 * With no steps given, runs the full Orchestrator step list in order.
 */
final class ProvisionCommand extends Command
{
    protected static $defaultName = 'provision';

    public function __construct(
        private readonly Orchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('steps', InputArgument::IS_ARRAY, 'Step names to run (default: all, in dependency order)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan without calling AWS');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
    }
}
