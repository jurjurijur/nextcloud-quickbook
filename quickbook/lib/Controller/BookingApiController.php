<?php

declare(strict_types=1);

namespace OCA\QuickBook\Controller;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Calendar\CalendarBridge;
use OCA\QuickBook\Exception\CalendarUnavailableException;
use OCA\QuickBook\Exception\EmailLimitException;
use OCA\QuickBook\Exception\ScheduleNotFoundException;
use OCA\QuickBook\Exception\SlotUnavailableException;
use OCA\QuickBook\Exception\TemporaryFailureException;
use OCA\QuickBook\Exception\ValidationException;
use OCA\QuickBook\Service\BookingFlowService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints used by the public booking page.
 *
 * Bookings are rate limited per visitor (IP address for guests, account for
 * logged-in users). Adjust without code changes in config.php:
 *   'ratelimit_overwrite' => ['quickbook.bookingapi.book' => [
 *       'anon' => ['limit' => 5, 'period' => 3600],
 *       'user' => ['limit' => 5, 'period' => 3600],
 *   ]],
 * On top of that, bookings are limited per email address (see BookingFlowService).
 */
class BookingApiController extends Controller {
	public function __construct(
		IRequest $request,
		private BookingFlowService $flow,
		private CalendarBridge $calendar,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/schedules/{token}/slots')]
	public function slots(string $token, string $date = '', string $timeZone = 'UTC'): JSONResponse {
		try {
			return new JSONResponse(['slots' => $this->flow->getSlotsForDay($token, $date, $timeZone)]);
		} catch (\Exception $e) {
			return $this->error($e, $token);
		}
	}

	#[PublicPage]
	#[AnonRateLimit(limit: 5, period: 3600)]
	#[UserRateLimit(limit: 5, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/schedules/{token}/bookings')]
	public function book(
		string $token,
		int $start,
		int $end,
		string $displayName,
		string $email,
		string $timeZone,
		string $description = '',
	): JSONResponse {
		try {
			$this->flow->book($token, $start, $end, $timeZone, $displayName, $email, $description);
		} catch (\Exception $e) {
			return $this->error($e, $token);
		}
		return new JSONResponse([], Http::STATUS_CREATED);
	}

	private function error(\Exception $e, string $token): JSONResponse {
		return match (true) {
			$e instanceof ValidationException => new JSONResponse(['error' => 'invalid', 'field' => $e->getField(), 'message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY),
			$e instanceof ScheduleNotFoundException => new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND),
			$e instanceof EmailLimitException => new JSONResponse(['error' => 'email_limit'], Http::STATUS_TOO_MANY_REQUESTS),
			$e instanceof SlotUnavailableException => new JSONResponse(['error' => 'slot_unavailable'], Http::STATUS_CONFLICT),
			$e instanceof CalendarUnavailableException => new JSONResponse(['error' => 'unavailable', 'fallbackUrl' => $this->calendar->fallbackUrl($token)], Http::STATUS_SERVICE_UNAVAILABLE),
			$e instanceof TemporaryFailureException => new JSONResponse(['error' => 'temporary'], Http::STATUS_INTERNAL_SERVER_ERROR),
			default => throw $e,
		};
	}
}
