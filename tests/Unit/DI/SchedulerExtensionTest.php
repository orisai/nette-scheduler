<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Unit\DI;

use Cron\CronExpression;
use DateTimeZone;
use Exception;
use Generator;
use Nette\DI\InvalidConfigurationException;
use OriNette\DI\Boot\ManualConfigurator;
use OriNette\Scheduler\DI\LazyJobManager;
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
use Orisai\Scheduler\RunRegistry\FileRunRegistry;
use Orisai\Scheduler\RunRegistry\LockPoolRunRegistry;
use Orisai\Scheduler\Scheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Tests\OriNette\Scheduler\Doubles\TestEventRecorder;
use Tests\OriNette\Scheduler\Doubles\TestJob;
use Tests\OriNette\Scheduler\Doubles\TestLogger;
use Tests\OriNette\Scheduler\Doubles\TestSchedulerLogger;
use Tests\OriNette\Scheduler\Doubles\TestService;
use Tracy\Debugger;
use function array_filter;
use function class_exists;
use function dirname;
use function function_exists;
use function mkdir;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use const E_USER_DEPRECATED;
use const PHP_VERSION_ID;

/**
 * @runTestsInSeparateProcesses
 */
final class SchedulerExtensionTest extends TestCase
{

	private string $rootDir;

	protected function setUp(): void
	{
		parent::setUp();

		$this->rootDir = dirname(__DIR__, 3);
		if (PHP_VERSION_ID < 8_01_00) {
			@mkdir("$this->rootDir/var/build");
		}
	}

	public function testMinimal(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.minimal.neon');

		$container = $configurator->createContainer();

		$scheduler = $container->getService('orisai.scheduler.scheduler');
		self::assertInstanceOf(ManagedScheduler::class, $scheduler);
		self::assertSame($scheduler, $container->getByType(Scheduler::class));

		$manager = $container->getService('orisai.scheduler.jobManager');
		self::assertInstanceOf(LazyJobManager::class, $manager);
		self::assertNull($container->getByType(LazyJobManager::class, false));

		if (function_exists('proc_open')) {
			$executor = $container->getService('orisai.scheduler.executor');
			self::assertInstanceOf(ProcessJobExecutor::class, $executor);
			self::assertNull($container->getByType(ProcessJobExecutor::class, false));
		} else {
			self::assertFalse($container->hasService('orisai.scheduler.executor'));
		}

		$listCommand = $container->getService('orisai.scheduler.command.list');
		self::assertInstanceOf(ListCommand::class, $listCommand);
		self::assertNull($container->getByType(ListCommand::class, false));

		$runCommand = $container->getService('orisai.scheduler.command.run');
		self::assertInstanceOf(RunCommand::class, $runCommand);
		self::assertNull($container->getByType(RunCommand::class, false));

		$runJobCommand = $container->getService('orisai.scheduler.command.runJob');
		self::assertInstanceOf(RunJobCommand::class, $runJobCommand);
		self::assertNull($container->getByType(RunJobCommand::class, false));

		$workerCommand = $container->getService('orisai.scheduler.command.worker');
		self::assertInstanceOf(WorkerCommand::class, $workerCommand);
		self::assertNull($container->getByType(WorkerCommand::class, false));

		// Compat - orisai/scheduler <2.1
		if (class_exists(ExplainCommand::class)) {
			$explainCommand = $container->getService('orisai.scheduler.command.explain');
			self::assertInstanceOf(ExplainCommand::class, $explainCommand);
			self::assertNull($container->getByType(ExplainCommand::class, false));
		}

		$explainer = $container->getService('orisai.scheduler.explainer');
		self::assertInstanceOf(DefaultCronExpressionExplainer::class, $explainer);
		self::assertSame($container->getByType(CronExpressionExplainer::class), $explainer);
	}

	public function testSchedulerIsExportedType(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.exportedType.neon');

		$container = $configurator->createContainer();

		$scheduler = $container->getService('orisai.scheduler.scheduler');
		self::assertInstanceOf(ManagedScheduler::class, $scheduler);
		self::assertSame($scheduler, $container->getByType(Scheduler::class));

		// Not exported type for comparison
		self::assertNull($container->getByType(CronExpressionExplainer::class, false));
	}

	public function testJobSchedules(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.jobSchedules.neon');

		$container = $configurator->createContainer();

		$scheduler = $container->getByType(Scheduler::class);
		$schedules = $scheduler->getJobSchedules();

		self::assertCount(3, $schedules);

		$schedule = $schedules[0];
		self::assertEquals(new CronExpression('* * * * *'), $schedule->getExpression());
		self::assertSame(0, $schedule->getRepeatAfterSeconds());
		self::assertNull($schedule->getTimeZone());

		$schedule = $schedules[1];
		self::assertEquals(new CronExpression('0 * * * *'), $schedule->getExpression());
		self::assertSame(1, $schedule->getRepeatAfterSeconds());
		self::assertEquals(new DateTimeZone('Europe/Prague'), $schedule->getTimeZone());

		$schedule = $schedules[2];
		self::assertEquals(new CronExpression('@yearly'), $schedule->getExpression());
		self::assertSame(30, $schedule->getRepeatAfterSeconds());
		self::assertEquals(new DateTimeZone('UTC'), $schedule->getTimeZone());
	}

