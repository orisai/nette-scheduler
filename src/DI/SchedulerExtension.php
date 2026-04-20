<?php declare(strict_types = 1);

namespace OriNette\Scheduler\DI;

use Closure;
use Cron\CronExpression;
use Nette\DI\CompilerExtension;
use Nette\DI\ContainerBuilder;
use Nette\DI\Definitions\Definition;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Elements\AnyOf;
use Nette\Schema\Elements\Type;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use OriNette\DI\Definitions\DefinitionsLoader;
use OriNette\Scheduler\Tracy\SchedulerTracyLogger;
use Orisai\CronExpressionExplainer\CronExpressionExplainer;
use Orisai\CronExpressionExplainer\DefaultCronExpressionExplainer;
use Orisai\Scheduler\Command\ExplainCommand;
use Orisai\Scheduler\Command\ListCommand;
use Orisai\Scheduler\Command\RunCommand;
use Orisai\Scheduler\Command\RunJobCommand;
use Orisai\Scheduler\Command\StatusCommand;
use Orisai\Scheduler\Command\WorkerCommand;
use Orisai\Scheduler\Executor\ProcessJobExecutor;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\Maintenance\MaintenanceManager;
use Orisai\Scheduler\ManagedScheduler;
use Orisai\Scheduler\Scheduler;
use stdClass;
use function assert;
use function class_exists;
use function function_exists;
use function in_array;
use function is_array;
use function str_starts_with;
use function substr;
use function timezone_identifiers_list;

/**
 * @property-read stdClass $config
 */
final class SchedulerExtension extends CompilerExtension
{

	private function createCallbackSchema(): AnyOf
	{
		return Expect::anyOf(
			Expect::string(),
			/* @infection-ignore-all */
			Expect::array()->min(2)->max(2),
			Expect::type(Statement::class),
		);
	}

	private function createJobEventsSchema(): Type
	{
		return Expect::listOf($this->createCallbackSchema());
	}

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'errorHandler' => Expect::anyOf(
			/* @infection-ignore-all */
				Expect::array()->min(2)->max(2),
				'tracy',
				null,
			)->default(null),
			'executor' => Expect::anyOf('auto', 'basic', 'process')->default('auto'),
			'console' => Expect::structure([
				'script' => Expect::string()->default('bin/console'),
				'runCommand' => Expect::string()->default('scheduler:run'),
				'runJobCommand' => Expect::string()->default('scheduler:run-job'),
			]),
			'events' => Expect::structure([
				'beforeRun' => $this->createJobEventsSchema(),
				'afterRun' => $this->createJobEventsSchema(),
				'lockedJob' => $this->createJobEventsSchema()->deprecated(
					"The 'events.lockedJob' option is deprecated, use 'events.afterJob'"
					. ' and check $result->getState() === JobResultState::lock() instead.',
				),
				'beforeJob' => $this->createJobEventsSchema(),
				'afterJob' => $this->createJobEventsSchema(),
			]),
			'runRegistry' => DefinitionsLoader::schema()->default(null),
			'maintenance' => Expect::structure([
				'checker' => DefinitionsLoader::schema()->default(null),
				'gracePeriodSeconds' => Expect::int(30),
			]),
			'jobs' => Expect::arrayOf(
				Expect::structure([
					'enabled' => Expect::bool(true),
					'expression' => Expect::string()
						->assert(
							static function (string $value): bool {
								if (str_starts_with($value, '@@')) { // '@yearly' - string
									$value = substr($value, 1);
								} elseif (str_starts_with($value, '@')) { // @yearly - service reference
									return false;
								}

								return CronExpression::isValidExpression($value);
							},
							'Valid cron expression',
						),
					'callback' => $this->createCallbackSchema()->default(null),
					'job' => DefinitionsLoader::schema()->default(null),
					'repeatAfterSeconds' => Expect::int(0)
						->min(0)
						->max(30),
					'timeZone' => Expect::anyOf(
						Expect::string(),
						Expect::null(),
					)->assert(static function (?string $timeZone): bool {
						if ($timeZone === null) {
							return true;
						}

						return in_array($timeZone, timezone_identifiers_list(), true);
					}, 'Valid timezone'),
				])->assert(static function (stdClass $values): bool {
					if ($values->callback !== null && $values->job !== null) {
						return false;
					}

					return $values->callback !== null || $values->job !== null;
				}, "Use either 'callback' or 'job'"),
			),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;

