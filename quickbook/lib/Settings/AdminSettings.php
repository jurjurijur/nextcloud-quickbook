<?php

declare(strict_types=1);

namespace OCA\QuickBook\Settings;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Health\HealthState;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private HealthState $state,
		private IURLGenerator $urlGenerator,
		private IRequest $request,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'settings');
		Util::addStyle(Application::APP_ID, 'settings');
		// The rate limits count per address: behind a reverse proxy that Nextcloud does not
		// know, every visitor gets the proxy's (private) address and they all share one limit
		$address = $this->request->getRemoteAddress();
		$isPublic = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
		return new TemplateResponse(Application::APP_ID, 'settings-admin', [
			'status' => $this->state->getStatus(),
			'checkUrl' => $this->urlGenerator->linkToRoute('quickbook.settings.runCheck'),
			'visitorAddress' => $address,
			'visitorAddressIsPublic' => $isPublic,
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