	public function testEnabledJob(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.enabledJob.neon');

		$container = $configurator->createContainer();

		$scheduler = $container->getByType(Scheduler::class);

		self::assertFalse($container->hasService('orisai.scheduler.job.0'));
		$job1 = $container->getService('orisai.scheduler.job.1');
		self::assertInstanceOf(TestJob::class, $job1);
		$job2 = $container->getService('orisai.scheduler.job.2');
		self::assertInstanceOf(TestJob::class, $job2);

		self::assertSame(0, $job1->executions);
		self::assertSame(0, $job2->executions);

		$result = $scheduler->run();

		self::assertCount(2, $result->getJobSummaries());

		self::assertSame(1, $job1->executions);
		self::assertSame(1, $job2->executions);
	}

	public function testInvalidTimeZone(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.invalidTimeZone.neon');

		$this->expectException(InvalidConfigurationException::class);
		$this->expectExceptionMessage(
			"Failed assertion 'Valid timezone' for item 'orisai.scheduler › jobs › 0 › timeZone' with value 'invalid'.",
		);

		$configurator->createContainer();
	}

	public function testExecutorBasic(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.executor.basic.neon');

		$container = $configurator->createContainer();

		$scheduler = $container->getByType(Scheduler::class);

		$service = $container->getService('service');
		self::assertInstanceOf(TestService::class, $service);
		$job1 = $container->getService('orisai.scheduler.job.1');
		self::assertInstanceOf(TestJob::class, $job1);
		$job2 = $container->getService('orisai.scheduler.job.2');
		self::assertInstanceOf(TestJob::class, $job2);

		self::assertNull($container->getByType(TestJob::class, false));
		self::assertNull($container->getByType(CallbackJob::class, false));

		self::assertSame(0, $service->executions);
		self::assertSame(0, $job1->executions);
		self::assertSame(0, $job2->executions);

		$result = $scheduler->run();

		self::assertCount(4, $result->getJobSummaries());

		self::assertSame(2, $service->executions);
		self::assertSame(1, $job1->executions);
		self::assertSame(0, $job2->executions);
	}

	public function testExecutorProcess(): void
	{
		if (!function_exists('proc_open')) {
			self::markTestSkipped('proc_* functions are required for parallelism testing');
		}

		$container = SchedulerExtensionProcessSetup::create();

		$scheduler = $container->getByType(Scheduler::class);

		$service = $container->getService('service');
		self::assertInstanceOf(TestService::class, $service);
		$job1 = $container->getService('orisai.scheduler.job.1');
		self::assertInstanceOf(TestJob::class, $job1);
		$job2 = $container->getService('orisai.scheduler.job.2');
		self::assertInstanceOf(TestJob::class, $job2);

		$result = $scheduler->run();

		// Can't test the same way as basic executor, we are in different process
		self::assertCount(2, $result->getJobSummaries());
	}

	public function testRunEvents(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.runEvents.neon');

		$container = $configurator->createContainer();

		$scheduler = $container->getByType(Scheduler::class);
		$recorder = $container->getByType(TestEventRecorder::class);

		self::assertSame([], $recorder->records);

		$scheduler->run();
		self::assertSame(
			[
				'before run',
				'before run',
				'before run',
				'before run',
				'after run',
				'after run',
				'after run',
				'after run',
			],
			$recorder->records,
		);
	}

	public function testLockedJobEvents(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.lockedJobEvents.neon');

		$deprecations = [];
		set_error_handler(static function (int $errno, string $message) use (&$deprecations): bool {
			if ($errno === E_USER_DEPRECATED) {
				$deprecations[] = $message;

				return true;
			}

			return false;
		});

		try {
			$container = $configurator->createContainer();
		} finally {
			restore_error_handler();
		}

		self::assertNotEmpty(
			array_filter(
				$deprecations,
				static fn (string $message): bool => str_contains($message, 'events.lockedJob')
					&& str_contains($message, 'afterJob'),
			),
			'Configuring events.lockedJob should trigger a deprecation warning pointing to afterJob',
		);

		$scheduler = $container->getByType(Scheduler::class);
		$lockFactory = $container->getByType(LockFactory::class);
		$recorder = $container->getByType(TestEventRecorder::class);

		$scheduler->run();
		self::assertSame([], $recorder->records);

		$lock = $lockFactory->createLock('Orisai.Scheduler.Job/jobName');
		self::assertTrue($lock->acquire());

		$scheduler->run();
		self::assertSame(
			[
				'locked job',
				'locked job',
				'locked job',
				'locked job',
			],
			$recorder->records,
		);
	}

