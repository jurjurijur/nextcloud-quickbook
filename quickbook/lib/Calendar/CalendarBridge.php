<?php

declare(strict_types=1);

namespace OCA\QuickBook\Calendar;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Exception\CalendarUnavailableException;
use OCA\QuickBook\Exception\ScheduleNotFoundException;
use OCA\QuickBook\Exception\SlotUnavailableException;
use OCA\QuickBook\Exception\TemporaryFailureException;
use OCA\QuickBook\Health\HealthState;
use OCA\QuickBook\Middleware\CalendarLinkMiddleware;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The only place that touches the Calendar app's internal code.
 *
 * Every call goes through {@see self::call()}, which:
 *  - refuses to run while the integration is marked broken,
 *  - re-verifies the contract once whenever the Calendar version changes,
 *  - on an unexpected error (missing class/method, wrong types, ...) re-checks the
 *    contract: if it fails, the Calendar code changed and the integration is switched off;
 *    if it holds, the error may have been caused by the request itself, so it only
 *    switches off when such errors come from several networks within an hour.
 * Ordinary failures (slot taken, database hiccup) never switch anything off.
 */
class CalendarBridge {
	public function __construct(
		private ContainerInterface $container,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
		private IDBConnection $db,
		private HealthState $health,
		private ContractCheck $contract,
		private IRequest $request,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether bookings can currently go through this app.
	 */
	public function isUsable(): bool {
		if (!$this->appManager->isEnabledForAnyone('calendar')) {
			$this->health->markBroken(HealthState::SOURCE_CHECK, 'The Calendar app is not enabled');
			return false;
		}

		$versions = $this->health->currentVersions();
		if ($versions !== $this->health->getCheckedVersion()) {
			// Calendar, Nextcloud or this app was updated (or just installed): quick contract check.
			// The background job runs the full self-check soon after.
			$this->appManager->loadApp('calendar');
			$problems = $this->contract->run();
			if ($problems !== []) {
				$this->health->markBroken(HealthState::SOURCE_CHECK, "Not compatible ($versions): " . implode('; ', $problems));
				$this->health->setCheckedVersion($versions);
				return false;
			}
			if (!$this->health->isBroken() || $this->health->getBrokenSource() === HealthState::SOURCE_RUNTIME) {
				$this->health->markWorking($versions);
			} else {
				// Switched off by a failed self-check: only a passing full self-check switches on again
				$this->health->setCheckedVersion($versions);
			}
		}

		return !$this->health->isBroken();
	}

	/**
	 * @throws ScheduleNotFoundException|CalendarUnavailableException|TemporaryFailureException
	 */
	public function findSchedule(string $token): Schedule {
		return $this->call('find schedule', function () use ($token): Schedule {
			$service = $this->get(ContractCheck::CONFIG_SERVICE);
			try {
				$config = $service->findByToken($token);
			} catch (\Exception $e) {
				if (is_a($e, ContractCheck::CLIENT_EXCEPTION)) {
					throw new ScheduleNotFoundException('No schedule for token', 0, $e);
				}
				throw $e;
			}
			return $this->toSchedule($config);
		});
	}

	/**
	 * Free slots according to the Calendar's own rules (availability, buffers,
	 * daily limit, conflicts, minimum notice, future limit).
	 *
	 * @return list<array{start: int, end: int}>
	 * @throws CalendarUnavailableException|TemporaryFailureException
	 */
	public function getAvailableSlots(Schedule $schedule, int $start, int $end): array {
		return $this->call('get available slots', function () use ($schedule, $start, $end): array {
			$intervals = $this->get(ContractCheck::BOOKING_SERVICE)->getAvailableSlots($schedule->config, $start, $end);
			return $this->intervalsToArray($intervals);
		});
	}

	/**
	 * Book a slot and confirm it right away. The Calendar app writes the event,
	 * creates a Talk room if configured, and sends the booking information emails.
	 *
	 * @throws SlotUnavailableException|CalendarUnavailableException|TemporaryFailureException
	 */
	public function bookConfirmed(Schedule $schedule, int $start, int $end, string $timezone, string $displayName, string $email, string $description): void {
		$this->call('book appointment', function () use ($schedule, $start, $end, $timezone, $displayName, $email, $description): void {
			$mapper = $this->get(ContractCheck::BOOKING_MAPPER);
			$service = $this->get(ContractCheck::BOOKING_SERVICE);

			$booking = $this->newBooking($schedule, $start, $end, $timezone, $displayName, $email, $description === '' ? null : $description);
			$booking->setCreatedAt($this->timeFactory->getTime());
			$mapper->insert($booking);

			try {
				$service->confirmBooking($booking, $schedule->config);
			} catch (\Throwable $e) {
				try {
					$mapper->delete($booking);
				} catch (\Throwable $deleteError) {
					// Unconfirmed bookings are removed by the Calendar's cleanup job after a day
					$this->logger->warning('Could not remove failed booking', ['app' => Application::APP_ID, 'exception' => $deleteError]);
				}
				if (is_a($e, ContractCheck::NO_SLOT_EXCEPTION)) {
					throw new SlotUnavailableException('Slot is not available any more', 0, $e);
				}
				throw $e;
			}
		});
	}

	/**
	 * URL that always shows the Calendar's own booking page (with email confirmation).
	 * The marker stops {@see CalendarLinkMiddleware} from redirecting it back to Quick Booking.
	 */
	public function fallbackUrl(string $token): ?string {
		try {
			if (!$this->appManager->isEnabledForAnyone('calendar')) {
				return null;
			}
			return $this->urlGenerator->linkToRouteAbsolute(ContractCheck::FALLBACK_ROUTE, ['token' => $token, CalendarLinkMiddleware::FALLBACK_PARAMETER => 'fallback']);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Full self-check against the real Calendar code. Never changes the health state itself.
	 *
	 * @return list<array{name: string, status: string, detail: string}>
	 */
	public function selfTest(): array {
		$results = [];
		$add = static function (string $name, string $status, string $detail = '') use (&$results): void {
			$results[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
		};
		$describe = static fn (\Throwable $e): string => get_class($e) . ': ' . $e->getMessage();

		if (!$this->appManager->isEnabledForAnyone('calendar')) {
			$add('Calendar app enabled', 'fail', 'The Calendar app is not enabled');
			return $results;
		}
		$add('Calendar app enabled', 'pass', 'version ' . $this->health->currentCalendarVersion());

		try {
			$this->appManager->loadApp('calendar');
			$problems = $this->contract->run();
		} catch (\Throwable $e) {
			$problems = [$describe($e)];
		}
		if ($problems !== []) {
			$add('Calendar code contract', 'fail', implode('; ', $problems));
			return $results;
		}
		$add('Calendar code contract', 'pass', 'all classes, methods and fields found');

		try {
			$bookingService = $this->get(ContractCheck::BOOKING_SERVICE);
			$configService = $this->get(ContractCheck::CONFIG_SERVICE);
			$mapper = $this->get(ContractCheck::BOOKING_MAPPER);
			$add('Calendar services', 'pass', 'booking service, schedule service and booking storage load');
		} catch (\Throwable $e) {
			$add('Calendar services', 'fail', $describe($e));
			return $results;
		}

		$fallback = null;
		try {
			$fallback = $this->urlGenerator->linkToRoute(ContractCheck::FALLBACK_ROUTE, ['token' => 'selftest']);
		} catch (\Throwable $e) {
		}
		// Not fatal: only the fallback for visitors is affected
		$add('Standard booking page (fallback)', $fallback !== null ? 'pass' : 'warn', $fallback ?? 'route ' . ContractCheck::FALLBACK_ROUTE . ' not found');

		$token = $this->findAnyScheduleToken();
		if ($token === null) {
			$add('Slot lookup', 'skip', 'no appointment schedule exists yet');
			$add('Booking storage', 'skip', 'no appointment schedule exists yet');
			return $results;
		}

		try {
			$schedule = $this->toSchedule($configService->findByToken($token));
			$now = $this->timeFactory->getTime();
			$slots = $this->intervalsToArray($bookingService->getAvailableSlots($schedule->config, $now, $now + 7 * 86400));
			$add('Slot lookup', 'pass', sprintf('schedule "%s": %d free slots in the next 7 days', $schedule->name, count($slots)));
		} catch (\Throwable $e) {
			$add('Slot lookup', 'fail', $describe($e));
			return $results;
		}

		try {
			$booking = $this->newBooking($schedule, $now, $now + 60, 'UTC', 'Quick Booking self-check', 'selfcheck@example.invalid', null);
			// createdAt 0 makes the Calendar's cleanup job remove it if the delete below fails
			$booking->setCreatedAt(0);
			$mapper->insert($booking);
			$mapper->delete($booking);
			$add('Booking storage', 'pass', 'test booking stored and removed');
		} catch (\Throwable $e) {
			$add('Booking storage', 'fail', $describe($e));
		}

		return $results;
	}

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	private function call(string $name, callable $operation): mixed {
		if (!$this->isUsable()) {
			throw new CalendarUnavailableException('The Calendar integration is switched off');
		}

		try {
			$this->appManager->loadApp('calendar');
			return $operation();
		} catch (ScheduleNotFoundException|SlotUnavailableException $e) {
			throw $e;
		} catch (\Error|\ReflectionException|\BadFunctionCallException|ContainerExceptionInterface|IncompatibleCalendarException $e) {
			$reason = sprintf('Could not %s: %s: %s', $name, get_class($e), $e->getMessage());

			try {
				$problems = $this->contract->run();
			} catch (\Throwable $checkError) {
				$problems = [get_class($checkError) . ': ' . $checkError->getMessage()];
			}
			if ($problems !== []) {
				// The Calendar code changed: the self-check will switch back on once it is fixed
				$this->health->markBroken(HealthState::SOURCE_CHECK, $reason . ' (Calendar not compatible: ' . implode('; ', $problems) . ')', $e);
				throw new CalendarUnavailableException('The Calendar integration broke', 0, $e);
			}

			// Code is intact: could be this request's data. Only a pattern across networks counts.
			$networks = $this->health->recordUnexplainedFailure($this->clientNetwork());
			$this->logger->error("Quick Booking could not $name (unexpected error, $networks network(s) affected in the last hour)", ['app' => Application::APP_ID, 'exception' => $e]);
			if ($networks >= HealthState::RUNTIME_FAILURE_NETWORKS) {
				$this->health->markBroken(HealthState::SOURCE_RUNTIME, $reason . " (bookings from $networks different networks failed within an hour)", $e);
				throw new CalendarUnavailableException('The Calendar integration broke', 0, $e);
			}
			throw new TemporaryFailureException("Could not $name", 0, $e);
		} catch (\Throwable $e) {
			$this->logger->error("Quick Booking could not $name", ['app' => Application::APP_ID, 'exception' => $e]);
			throw new TemporaryFailureException("Could not $name", 0, $e);
		}
	}

	/**
	 * Visitor's network (IPv4 /24, IPv6 /64) as a short hash, so failures from one
	 * attacker count once without storing IP addresses.
	 */
	private function clientNetwork(): string {
		$ip = $this->request->getRemoteAddress();
		$packed = @inet_pton($ip);
		if ($packed === false) {
			return substr(hash('sha256', $ip), 0, 16);
		}
		$prefix = strlen($packed) === 4 ? substr($packed, 0, 3) : substr($packed, 0, 8);
		return substr(hash('sha256', $prefix), 0, 16);
	}

	private function get(string $class): object {
		$service = $this->container->get($class);
		if (!is_a($service, $class)) {
			throw new IncompatibleCalendarException("Container returned " . get_debug_type($service) . " for $class");
		}
		return $service;
	}

	private function newBooking(Schedule $schedule, int $start, int $end, string $timezone, string $displayName, string $email, ?string $description): object {
		$class = ContractCheck::BOOKING;
		$booking = new $class();
		$booking->setApptConfigId($schedule->id);
		$booking->setToken($this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC));
		$booking->setDisplayName($displayName);
		$booking->setDescription($description);
		$booking->setEmail($email);
		$booking->setStart($start);
		$booking->setEnd($end);
		$booking->setTimezone($timezone);
		$booking->setConfirmed(false);
		return $booking;
	}

	private function toSchedule(mixed $config): Schedule {
		if (!is_a($config, ContractCheck::CONFIG)) {
			throw new IncompatibleCalendarException('Expected an appointment config, got ' . get_debug_type($config));
		}
		try {
			$availability = $config->getAvailability() === null ? [] : $config->getAvailabilityAsArray();
		} catch (\JsonException) {
			$availability = [];
		}
		return new Schedule(
			id: (int)$config->getId(),
			userId: (string)$config->getUserId(),
			name: (string)$config->getName(),
			description: (string)($config->getDescription() ?? ''),
			location: (string)($config->getLocation() ?? ''),
			lengthSeconds: (int)$config->getLength(),
			start: $config->getStart() === null ? null : (int)$config->getStart(),
			end: $config->getEnd() === null ? null : (int)$config->getEnd(),
			futureLimit: $config->getFutureLimit() === null ? null : (int)$config->getFutureLimit(),
			timeBeforeNextSlot: (int)($config->getTimeBeforeNextSlot() ?? 0),
			availability: is_array($availability) ? $availability : [],
			config: $config,
		);
	}

	/**
	 * @return list<array{start: int, end: int}>
	 */
	private function intervalsToArray(mixed $intervals): array {
		if (!is_array($intervals)) {
			throw new IncompatibleCalendarException('getAvailableSlots() returned ' . get_debug_type($intervals));
		}
		$slots = [];
		foreach ($intervals as $interval) {
			if (!is_a($interval, ContractCheck::INTERVAL)) {
				throw new IncompatibleCalendarException('getAvailableSlots() returned a ' . get_debug_type($interval));
			}
			$start = $interval->getStart();
			$end = $interval->getEnd();
			if (!is_int($start) || !is_int($end) || $end <= $start) {
				throw new IncompatibleCalendarException('getAvailableSlots() returned an invalid interval');
			}
			$slots[] = ['start' => $start, 'end' => $end];
		}
		usort($slots, static fn (array $a, array $b) => $a['start'] <=> $b['start']);
		return $slots;
	}

	private function findAnyScheduleToken(): ?string {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('token')->from('calendar_appt_configs')->setMaxResults(1);
			$result = $qb->executeQuery();
			$token = $result->fetchOne();
			$result->closeCursor();
			return is_string($token) ? $token : null;
		} catch (\Throwable $e) {
			$this->logger->info('Quick Booking self-check could not look up a schedule', ['app' => Application::APP_ID, 'exception' => $e]);
			return null;
		}
	}
}
