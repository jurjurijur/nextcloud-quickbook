<?php

declare(strict_types=1);

namespace OCA\QuickBook\Settings;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Calendar\CalendarBridge;
use OCA\QuickBook\Exception\CalendarUnavailableException;
use OCA\QuickBook\Exception\TemporaryFailureException;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings {
	public function __construct(
		private CalendarBridge $calendar,
		private IURLGenerator $urlGenerator,
		private ?string $userId,
	) {
	}

	public function getForm(): TemplateResponse {
		$schedules = [];
		$available = true;
		try {
			foreach ($this->calendar->listSchedulesForUser((string)$this->userId) as $schedule) {
				$schedules[] = [
					'id' => $schedule->id,
					'name' => $schedule->name,
					'isPublic' => $schedule->isPublic,
					'url' => $this->calendar->shareUrl($schedule->token)
						?? $this->urlGenerator->linkToRouteAbsolute('quickbook.page.show', ['token' => $schedule->token]),
					'calendarUrl' => $this->calendar->fallbackUrl($schedule->token),
				];
			}
		} catch (CalendarUnavailableException|TemporaryFailureException) {
			$available = false;
		}

		Util::addScript(Application::APP_ID, 'settings');
		Util::addStyle(Application::APP_ID, 'settings');
		return new TemplateResponse(Application::APP_ID, 'settings-personal', [
			'available' => $available,
			'schedules' => $schedules,
			'calendarUrl' => $this->urlGenerator->linkToRoute('calendar.view.index'),
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
