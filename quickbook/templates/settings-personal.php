<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
?>
<div id="quickbook-personal" class="section quickbook-settings">
	<h2><?php p($l->t('Quick Booking')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Visitors book your appointment schedules in one go: pick a time, enter details, confirm. No confirmation email round trip.')); ?>
		<?php p($l->t('Share the booking link: it is the same link as "Copy link" in the Calendar app. It opens Quick Booking, and the Calendar\'s own booking page whenever Quick Booking is switched off or not installed.')); ?>
	</p>

	<?php if (!$_['available']): ?>
		<p class="quickbook-settings__warning"><?php p($l->t('The Calendar app is not available right now, so your schedules cannot be shown.')); ?></p>
	<?php elseif (empty($_['schedules'])): ?>
		<p>
			<?php p($l->t('You have no appointment schedules yet.')); ?>
			<a class="quickbook-settings__link" href="<?php p($_['calendarUrl']); ?>"><?php p($l->t('Create one in the Calendar app')); ?></a>
		</p>
	<?php else: ?>
		<ul class="quickbook-settings__schedules">
			<?php foreach ($_['schedules'] as $schedule): ?>
				<li class="quickbook-settings__schedule">
					<div class="quickbook-settings__name">
						<strong><?php p($schedule['name']); ?></strong>
						<?php if (!$schedule['isPublic']): ?>
							<span class="quickbook-settings__badge"><?php p($l->t('Private link')); ?></span>
						<?php endif; ?>
					</div>
					<p class="quickbook-settings__label"><?php p($l->t('Booking link')); ?></p>
					<div class="quickbook-settings__url">
						<input type="text" readonly value="<?php p($schedule['url']); ?>" aria-label="<?php p($l->t('Booking link for %s', [$schedule['name']])); ?>">
						<button type="button" class="quickbook-copy" data-url="<?php p($schedule['url']); ?>"><?php p($l->t('Copy link')); ?></button>
					</div>
					<?php if ($schedule['calendarUrl'] !== null): ?>
						<p class="quickbook-settings__label"><?php p($l->t('Always the Calendar\'s own page (with email confirmation)')); ?></p>
						<div class="quickbook-settings__url">
							<input type="text" readonly value="<?php p($schedule['calendarUrl']); ?>" aria-label="<?php p($l->t('Calendar booking page link for %s', [$schedule['name']])); ?>">
							<button type="button" class="quickbook-copy" data-url="<?php p($schedule['calendarUrl']); ?>"><?php p($l->t('Copy link')); ?></button>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
