<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Doubles;

use Exception;

final class TestService
{

	public int $executions = 0;

	public function do(): void
	{
		$this->executions++;
	}

	public function error(): void
	{
		throw new Exception('test');
	}

	public function __invoke(): void
	{
		$this->executions++;
	}

}
