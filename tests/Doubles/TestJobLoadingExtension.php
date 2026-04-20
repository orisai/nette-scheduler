<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Doubles;

use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use stdClass;

/**
 * @property-read stdClass $config
 */
final class TestJobLoadingExtension extends CompilerExtension
{

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'name' => Expect::string()->required(),
		]);
	}

	public function loadConfiguration(): void
	{
		parent::loadConfiguration();

		$builder = $this->getContainerBuilder();
		$config = $this->config;

		$builder->addDefinition($this->prefix('job'))
			->setFactory(TestJob::class, [$config->name])
			->setAutowired(false);
	}

}
