<?php

declare(strict_types=1);

namespace OCA\QuickBook\Exception;

/**
 * An unexpected, probably temporary, error (e.g. database) occurred.
 */
class TemporaryFailureException extends \RuntimeException {
}