	public function testJobEvents(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.jobEvents.neon');

		$container = $configurator->createContainer();

		$scheduler = $container->getByType(Scheduler::class);
		$recorder = $container->getByType(TestEventRecorder::class);

		self::assertSame([], $recorder->records);

		$scheduler->run();
		self::assertSame(
			[
				'before job',
				'before job',
				'before job',
				'before job',
				'after job',
				'after job',
				'after job',
				'after job',
			],
			$recorder->records,
		);
	}

	public function testErrorHandlerCustom(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.errorHandler.custom.neon');

		$container = $configurator->createContainer();

		$scheduler = $container->getByType(Scheduler::class);

		$logger = $container->getByType(TestSchedulerLogger::class);
		self::assertSame([], $logger->records);

		$scheduler->run();

		self::assertEquals(
			[
				new Exception('test'),
			],
			$logger->records,
		);
	}

	public function testErrorHandlerTracy(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.errorHandler.tracy.neon');

		$container = $configurator->createContainer();

		$scheduler = $container->getByType(Scheduler::class);

		$logger = new TestLogger();
		Debugger::setLogger($logger);

		$scheduler->run();

		self::assertEquals(
			[
				new Exception('test'),
			],
			$logger->records,
		);
	}

	public function testInvalidExpression(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.invalidExpression.neon');

		$this->expectException(InvalidConfigurationException::class);
		$this->expectExceptionMessage(
			"Failed assertion 'Valid cron expression' for item 'orisai.scheduler › jobs › 0 › expression' with value 'invalid'.",
		);
		$configurator->createContainer();
	}

	/**
	 * @dataProvider provideInvalidJobDefinition
	 */
	public function testInvalidJobDefinition(string $config): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig($config);

		$this->expectException(InvalidConfigurationException::class);
		$this->expectExceptionMessage(
			"Failed assertion 'Use either 'callback' or 'job'' for item 'orisai.scheduler › jobs › example' with value object stdClass.",
		);

		$configurator->createContainer();
	}

	public function provideInvalidJobDefinition(): Generator
	{
		yield [__DIR__ . '/SchedulerExtension.invalidJobDefinition.both.neon'];
		yield [__DIR__ . '/SchedulerExtension.invalidJobDefinition.none.neon'];
	}

	public function testMaintenanceAndRegistryDisabled(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.minimal.neon');

		$container = $configurator->createContainer();

		self::assertFalse($container->hasService('orisai.scheduler.maintenanceManager'));
		self::assertFalse($container->hasService('orisai.scheduler.maintenanceChecker'));
		self::assertFalse($container->hasService('orisai.scheduler.runRegistry'));
		self::assertFalse($container->hasService('orisai.scheduler.command.status'));
	}

	public function testRunRegistryWithoutMaintenance(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.runRegistry.neon');

		$container = $configurator->createContainer();

		$registry = $container->getService('orisai.scheduler.runRegistry');
		self::assertInstanceOf(FileRunRegistry::class, $registry);

		// StatusCommand is registered (run tracking works without maintenance)
		$statusCommand = $container->getService('orisai.scheduler.command.status');
		self::assertInstanceOf(StatusCommand::class, $statusCommand);

		// No maintenance manager
		self::assertFalse($container->hasService('orisai.scheduler.maintenanceManager'));

		// Scheduler has RunRegistry but no MaintenanceManager
		$scheduler = $container->getService('orisai.scheduler.scheduler');
		self::assertInstanceOf(ManagedScheduler::class, $scheduler);
	}

	public function testMaintenanceWithFileRegistry(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.maintenance.file.neon');

		$container = $configurator->createContainer();

		$manager = $container->getService('orisai.scheduler.maintenanceManager');
		self::assertInstanceOf(MaintenanceManager::class, $manager);

		$registry = $container->getService('orisai.scheduler.runRegistry');
		self::assertInstanceOf(FileRunRegistry::class, $registry);

		$statusCommand = $container->getService('orisai.scheduler.command.status');
		self::assertInstanceOf(StatusCommand::class, $statusCommand);

		$scheduler = $container->getService('orisai.scheduler.scheduler');
		self::assertInstanceOf(ManagedScheduler::class, $scheduler);
	}

	public function testMaintenanceWithLockPoolRegistry(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.maintenance.lockPool.neon');

		$container = $configurator->createContainer();

		$manager = $container->getService('orisai.scheduler.maintenanceManager');
		self::assertInstanceOf(MaintenanceManager::class, $manager);

		$registry = $container->getService('orisai.scheduler.runRegistry');
		self::assertInstanceOf(LockPoolRunRegistry::class, $registry);
	}

	public function testMaintenanceWithCustomRunRegistry(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.maintenance.customRegistry.neon');

		$container = $configurator->createContainer();

		$manager = $container->getService('orisai.scheduler.maintenanceManager');
		self::assertInstanceOf(MaintenanceManager::class, $manager);
	}

}
