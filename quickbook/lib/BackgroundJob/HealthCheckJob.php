<?php

declare(strict_types=1);

namespace OCA\QuickBook\BackgroundJob;

use OCA\QuickBook\Health\HealthChecker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Re-checks the Calendar integration every 15 minutes while it is switched off
 * (so it comes back by itself once the problem is gone), after Calendar updates,
 * and once a day otherwise.
 */
class HealthCheckJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private HealthChecker $checker,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
	}

	protected function run($argument): void {
		$this->checker->runScheduled($this->time->getTime());
	}
}
