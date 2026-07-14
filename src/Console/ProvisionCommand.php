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
        /** @var string[] $steps */
        $steps = $input->getArgument('steps');
        $dryRun = (bool) $input->getOption('dry-run');

        $validNames = $this->orchestrator->stepNames();
        $unknown = array_diff($steps, $validNames);
        if ($unknown !== []) {
            $output->writeln(sprintf(
                '<error>Unknown step(s): %s. Available steps: %s.</error>',
                implode(', ', $unknown),
                implode(', ', $validNames),
            ));

            return Command::FAILURE;
        }

        $this->orchestrator->run($steps, $dryRun, function (string $name, bool $dryRun) use ($output): void {
            $output->writeln(sprintf('%s <info>%s</info>', $dryRun ? '[dry-run]' : '->', $name));
        });

        $output->writeln('<info>Done.</info>');

        return Command::SUCCESS;
    }
}
