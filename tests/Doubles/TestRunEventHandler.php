<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Doubles;

use Orisai\Scheduler\Status\RunInfo;
use Orisai\Scheduler\Status\RunSummary;

final class TestRunEventHandler
{

	private TestEventRecorder $recorder;

	public function __construct(TestEventRecorder $recorder)
	{
		$this->recorder = $recorder;
	}

	/**
	 * @param RunInfo|RunSummary $info
	 */
	public function handle($info): void
	{
		$this->recorder->records[] = $info instanceof RunInfo
			? 'before run'
			: 'after run';
	}

	/**
	 * @param RunInfo|RunSummary $info
	 */
	public function __invoke($info): void
	{
		$this->handle($info);
	}

}
