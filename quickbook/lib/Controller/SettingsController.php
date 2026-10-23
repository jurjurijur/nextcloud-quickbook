<?php

declare(strict_types=1);

namespace OCA\QuickBook\Controller;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Health\HealthChecker;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private HealthChecker $checker,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Admin only (no NoAdminRequired)
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings/check')]
	public function runCheck(): JSONResponse {
		return new JSONResponse($this->checker->run(true));
	}
}
