<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Doubles;

use Exception;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;

final class TestLockedJobEventHandler
{

	private TestEventRecorder $recorder;

	public function __construct(TestEventRecorder $recorder)
	{
		$this->recorder = $recorder;
	}

	public function handle(JobInfo $info, JobResult $result): void
	{
		if ($result->getState() !== JobResultState::lock()) {
			throw new Exception('This handler is for locked jobs only');
		}

		$this->recorder->records[] = 'locked job';
	}

	public function __invoke(JobInfo $info, JobResult $result): void
	{
		$this->handle($info, $result);
	}

}
