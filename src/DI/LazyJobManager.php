<?php declare(strict_types = 1);

namespace OriNette\Scheduler\DI;

use Cron\CronExpression;
use DateTimeZone;
use Nette\DI\Container;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Exceptions\Logic\ShouldNotHappen;
use Orisai\Exceptions\Message;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Manager\JobManager;
use Orisai\Utils\Reflection\Classes;
use function get_class;

/**
 * @internal
 */
final class LazyJobManager implements JobManager
{

	private Container $container;

	/**
	 * @var array<int|string, array{
	 *     job: string,
	 *     expression: string,
	 *     repeatAfterSeconds: int<0, 30>,
	 *     timeZone: string|null,
	 * }>
	 */
	private array $jobSchedules;

	/** @var array<int|string, JobSchedule> */
	private array $resolvedJobSchedules = [];

	/**
	 * @param array<int|string, array{
	 *     job: string,
	 *     expression: string,
	 *     repeatAfterSeconds: int<0, 30>,
	 *     timeZone: string|null,
	 * }> $jobSchedules
	 */
	public function __construct(Container $container, array $jobSchedules)
	{
		$this->jobSchedules = $jobSchedules;
		$this->container = $container;
	}

	public function getJobSchedule($id): ?JobSchedule
	{
		$schedule = $this->resolvedJobSchedules[$id] ?? null;
		if ($schedule !== null) {
			return $schedule;
		}

		$rawSchedule = $this->jobSchedules[$id] ?? null;
		if ($rawSchedule === null) {
			return null;
		}

		$jobName = $rawSchedule['job'];
		$timeZone = $rawSchedule['timeZone'];
		$schedule = JobSchedule::createLazy(
			function () use ($jobName): Job {
				$job = $this->container->getService($jobName);
				if (!$job instanceof Job) {
					self::throwInvalidServiceType($jobName, Job::class, $job);
				}

				return $job;
			},
			new CronExpression($rawSchedule['expression']),
			$rawSchedule['repeatAfterSeconds'],
			$timeZone !== null ? new DateTimeZone($timeZone) : null,
		);

		unset($this->jobSchedules[$id]);

		return $this->resolvedJobSchedules[$id] = $schedule;
	}

	public function getJobSchedules(): array
	{
		// Triggers schedules initialization
		foreach ($this->jobSchedules as $id => $jobSchedule) {
			$this->getJobSchedule($id);
		}

		return $this->resolvedJobSchedules;
	}

	/**
	 * @param class-string $expectedType
	 * @return never
	 */
	private static function throwInvalidServiceType(string $serviceName, string $expectedType, object $service): void
	{
		$serviceClass = get_class($service);
		$selfClass = self::class;
		$className = Classes::getShortName($selfClass);

		$message = Message::create()
			->withContext("Service '$serviceName' returns instance of $serviceClass.")
			->withProblem("$selfClass supports only instances of $expectedType.")
			->withSolution("Remove service from $className or make the service return supported object type.");

		throw InvalidArgument::create()
			->withMessage($message);
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function getPair($id): ?array
	{
		throw ShouldNotHappen::create()
			->withMessage('This method is here just to make tooling happy');
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function getPairs(): array
	{
		throw ShouldNotHappen::create()
			->withMessage('This method is here just to make tooling happy');
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function getExpressions(): array
	{
		throw ShouldNotHappen::create()
			->withMessage('This method is here just to make tooling happy');
	}

}
