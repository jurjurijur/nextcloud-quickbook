<?php

declare(strict_types=1);

namespace OCA\QuickBook\Notification;

use OCA\QuickBook\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public const SUBJECT_BROKEN = 'broken';
	public const SUBJECT_RESTORED = 'restored';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Quick Booking');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();

		switch ($notification->getSubject()) {
			case self::SUBJECT_BROKEN:
				$notification->setParsedSubject($l->t('Quick Booking switched visitors to the standard Calendar booking page'))
					->setParsedMessage($l->t('Something in the Calendar app or Nextcloud no longer works as Quick Booking expects: %s', [(string)($params['reason'] ?? '')]));
				break;
			case self::SUBJECT_RESTORED:
				$notification->setParsedSubject($l->t('Quick Booking works again (%s)', [(string)($params['version'] ?? '')]));
				break;
			default:
				throw new UnknownNotificationException();
		}

		$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')))
			->setLink($this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]));
		return $notification;
	}
}