		$runRegistryDefinition = $this->registerRunRegistry($config);
		$maintenanceManagerDefinition = $this->registerMaintenance($builder, $config);
		$schedulerDefinition = $this->registerScheduler(
			$builder,
			$config,
			$runRegistryDefinition,
			$maintenanceManagerDefinition,
		);
		$this->compiler->addExportedType(Scheduler::class);

		$this->registerCommands(
			$builder,
			$config,
			$schedulerDefinition,
			$runRegistryDefinition,
			$maintenanceManagerDefinition,
		);
		$this->registerExplainer($builder);
	}

	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;

		$this->addJobsToManager($builder, $config);
	}

	/**
	 * @param ServiceDefinition|Definition|Reference|null $runRegistryDefinition
	 */
	private function registerScheduler(
		ContainerBuilder $builder,
		stdClass $config,
		$runRegistryDefinition,
		?ServiceDefinition $maintenanceManagerDefinition
	): ServiceDefinition
	{
		/** @infection-ignore-all */
		$schedulerDefinition = $builder->addDefinition($this->prefix('scheduler'))
			->setFactory(ManagedScheduler::class, [
				'jobManager' => $this->registerJobManager($builder, $config),
				'errorHandler' => $this->registerErrorHandler($config),
				'executor' => $this->registerExecutor($builder, $config),
				'maintenanceManager' => $maintenanceManagerDefinition,
				'runRegistry' => $runRegistryDefinition,
			]);

		$events = $config->events;

		$this->addEventsToScheduler(
			$schedulerDefinition,
			'addBeforeRunCallback',
			$events->beforeRun,
		);

		$this->addEventsToScheduler(
			$schedulerDefinition,
			'addAfterRunCallback',
			$events->afterRun,
		);

		$this->addEventsToScheduler(
			$schedulerDefinition,
			'addLockedJobCallback',
			$events->lockedJob,
		);

		$this->addEventsToScheduler(
			$schedulerDefinition,
			'addBeforeJobCallback',
			$events->beforeJob,
		);

		$this->addEventsToScheduler(
			$schedulerDefinition,
			'addAfterJobCallback',
			$events->afterJob,
		);

		return $schedulerDefinition;
	}

	/**
	 * @return ServiceDefinition|Definition|Reference|null
	 */
	private function registerRunRegistry(stdClass $config)
	{
		if ($config->runRegistry === null) {
			return null;
		}

		return (new DefinitionsLoader($this->compiler))->loadDefinitionFromConfig(
			$config->runRegistry,
			$this->prefix('runRegistry'),
		);
	}

	private function registerMaintenance(ContainerBuilder $builder, stdClass $config): ?ServiceDefinition
	{
		$maintenance = $config->maintenance;

		if ($maintenance->checker === null) {
			return null;
		}

		$loader = new DefinitionsLoader($this->compiler);

		$checkerRef = $loader->loadDefinitionFromConfig(
			$maintenance->checker,
			$this->prefix('maintenanceChecker'),
		);

		return $builder->addDefinition($this->prefix('maintenanceManager'))
			->setFactory(MaintenanceManager::class, [
				'checker' => $checkerRef,
				'gracePeriodSeconds' => $maintenance->gracePeriodSeconds,
			])
			->setAutowired(false);
	}

	private function registerJobManager(ContainerBuilder $builder, stdClass $config): ServiceDefinition
	{
		return $builder->addDefinition($this->prefix('jobManager'))
			->setFactory(LazyJobManager::class)
			->setAutowired(false);
	}

	private function addJobsToManager(ContainerBuilder $builder, stdClass $config): void
	{
		$loader = new DefinitionsLoader($this->compiler);

		$jobSchedules = [];
		foreach ($config->jobs as $id => $job) {
			if (!$job->enabled) {
				continue;
			}

			$jobDefinitionName = $this->registerJob($id, $job, $builder, $loader);
			$jobSchedules[$id] = [
				'job' => $jobDefinitionName,
				'expression' => $job->expression,
				'repeatAfterSeconds' => $job->repeatAfterSeconds,
				'timeZone' => $job->timeZone,
			];
		}

		$definition = $builder->getDefinition($this->prefix('jobManager'));
		assert($definition instanceof ServiceDefinition);
		$definition->setArgument('jobSchedules', $jobSchedules);
	}

	/**
	 * @param int|string $id
	 */
	private function registerJob($id, stdClass $job, ContainerBuilder $builder, DefinitionsLoader $loader): string
	{
		$jobDefinitionName = $this->prefix("job.$id");
		if ($job->callback !== null) {
			$builder->addDefinition($jobDefinitionName)
				->setFactory(new Statement(
					CallbackJob::class,
					[
						new Statement([
							Closure::class,
							'fromCallable',
						], [
							$job->callback,
						]),
					],
				))
				->setAutowired(false);
		} else {
			$loader->loadDefinitionFromConfig($job->job, $jobDefinitionName);
		}

		return $jobDefinitionName;
	}

	private function registerErrorHandler(stdClass $config): ?Statement
	{
		if ($config->errorHandler === 'tracy') {
			return new Statement([
				Closure::class,
				'fromCallable',
			], [
				[SchedulerTracyLogger::class, 'log'],
			]);
		}

		if (is_array($config->errorHandler)) {
			return new Statement([
				Closure::class,
				'fromCallable',
			], [
				$config->errorHandler,
			]);
		}

		return null;
	}

	private function registerExecutor(ContainerBuilder $builder, stdClass $config): ?ServiceDefinition
	{
		if (
			($config->executor === 'auto' && function_exists('proc_open'))
			|| $config->executor === 'process'
		) {
			/** @infection-ignore-all */
			return $builder->addDefinition($this->prefix('executor'))
				->setFactory(ProcessJobExecutor::class)
				->addSetup('setExecutable', [
					$config->console->script,
					$config->console->runJobCommand,
				])
				->setAutowired(false);
		}

		return null;
	}

	/**
	 * @param array<mixed> $events
	 */
	private function addEventsToScheduler(
		ServiceDefinition $schedulerDefinition,
		string $method,
		array $events
	): void
	{
		foreach ($events as $event) {
			$schedulerDefinition->addSetup(
				$method,
				[
					new Statement([
						Closure::class,
						'fromCallable',
					], [
						$event,
					]),
				],
			);
		}
	}

	/**
	 * @param ServiceDefinition|Definition|Reference|null $runRegistryDefinition
	 */
	private function registerCommands(
		ContainerBuilder $builder,
		stdClass $config,
		ServiceDefinition $schedulerDefinition,
		$runRegistryDefinition,
		?ServiceDefinition $maintenanceManagerDefinition
	): void
	{
		/** @infection-ignore-all */
		$builder->addDefinition($this->prefix('command.list'))
			->setFactory(ListCommand::class, [
				$schedulerDefinition,
			])
			->setAutowired(false);

		/** @infection-ignore-all */
		$builder->addDefinition($this->prefix('command.run'))
			->setFactory(RunCommand::class, [
				'scheduler' => $schedulerDefinition,
				'maintenanceManager' => $maintenanceManagerDefinition,
			])
			->setAutowired(false);

		/** @infection-ignore-all */
		$builder->addDefinition($this->prefix('command.runJob'))
			->setFactory(RunJobCommand::class, [
				$schedulerDefinition,
			])
			->setAutowired(false);

		/** @infection-ignore-all */
		$builder->addDefinition($this->prefix('command.worker'))
			->setFactory(WorkerCommand::class)
			->addSetup('setExecutable', [
				$config->console->script,
				$config->console->runCommand,
			])
			->setAutowired(false);

		if ($runRegistryDefinition !== null) {
			/** @infection-ignore-all */
			$builder->addDefinition($this->prefix('command.status'))
				->setFactory(StatusCommand::class, [
					'runRegistry' => $runRegistryDefinition,
					'maintenanceManager' => $maintenanceManagerDefinition,
				])
				->setAutowired(false);
		}

		// Compat - orisai/scheduler <2.1
		/** @infection-ignore-all */
		if (class_exists(ExplainCommand::class)) {
			$builder->addDefinition($this->prefix('command.explain'))
				->setFactory(ExplainCommand::class, [
					$schedulerDefinition,
				])
				->setAutowired(false);
		}
	}

	private function registerExplainer(ContainerBuilder $builder): void
	{
		$builder->addDefinition($this->prefix('explainer'))
			->setFactory(DefaultCronExpressionExplainer::class)
			->setType(CronExpressionExplainer::class);
	}

}
