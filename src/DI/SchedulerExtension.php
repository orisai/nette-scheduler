<?php declare(strict_types = 1);

namespace OriNette\Scheduler\DI;

use Closure;
use Cron\CronExpression;
use Nette\DI\CompilerExtension;
use Nette\DI\ContainerBuilder;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use OriNette\DI\Definitions\DefinitionsLoader;
use OriNette\Scheduler\Tracy\SchedulerTracyLogger;
use Orisai\Scheduler\Command\ListCommand;
use Orisai\Scheduler\Command\RunCommand;
use Orisai\Scheduler\Command\RunJobCommand;
use Orisai\Scheduler\Command\WorkerCommand;
use Orisai\Scheduler\Executor\ProcessJobExecutor;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\ManagedScheduler;
use stdClass;
use function function_exists;
use function is_array;

/**
 * @property-read stdClass $config
 */
final class SchedulerExtension extends CompilerExtension
{

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
				'beforeJob' => Expect::listOf(
					Expect::anyOf(
						Expect::string(),
						/* @infection-ignore-all */
						Expect::array()->min(2)->max(2),
						Expect::type(Statement::class),
					),
				),
				'afterJob' => Expect::listOf(
					Expect::anyOf(
						Expect::string(),
						/* @infection-ignore-all */
						Expect::array()->min(2)->max(2),
						Expect::type(Statement::class),
					),
				),
			]),
			'jobs' => Expect::arrayOf(
				Expect::structure([
					'expression' => Expect::string()
						->assert(
							static fn (string $value): bool => CronExpression::isValidExpression($value),
							'Valid cron expression',
						),
					'callback' => Expect::anyOf(
						Expect::string(),
						/* @infection-ignore-all */
						Expect::array()->min(2)->max(2),
						Expect::type(Statement::class),
					)->default(null),
					'job' => DefinitionsLoader::schema()->default(null),
				])->assert(static function (stdClass $values): bool {
					if ($values->callback !== null && $values->job !== null) {
						return false;
					}

					return $values->callback !== null || $values->job !== null;
				}, "Use either 'callback' or 'assert'"),
			),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;

		$schedulerDefinition = $this->registerScheduler($builder, $config);
		$this->registerCommands($builder, $config, $schedulerDefinition);
	}

	private function registerScheduler(ContainerBuilder $builder, stdClass $config): ServiceDefinition
	{
		/** @infection-ignore-all */
		$schedulerDefinition = $builder->addDefinition($this->prefix('scheduler'))
			->setFactory(ManagedScheduler::class, [
				'jobManager' => $this->registerJobManager($builder, $config),
				'errorHandler' => $this->registerErrorHandler($config),
				'executor' => $this->registerExecutor($builder, $config),
			]);

		$events = $config->events;

		foreach ($events->beforeJob as $event) {
			$schedulerDefinition->addSetup(
				'addBeforeJobCallback',
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

		foreach ($events->afterJob as $event) {
			$schedulerDefinition->addSetup(
				'addAfterJobCallback',
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

		return $schedulerDefinition;
	}

	private function registerJobManager(ContainerBuilder $builder, stdClass $config): ServiceDefinition
	{
		$loader = new DefinitionsLoader($this->compiler);

		$jobs = [];
		$expressions = [];
		foreach ($config->jobs as $id => $job) {
			$expressions[$id] = $job->expression;

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
				$loader->loadDefinitionFromConfig(
					$job->job,
					$jobDefinitionName,
				);
			}

			$jobs[$id] = $jobDefinitionName;
		}

		return $builder->addDefinition($this->prefix('jobManager'))
			->setFactory(LazyJobManager::class, [
				'jobs' => $jobs,
				'expressions' => $expressions,
			])
			->setAutowired(false);
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

	private function registerCommands(
		ContainerBuilder $builder,
		stdClass $config,
		ServiceDefinition $schedulerDefinition
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
				$schedulerDefinition,
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
	}

}
