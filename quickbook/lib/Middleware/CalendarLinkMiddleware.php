<?php

declare(strict_types=1);

namespace OCA\QuickBook\Middleware;

use OCA\QuickBook\Calendar\CalendarBridge;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Sends visitors of the Calendar's own booking link (/apps/calendar/appointment/{token})
 * to the Quick Booking page.
 *
 * This makes the Calendar link the one to share: when Quick Booking is switched off by the
 * self-check, disabled, or removed, the same link simply shows the Calendar's booking page.
 *
 * Registered for all apps, so it must never break other pages: anything unexpected leaves
 * the Calendar's response untouched. Services are looked up only when needed for that reason.
 */
class CalendarLinkMiddleware extends Middleware {
	private const CALENDAR_CONTROLLER = 'OCA\Calendar\Controller\AppointmentController';
	private const CALENDAR_METHOD = 'show';
	/** Added to links that must show the Calendar page, see {@see CalendarBridge::fallbackUrl()} */
	public const FALLBACK_PARAMETER = 'qb';

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if ($methodName !== self::CALENDAR_METHOD || get_class($controller) !== self::CALENDAR_CONTROLLER) {
			return $response;
		}

		try {
			if ($response->getStatus() !== Http::STATUS_OK) {
				return $response;
			}
			$request = Server::get(IRequest::class);
			$token = $request->getParam('token');
			if (!is_string($token) || $token === '' || $request->getParam(self::FALLBACK_PARAMETER) === 'fallback') {
				return $response;
			}

			// Throws when the schedule does not exist or Quick Booking is switched off
			Server::get(CalendarBridge::class)->findSchedule($token);
			return new RedirectResponse(Server::get(IURLGenerator::class)->linkToRoute('quickbook.page.show', ['token' => $token]));
		} catch (\Throwable $e) {
			try {
				Server::get(LoggerInterface::class)->debug('Quick Booking left the Calendar booking page as it is', ['app' => 'quickbook', 'exception' => $e]);
			} catch (\Throwable) {
			}
			return $response;
		}
	}
}
