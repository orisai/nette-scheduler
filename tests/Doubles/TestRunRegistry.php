<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Doubles;

use Orisai\Scheduler\RunRegistry\RunRegistry;

final class TestRunRegistry implements RunRegistry
{

	public function register(string $runId, int $pid): void
	{
		// noop
	}

	public function deregister(string $runId): void
	{
		// noop
	}

	public function refresh(string $runId): void
	{
		// noop
	}

	public function getActiveRuns(): array
	{
		return [];
	}

}
