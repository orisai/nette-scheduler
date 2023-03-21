<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Unit\DI;

use Nette\DI\Container;
use OriNette\DI\Boot\ManualConfigurator;
use function dirname;
use function mkdir;
use const PHP_VERSION_ID;

final class SchedulerExtensionProcessSetup
{

	public static function create(): Container
	{
		$rootDir = dirname(__DIR__, 3);
		if (PHP_VERSION_ID < 8_01_00) {
			@mkdir("$rootDir/var/build");
		}

		$configurator = new ManualConfigurator($rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.executor.process.neon');

		return $configurator->createContainer();
	}

}
