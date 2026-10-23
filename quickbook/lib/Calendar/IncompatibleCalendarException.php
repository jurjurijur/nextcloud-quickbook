<?php

declare(strict_types=1);

namespace OCA\QuickBook\Calendar;

/**
 * The Calendar app behaved differently from what this app was built for
 * (unexpected return value, missing class, ...).
 */
class IncompatibleCalendarException extends \RuntimeException {
}
