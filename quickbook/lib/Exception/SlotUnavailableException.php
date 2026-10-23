<?php

declare(strict_types=1);

namespace OCA\QuickBook\Exception;

/**
 * The requested time slot is not (or no longer) bookable.
 */
class SlotUnavailableException extends \RuntimeException {
}
