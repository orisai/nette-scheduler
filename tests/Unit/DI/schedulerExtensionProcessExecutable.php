<?php declare(strict_types = 1);

use Symfony\Component\Console\Application;
use Tests\OriNette\Scheduler\Unit\DI\SchedulerExtensionProcessSetup;

require_once __DIR__ . '/../../../vendor/autoload.php';

$container = SchedulerExtensionProcessSetup::create();

$application = $container->getByType(Application::class);
exit($application->run());
