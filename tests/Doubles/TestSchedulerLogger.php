<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Doubles;

use Throwable;

final class TestSchedulerLogger
{

	/** @var array<mixed> */
	public array $records = [];

	public function log(Throwable $throwable): void
	{
		$this->records[] = $throwable;
	}

}
