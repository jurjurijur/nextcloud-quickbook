<?php

declare(strict_types=1);

namespace OCA\QuickBook\Migration;

use OCA\QuickBook\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Removes the per-schedule lead times stored by versions before 0.3.0.
 * They were the only per-user values of this app. Runs once; a flag records that it ran.
 */
class RemoveLeadTimes implements IRepairStep {
	private const DONE_KEY = 'lead_times_removed';

	public function __construct(
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
	) {
	}

	public function getName(): string {
		return 'Remove Quick Booking lead time settings (feature removed)';
	}

	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool(Application::APP_ID, self::DONE_KEY)) {
			return;
		}
		$this->userConfig->deleteApp(Application::APP_ID);
		$this->appConfig->setValueBool(Application::APP_ID, self::DONE_KEY, true);
	}
}
