<?php declare(strict_types = 1);

namespace OriNette\Scheduler\DI;

use Cron\CronExpression;
use Nette\DI\Container;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Exceptions\Message;
use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Manager\JobManager;
use Orisai\Utils\Reflection\Classes;
use function get_class;

/**
 * @internal
 */
final class LazyJobManager implements JobManager
{

	private Container $container;

	/** @var array<int|string, string> */
	private array $jobs;

	/** @var array<int|string, string> */
	private array $expressions;

	/**
	 * @param array<int|string, string> $jobs
	 * @param array<int|string, string> $expressions
	 */
	public function __construct(Container $container, array $jobs, array $expressions)
	{
		$this->container = $container;
		$this->jobs = $jobs;
		$this->expressions = $expressions;
	}

	public function getPair($id): ?array
	{
		$job = $this->jobs[$id] ?? null;

		if ($job === null) {
			return null;
		}

		$jobInst = $this->container->getService($job);
		if (!$jobInst instanceof Job) {
			$this->throwInvalidServiceType($job, Job::class, $jobInst);
		}

		return [
			$jobInst,
			new CronExpression($this->expressions[$id]),
		];
	}

	public function getPairs(): array
	{
		$pairs = [];
		foreach ($this->jobs as $id => $job) {
			$jobInst = $this->container->getService($job);
			if (!$jobInst instanceof Job) {
				$this->throwInvalidServiceType($job, Job::class, $jobInst);
			}

			$pairs[$id] = [
				$jobInst,
				new CronExpression($this->expressions[$id]),
			];
		}

		return $pairs;
	}

	public function getExpressions(): array
	{
		$expressions = [];
		foreach ($this->expressions as $id => $expression) {
			$expressions[$id] = new CronExpression($expression);
		}

		return $expressions;
	}

	/**
	 * @param class-string $expectedType
	 * @return never
	 */
	private function throwInvalidServiceType(string $serviceName, string $expectedType, object $service): void
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

}
