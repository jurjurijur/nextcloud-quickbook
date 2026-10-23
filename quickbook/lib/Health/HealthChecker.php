<?php

declare(strict_types=1);

namespace OCA\QuickBook\Health;

use OCA\QuickBook\Calendar\CalendarBridge;
use OCA\QuickBook\Controller\BookingApiController;
use OCA\QuickBook\Controller\PageController;
use OCA\QuickBook\Middleware\CalendarLinkMiddleware;
use OCA\QuickBook\Service\BookingFlowService;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;

/**
 * Runs the full self-check and decides whether the integration is switched on or off.
 *
 * It covers both sides that can change: the Calendar app's code, and this app's own parts
 * on the installed Nextcloud version. Version numbers alone never switch anything off.
 */
class HealthChecker {
	private const FULL_CHECK_INTERVAL = 86400;

	/** Parts of this app that must load on the installed Nextcloud version */
	private const COMPONENTS = [
		PageController::class,
		BookingApiController::class,
		BookingFlowService::class,
		CalendarLinkMiddleware::class,
	];

	public function __construct(
		private CalendarBridge $calendar,
		private HealthState $state,
		private ContainerInterface $container,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @param bool $manual run by an admin: may also switch on again after a failed real booking
	 * @return array{working: bool, note: string, results: list<array{name: string, status: string, detail: string}>}
	 */
	public function run(bool $manual): array {
		$results = array_merge($this->selfTestComponents(), $this->calendar->selfTest());
		$failed = array_values(array_filter($results, static fn (array $r) => $r['status'] === 'fail'));
		$version = $this->state->currentVersions();
		$note = '';

		if ($failed !== []) {
			$reason = 'Self-check failed: ' . implode('; ', array_map(
				static fn (array $r) => $r['name'] . ' (' . $r['detail'] . ')',
				$failed,
			));
			$this->state->markBroken(HealthState::SOURCE_CHECK, $reason);
			$this->state->setCheckedVersion($version);
		} elseif ($this->state->isBroken()
			&& $this->state->getBrokenSource() === HealthState::SOURCE_RUNTIME
			&& $this->state->getBrokenVersion() === $version
			&& !$manual) {
			$note = 'The self-check passes, but bookings from several networks failed with these versions. '
				. 'Quick Booking stays off until the Calendar app, Nextcloud or Quick Booking is updated, or an admin runs the check manually.';
		} else {
			$this->state->markWorking($version);
		}

		$this->state->saveReport($results);

		return [
			'working' => !$this->state->isBroken(),
			'note' => $note,
			'results' => $results,
		];
	}

	/**
	 * @return list<array{name: string, status: string, detail: string}>
	 */
	private function selfTestComponents(): array {
		try {
			foreach (self::COMPONENTS as $class) {
				$this->container->get($class);
			}
			$url = $this->urlGenerator->linkToRoute('quickbook.page.show', ['token' => 'selftest']);
			if (!str_contains($url, '/selftest')) {
				throw new \RuntimeException('booking page route not found');
			}
			$result = ['status' => 'pass', 'detail' => 'booking page, booking API and Calendar link redirect load'];
		} catch (\Throwable $e) {
			$result = ['status' => 'fail', 'detail' => get_class($e) . ': ' . $e->getMessage()];
		}
		return [['name' => 'Quick Booking components', ...$result]];
	}

	/**
	 * Called by the background job: only does work when there is something to find out.
	 */
	public function runScheduled(int $now): void {
		$due = $this->state->isBroken()
			|| $this->state->getCheckedVersion() !== $this->state->currentVersions()
			|| $now - $this->state->getLastFullCheck() >= self::FULL_CHECK_INTERVAL;
		if ($due) {
			$this->run(false);
		}
	}
}
