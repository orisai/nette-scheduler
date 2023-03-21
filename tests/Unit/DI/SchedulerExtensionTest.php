<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Unit\DI;

use Exception;
use Generator;
use Nette\DI\InvalidConfigurationException;
use OriNette\DI\Boot\ManualConfigurator;
use OriNette\Scheduler\DI\LazyJobManager;
use Orisai\Scheduler\Command\ListCommand;
use Orisai\Scheduler\Command\RunCommand;
use Orisai\Scheduler\Command\RunJobCommand;
use Orisai\Scheduler\Command\WorkerCommand;
use Orisai\Scheduler\Executor\ProcessJobExecutor;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\ManagedScheduler;
use Orisai\Scheduler\Scheduler;
use PHPUnit\Framework\TestCase;
use Tests\OriNette\Scheduler\Doubles\TestEventRecorder;
use Tests\OriNette\Scheduler\Doubles\TestJob;
use Tests\OriNette\Scheduler\Doubles\TestLogger;
use Tests\OriNette\Scheduler\Doubles\TestSchedulerLogger;
use Tests\OriNette\Scheduler\Doubles\TestService;
use Tracy\Debugger;
use function dirname;
use function function_exists;
use function mkdir;
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

		self::assertCount(4, $result->getJobs());

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
		self::assertCount(2, $result->getJobs());
	}

	public function testEvents(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/SchedulerExtension.events.neon');

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
			"Failed assertion 'Use either 'callback' or 'assert'' for item 'orisai.scheduler › jobs › example' with value object stdClass.",
		);

		$configurator->createContainer();
	}

	public function provideInvalidJobDefinition(): Generator
	{
		yield [__DIR__ . '/SchedulerExtension.invalidJobDefinition.both.neon'];
		yield [__DIR__ . '/SchedulerExtension.invalidJobDefinition.none.neon'];
	}

}
