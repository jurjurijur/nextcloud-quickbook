<?php

declare(strict_types=1);

namespace OCA\QuickBook\Exception;

/**
 * Too many bookings were made for one email address.
 */
class EmailLimitException extends \RuntimeException {
}
