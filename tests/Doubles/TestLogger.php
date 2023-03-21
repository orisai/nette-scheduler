<?php declare(strict_types = 1);

namespace Tests\OriNette\Scheduler\Doubles;

use Tracy\ILogger;

final class TestLogger implements ILogger
{

	/** @var array<mixed> */
	public array $records = [];

	/**
	 * @param mixed $value
	 * @param string $level
	 *
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 */
	public function log($value, $level = self::INFO): void
	{
		$this->records[] = $value;
	}

}
