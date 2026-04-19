<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Doubles;

use Orisai\Scheduler\Maintenance\MaintenanceChecker;

final class TestMaintenanceChecker implements MaintenanceChecker
{

	public function isMaintenance(): bool
	{
		return false;
	}

}
