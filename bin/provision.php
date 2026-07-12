#!/usr/bin/env php
<?php

declare(strict_types=1);

use AwsProvisioner\Console\ProvisionCommand;
use Symfony\Component\Console\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

// Bootstrap (.env + config/settings.php + ClientFactory + Orchestrator) goes here once the
// provisioners above are implemented. For now this only wires the CLI shape.

$application = new Application('AWS Provisioner', 'dev');
// $application->add(new ProvisionCommand($orchestrator));
$application->run();
