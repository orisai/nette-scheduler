<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Unit\DI;

use Cron\CronExpression;
use OriNette\DI\Boot\ManualConfigurator;
use OriNette\Scheduler\DI\LazyJobManagerV1;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Scheduler\Manager\JobManager;
use PHPUnit\Framework\TestCase;
use Tests\OriNette\Scheduler\Doubles\TestJob;
use function dirname;
use function method_exists;
use function mkdir;
use const PHP_VERSION_ID;

final class LazyJobManagerV1Test extends TestCase
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
		if (!method_exists(JobManager::class, 'getPairs')) {
			self::markTestSkipped('This test is for orisai/scheduler v1');
		}
	}

	public function test(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/LazyJobManagerV1.neon');

		$container = $configurator->createContainer();

		$manager = $container->getService('orisai.scheduler.jobManager');
		self::assertInstanceOf(LazyJobManagerV1::class, $manager);

		self::assertEquals(
			[
				'job1' => new CronExpression('1 * * * *'),
				'job2' => new CronExpression('2 * * * *'),
				3 => new CronExpression('3 * * * *'),
			],
			$manager->getExpressions(),
		);

		self::assertEquals(
			[
				'job1' => [
					new TestJob('job1'),
					new CronExpression('1 * * * *'),
				],
				'job2' => [
					new TestJob('job2'),
					new CronExpression('2 * * * *'),
				],
				3 => [
					new TestJob('job3'),
					new CronExpression('3 * * * *'),
				],
			],
			$manager->getPairs(),
		);

		self::assertNull($manager->getPair(42));
		foreach ($manager->getPairs() as $id => $pair) {
			self::assertEquals($pair, $manager->getPair($id));
		}
	}

	public function testEmpty(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/LazyJobManagerV1.empty.neon');

		$container = $configurator->createContainer();

		$manager = $container->getService('orisai.scheduler.jobManager');
		self::assertInstanceOf(LazyJobManagerV1::class, $manager);

		self::assertSame([], $manager->getExpressions());
		self::assertSame([], $manager->getPairs());
		self::assertNull($manager->getPair(0));
		self::assertNull($manager->getPair('id'));
		self::assertNull($manager->getPair(42));
	}

	public function testInvalidPair(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/LazyJobManagerV1.invalidType.neon');

		$container = $configurator->createContainer();

		$manager = $container->getService('orisai.scheduler.jobManager');
		self::assertInstanceOf(LazyJobManagerV1::class, $manager);

		$this->expectException(InvalidArgument::class);
		$this->expectExceptionMessage(
			<<<'MSG'
Context: Service 'app.job1' returns instance of stdClass.
Problem: OriNette\Scheduler\DI\LazyJobManagerV1 supports only instances of
         Orisai\Scheduler\Job\Job.
Solution: Remove service from LazyJobManagerV1 or make the service return
          supported object type.
MSG,
		);
		$manager->getPair('job1');
	}

	public function testInvalidPairs(): void
	{
		$configurator = new ManualConfigurator($this->rootDir);
		$configurator->setForceReloadContainer();
		$configurator->addConfig(__DIR__ . '/LazyJobManagerV1.invalidType.neon');

		$container = $configurator->createContainer();

		$manager = $container->getService('orisai.scheduler.jobManager');
		self::assertInstanceOf(LazyJobManagerV1::class, $manager);

		$this->expectException(InvalidArgument::class);
		$this->expectExceptionMessage(
			<<<'MSG'
Context: Service 'app.job1' returns instance of stdClass.
Problem: OriNette\Scheduler\DI\LazyJobManagerV1 supports only instances of
         Orisai\Scheduler\Job\Job.
Solution: Remove service from LazyJobManagerV1 or make the service return
          supported object type.
MSG,
		);
		$manager->getPairs();
	}

}
