<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Doubles;

use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;

final class TestEventHandler
{

	private TestEventRecorder $recorder;

	public function __construct(TestEventRecorder $recorder)
	{
		$this->recorder = $recorder;
	}

	public function handle(JobInfo $info, ?JobResult $result = null): void
	{
		$this->recorder->records[] = $result === null
			? 'before job'
			: 'after job';
	}

	public function __invoke(JobInfo $info, ?JobResult $result = null): void
	{
		$this->handle($info, $result);
	}

}
