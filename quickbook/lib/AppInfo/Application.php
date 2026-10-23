<?php

declare(strict_types=1);

namespace OCA\QuickBook\AppInfo;

use OCA\QuickBook\Middleware\CalendarLinkMiddleware;
use OCA\QuickBook\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'quickbook';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Global: it acts on the Calendar app's booking page
		$context->registerMiddleware(CalendarLinkMiddleware::class, true);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
