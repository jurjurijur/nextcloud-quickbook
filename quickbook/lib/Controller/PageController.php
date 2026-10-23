<?php

declare(strict_types=1);

namespace OCA\QuickBook\Controller;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Calendar\CalendarBridge;
use OCA\QuickBook\Exception\CalendarUnavailableException;
use OCA\QuickBook\Exception\ScheduleNotFoundException;
use OCA\QuickBook\Exception\TemporaryFailureException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private CalendarBridge $calendar,
		private IInitialState $initialState,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/book/{token}')]
	public function show(string $token): Response {
		try {
			return $this->render($token);
		} catch (\Throwable $e) {
			// Whatever broke, the Calendar's own page can still take the booking
			$fallback = $this->calendar->fallbackUrl($token);
			if ($fallback === null) {
				throw $e;
			}
			$this->logger->error('Quick Booking could not show the booking page', ['app' => Application::APP_ID, 'exception' => $e]);
			return new RedirectResponse($fallback);
		}
	}

	private function render(string $token): Response {
		try {
			$schedule = $this->calendar->findSchedule($token);
		} catch (ScheduleNotFoundException) {
			return $this->message('Appointment not found', 'This booking link does not exist (any more).', Http::STATUS_NOT_FOUND);
		} catch (CalendarUnavailableException|TemporaryFailureException) {
			$fallback = $this->calendar->fallbackUrl($token);
			if ($fallback !== null) {
				return new RedirectResponse($fallback);
			}
			return $this->message('Booking unavailable', 'Online booking is temporarily unavailable. Please try again later.', Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$now = $this->timeFactory->getTime();
		$organizer = $this->userManager->getDisplayName($schedule->userId) ?? $schedule->userId;
		// Same rule as the Calendar: "Minimum time before next slot" counts from now (and from the schedule start)
		$earliest = max($now, $schedule->start ?? 0) + $schedule->timeBeforeNextSlot;
		$latestCandidates = array_filter([
			$schedule->futureLimit !== null ? $now + $schedule->futureLimit : null,
			$schedule->end,
		], static fn ($v) => $v !== null);

		$visitor = null;
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$visitor = ['displayName' => $user->getDisplayName(), 'email' => $user->getEMailAddress() ?? ''];
		}

		$this->initialState->provideInitialState('booking', [
			'name' => $schedule->name,
			'description' => $schedule->description,
			'location' => $schedule->location,
			'organizer' => $organizer,
			'lengthSeconds' => $schedule->lengthSeconds,
			'organizerTimezone' => $schedule->availabilityTimezone(),
			'weekdays' => $schedule->availableWeekdays(),
			'earliestStart' => $earliest,
			'latestEnd' => $latestCandidates === [] ? null : min($latestCandidates),
			'slotsUrl' => $this->urlGenerator->linkToRoute('quickbook.bookingApi.slots', ['token' => $token]),
			'bookUrl' => $this->urlGenerator->linkToRoute('quickbook.bookingApi.book', ['token' => $token]),
			'fallbackUrl' => $this->calendar->fallbackUrl($token),
			'visitor' => $visitor,
		]);

		Util::addScript(Application::APP_ID, 'booking');
		Util::addStyle(Application::APP_ID, 'booking');

		$response = new PublicTemplateResponse(Application::APP_ID, 'booking');
		$response->setHeaderTitle($schedule->name);
		$response->setHeaderDetails($organizer);
		return $response;
	}

	private function message(string $title, string $text, int $status): TemplateResponse {
		$response = new TemplateResponse(Application::APP_ID, 'message', ['title' => $title, 'text' => $text], TemplateResponse::RENDER_AS_GUEST);
		$response->setStatus($status);
		return $response;
	}
}
