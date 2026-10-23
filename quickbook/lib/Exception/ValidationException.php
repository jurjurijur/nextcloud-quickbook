<?php

declare(strict_types=1);

namespace OCA\QuickBook\Exception;

/**
 * Input sent by the visitor is invalid.
 */
class ValidationException extends \InvalidArgumentException {
	public function __construct(
		private string $field,
		string $message,
	) {
		parent::__construct($message);
	}

	public function getField(): string {
		return $this->field;
	}
}
