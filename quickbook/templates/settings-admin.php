<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
$status = $_['status'];
$formatTime = static fn (int $ts): string => $ts > 0 ? (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i') : '–';
?>
<div id="quickbook-admin" class="section quickbook-settings">
	<h2><?php p($l->t('Quick Booking self-check')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Quick Booking uses internal code of the Calendar app. It checks after every Calendar or Nextcloud update and once a day that this still works. If something breaks, visitors are sent to the standard Calendar booking page and admins get a notification. While switched off, the check repeats every 15 minutes and switches back on when it passes.')); ?>
	</p>

	<?php if ($status['broken']): ?>
		<p class="quickbook-settings__state quickbook-settings__state--off">
			<strong><?php p($l->t('Off: visitors use the standard Calendar booking page.')); ?></strong><br>
			<?php p($status['reason']); ?>
		</p>
		<?php if ($status['source'] === 'runtime'): ?>
			<p class="settings-hint"><?php p($l->t('Bookings from several different networks failed while the self-check passed. Quick Booking switches back on after a Calendar update, or when you run the check below and it passes.')); ?></p>
		<?php endif; ?>
	<?php else: ?>
		<p class="quickbook-settings__state quickbook-settings__state--on"><strong><?php p($l->t('On: visitors book through Quick Booking.')); ?></strong></p>
	<?php endif; ?>

	<dl class="quickbook-settings__facts">
		<dt><?php p($l->t('Calendar version')); ?></dt><dd><?php p($status['calendarVersion']); ?></dd>
		<dt><?php p($l->t('Nextcloud version')); ?></dt><dd><?php p($status['nextcloudVersion']); ?></dd>
		<dt><?php p($l->t('Last full check')); ?></dt><dd><?php p($formatTime($status['lastFullCheck'])); ?></dd>
	</dl>

	<?php if (!empty($status['report'])): ?>
		<table class="quickbook-settings__report">
			<thead><tr><th><?php p($l->t('Check')); ?></th><th><?php p($l->t('Result')); ?></th><th><?php p($l->t('Detail')); ?></th></tr></thead>
			<tbody>
				<?php foreach ($status['report'] as $row): ?>
					<tr class="quickbook-settings__result--<?php p($row['status']); ?>">
						<td><?php p($row['name']); ?></td>
						<td><?php p(strtoupper($row['status'])); ?></td>
						<td><?php p($row['detail']); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p>
		<button type="button" id="quickbook-run-check" data-check-url="<?php p($_['checkUrl']); ?>"><?php p($l->t('Run self-check now')); ?></button>
		<span id="quickbook-check-status" role="status"></span>
	</p>
	<p class="settings-hint"><?php p($l->t('Also available as: occ quickbook:check')); ?></p>
	<h3><?php p($l->t('Visitor addresses (rate limit)')); ?></h3>
	<p>
		<?php p($l->t('Nextcloud sees your address as: %s', [$_['visitorAddress']])); ?>
	</p>
	<?php if (!$_['visitorAddressIsPublic']): ?>
		<p class="quickbook-settings__warning">
			<?php p($l->t('This is a private address. That is fine if you are on the same network as the server. If you opened this page from outside (for example a phone on mobile data), Nextcloud does not know your reverse proxy: all visitors then share one address, and the booking limit applies to all of them together. Add the proxy to "trusted_proxies" in config.php.')); ?>
		</p>
	<?php else: ?>
		<p class="settings-hint"><?php p($l->t('This should be the public address of the device you are using right now. If it is the address of your server or proxy instead, add the proxy to "trusted_proxies" in config.php.')); ?></p>
	<?php endif; ?>

	<p class="settings-hint"><?php p($l->t('Share the Calendar\'s own booking links ("Copy link" in the Calendar app). They open Quick Booking while it is on, and the Calendar booking page while it is off, disabled or removed. Links to /apps/quickbook/book/… only work while the app is installed.')); ?></p>
</div>
