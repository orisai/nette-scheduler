<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Unit\DI;

use Cron\CronExpression;
use DateTimeZone;
use OriNette\DI\Boot\ManualConfigurator;
use OriNette\Scheduler\DI\LazyJobManager;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Manager\JobManager;
use PHPUnit\Framework\TestCase;
use Tests\OriNette\Scheduler\Doubles\TestJob;
use function dirname;
use function method_exists;
use function mkdir;
use const PHP_VERSION_ID;

final class LazyJobManagerTest extends TestCase
{

	private string $rootDir;

	protected function setUp(): void
	{
		parent::setUp();

		$this->rootDir = dirname(__DIR__, 3);
		if (PHP_VERSION_ID < 8_01_00) {
			@mkdir("$this->rootDir/var/build");
		}

		// Compat - orisai/scheduler v1
		if (method_exists(JobManager::class, 'getPairs')) {
			self::markTestSkipped('This test is for orisai/scheduler v2');
		}
	}

	public function test(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/LazyJobManager.neon');

		$container = $configurator->createContainer();

		$manager = $container->getService('orisai.scheduler.jobManager');
		self::assertInstanceOf(LazyJobManager::class, $manager);

		self::assertSame($manager->getJobSchedules(), $manager->getJobSchedules());

		// Trigger internal initialization
		foreach ($manager->getJobSchedules() as $jobSchedule) {
			$jobSchedule->getJob();
		}

		self::assertEquals(
			[
				'job1' => JobSchedule::create(
					new TestJob('job1'),
					new CronExpression('1 * * * *'),
					0,
					null,
				),
				'job2' => JobSchedule::create(
					new TestJob('job2'),
					new CronExpression('2 * * * *'),
					10,
					new DateTimeZone('Europe/Prague'),
				),
				3 => JobSchedule::create(
					new TestJob('job3'),
					new CronExpression('3 * * * *'),
					30,
					new DateTimeZone('UTC'),
				),
			],
			$manager->getJobSchedules(),
		);

		self::assertNull($manager->getJobSchedule(42));
		foreach ($manager->getJobSchedules() as $id => $schedule) {
			self::assertEquals($schedule, $manager->getJobSchedule($id));
			self::assertSame($manager->getJobSchedule($id), $manager->getJobSchedule($id));
		}
	}

	public function testEmpty(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/LazyJobManager.empty.neon');

		$container = $configurator->createContainer();

		$manager = $container->getService('orisai.scheduler.jobManager');
		self::assertInstanceOf(LazyJobManager::class, $manager);

		self::assertSame([], $manager->getJobSchedules());
		self::assertNull($manager->getJobSchedule(0));
		self::assertNull($manager->getJobSchedule('id'));
		self::assertNull($manager->getJobSchedule(42));
	}

	public function testInvalidJobType(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/LazyJobManager.invalidType.neon');

		$container = $configurator->createContainer();

		$manager = $container->getService('orisai.scheduler.jobManager');
		self::assertInstanceOf(LazyJobManager::class, $manager);

		$schedule = $manager->getJobSchedule('job1');
		self::assertNotNull($schedule);

		$this->expectException(InvalidArgument::class);
		$this->expectExceptionMessage(
			<<<'MSG'
Context: Service 'app.job1' returns instance of stdClass.
Problem: OriNette\Scheduler\DI\LazyJobManager supports only instances of
         Orisai\Scheduler\Job\Job.
Solution: Remove service from LazyJobManager or make the service return
          supported object type.
MSG,
		);

		$schedule->getJob();
	}

}
