<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Doubles;

use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobLock;

final class TestJob implements Job
{

	private string $name;

	public int $executions = 0;

	public function __construct(string $name)
	{
		$this->name = $name;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function run(JobLock $lock): void
	{
		$this->executions++;
	}

}
