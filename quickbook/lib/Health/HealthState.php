<?php

declare(strict_types=1);

namespace OCA\QuickBook\Health;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Notification\Notifier;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\ServerVersion;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Persistent on/off state of the Calendar integration.
 *
 * "broken" means visitors are sent to the Calendar's own booking page instead.
 * The state records why and where the failure was detected:
 *  - SOURCE_CHECK: the self-check failed. The next passing self-check switches back on.
 *  - SOURCE_RUNTIME: a real booking request failed. Only an update (Calendar, Nextcloud or this
 *    app) or a manual check by an admin switches back on, because the self-check could not see the problem.
 *
 * The "version" kept with the state is the combination of those three versions, so any update
 * triggers a new check. The version numbers themselves never switch anything off.
 */
class HealthState {
	public const SOURCE_CHECK = 'check';
	public const SOURCE_RUNTIME = 'runtime';

	/** Unexplained booking failures from this many different networks within an hour switch off */
	public const RUNTIME_FAILURE_NETWORKS = 3;
	private const RUNTIME_FAILURE_WINDOW = 3600;

	private const KEY_BROKEN = 'health_broken';
	private const KEY_SOURCE = 'health_source';
	private const KEY_REASON = 'health_reason';
	private const KEY_BROKEN_AT = 'health_broken_at';
	private const KEY_BROKEN_VERSION = 'health_broken_version';
	private const KEY_CHECKED_VERSION = 'health_checked_version';
	private const KEY_LAST_FULL_CHECK = 'health_last_full_check';
	private const KEY_REPORT = 'health_report';
	private const KEY_FAILURES = 'health_runtime_failures';

	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private INotificationManager $notifications,
		private IGroupManager $groupManager,
		private ServerVersion $serverVersion,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	public function isBroken(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_BROKEN);
	}

	public function getBrokenSource(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_SOURCE);
	}

	public function getBrokenVersion(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_BROKEN_VERSION);
	}

	/** Versions the last (quick or full) check ran against, see {@see self::currentVersions()} */
	public function getCheckedVersion(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_CHECKED_VERSION);
	}

	public function getLastFullCheck(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_LAST_FULL_CHECK);
	}

	public function currentCalendarVersion(): string {
		return $this->appManager->getAppVersion('calendar', false);
	}

	public function currentNextcloudVersion(): string {
		return $this->serverVersion->getVersionString();
	}

	/** Calendar, Nextcloud and Quick Booking versions: a change means the code has to be checked again */
	public function currentVersions(): string {
		return sprintf('Calendar %s, Nextcloud %s, Quick Booking %s',
			$this->currentCalendarVersion(),
			$this->currentNextcloudVersion(),
			$this->appManager->getAppVersion(Application::APP_ID, false),
		);
	}

	public function markBroken(string $source, string $reason, ?\Throwable $exception = null): void {
		$wasBroken = $this->isBroken();
		if ($wasBroken && $this->getReason() === $reason) {
			return;
		}

		$this->logger->error('Quick Booking switched visitors to the standard Calendar booking page: ' . $reason, [
			'app' => Application::APP_ID,
			'exception' => $exception,
		]);

		// A failed real booking outranks the self-check: keep requiring a Calendar update or an admin
		if ($wasBroken && $this->getBrokenSource() === self::SOURCE_RUNTIME) {
			$source = self::SOURCE_RUNTIME;
		}

		$this->appConfig->setValueBool(Application::APP_ID, self::KEY_BROKEN, true);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_SOURCE, $source);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_REASON, mb_substr($reason, 0, 2000));
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_BROKEN_AT, $this->timeFactory->getTime());
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_BROKEN_VERSION, $this->currentVersions());

		if (!$wasBroken) {
			$this->notifyAdmins(Notifier::SUBJECT_BROKEN, ['reason' => $reason]);
		}
	}

	public function markWorking(string $versions): void {
		$wasBroken = $this->isBroken();
		$this->appConfig->setValueBool(Application::APP_ID, self::KEY_BROKEN, false);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_SOURCE, '');
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_REASON, '');
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_CHECKED_VERSION, $versions);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_FAILURES, '{}');

		if ($wasBroken) {
			$this->logger->warning('Quick Booking is working again with ' . $versions, ['app' => Application::APP_ID]);
			$this->notifyAdmins(Notifier::SUBJECT_RESTORED, ['version' => $versions]);
		}
	}

	/**
	 * Remember an unexplained runtime failure.
	 *
	 * @return int number of different networks with such failures in the last hour
	 */
	public function recordUnexplainedFailure(string $network): int {
		$now = $this->timeFactory->getTime();
		try {
			$failures = json_decode($this->appConfig->getValueString(Application::APP_ID, self::KEY_FAILURES, '{}'), true, 4, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$failures = [];
		}
		$failures = array_filter(is_array($failures) ? $failures : [], static fn ($time) => is_int($time) && $now - $time < self::RUNTIME_FAILURE_WINDOW);
		$failures[$network] = $now;
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_FAILURES, json_encode($failures, JSON_THROW_ON_ERROR));
		return count($failures);
	}

	public function setCheckedVersion(string $versions): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_CHECKED_VERSION, $versions);
	}

	/**
	 * @param list<array{name: string, status: string, detail: string}> $results
	 */
	public function saveReport(array $results): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_LAST_FULL_CHECK, $this->timeFactory->getTime());
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_REPORT, json_encode($results, JSON_THROW_ON_ERROR), lazy: true);
	}

	public function getReason(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_REASON);
	}

	/**
	 * @return array{broken: bool, source: string, reason: string, brokenAt: int, brokenVersion: string, checkedVersion: string, calendarVersion: string, nextcloudVersion: string, lastFullCheck: int, report: list<array{name: string, status: string, detail: string}>}
	 */
	public function getStatus(): array {
		try {
			$report = json_decode($this->appConfig->getValueString(Application::APP_ID, self::KEY_REPORT, '[]', lazy: true), true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$report = [];
		}
		return [
			'broken' => $this->isBroken(),
			'source' => $this->getBrokenSource(),
			'reason' => $this->getReason(),
			'brokenAt' => $this->appConfig->getValueInt(Application::APP_ID, self::KEY_BROKEN_AT),
			'brokenVersion' => $this->getBrokenVersion(),
			'checkedVersion' => $this->getCheckedVersion(),
			'calendarVersion' => $this->currentCalendarVersion(),
			'nextcloudVersion' => $this->currentNextcloudVersion(),
			'lastFullCheck' => $this->getLastFullCheck(),
			'report' => is_array($report) ? $report : [],
		];
	}

	/**
	 * @param array<string, string> $parameters
	 */
	private function notifyAdmins(string $subject, array $parameters): void {
		try {
			$previous = $this->notifications->createNotification();
			$previous->setApp(Application::APP_ID)->setObject('health', 'calendar');
			$this->notifications->markProcessed($previous);

			$adminGroup = $this->groupManager->get('admin');
			if ($adminGroup === null) {
				return;
			}
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setObject('health', 'calendar')
				->setSubject($subject, $parameters)
				->setDateTime($this->timeFactory->getDateTime());
			foreach ($adminGroup->getUsers() as $admin) {
				$notification->setUser($admin->getUID());
				$this->notifications->notify($notification);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not notify admins about the Quick Booking state', ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}
}
