<?php

declare(strict_types=1);

namespace OCA\QuickBook\Calendar;

/**
 * Read-only copy of a Calendar appointment schedule ("appointment config").
 *
 * Everything outside the Calendar namespace of this app works with this class,
 * never with the Calendar app's own entity.
 */
final class Schedule {
	/**
	 * @param array<string, mixed> $availability decoded availability rules ({timezoneId, slots})
	 * @param object $config the Calendar's AppointmentConfig, only to be used by CalendarBridge
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $token,
		public readonly string $userId,
		public readonly string $name,
		public readonly string $description,
		public readonly string $location,
		public readonly bool $isPublic,
		public readonly int $lengthSeconds,
		public readonly ?int $start,
		public readonly ?int $end,
		public readonly ?int $futureLimit,
		public readonly int $timeBeforeNextSlot,
		public readonly array $availability,
		public readonly object $config,
	) {
	}

	/**
	 * Weekdays that have availability rules, as two letter codes (MO, TU, ...)
	 *
	 * @return list<string>
	 */
	public function availableWeekdays(): array {
		$slots = $this->availability['slots'] ?? [];
		if (!is_array($slots)) {
			return [];
		}
		return array_values(array_map('strval', array_keys(array_filter($slots, static fn ($daySlots) => !empty($daySlots)))));
	}

	public function availabilityTimezone(): ?string {
		$tz = $this->availability['timezoneId'] ?? null;
		return is_string($tz) && $tz !== '' ? $tz : null;
	}
}
