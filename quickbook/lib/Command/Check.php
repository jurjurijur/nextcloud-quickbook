<?php

declare(strict_types=1);

namespace OCA\QuickBook\Command;

use OCA\QuickBook\Health\HealthChecker;
use OCA\QuickBook\Health\HealthState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Check extends Command {
	public function __construct(
		private HealthChecker $checker,
		private HealthState $state,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('quickbook:check')
			->setDescription('Check that Quick Booking works with the installed Calendar app and Nextcloud, and switch it back on when it does')
			->addOption('status', null, InputOption::VALUE_NONE, 'Only show the current state, do not run the check');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$input->getOption('status')) {
			$report = $this->checker->run(true);
			$table = new Table($output);
			$table->setHeaders(['Check', 'Result', 'Detail']);
			foreach ($report['results'] as $result) {
				$table->addRow([$result['name'], strtoupper($result['status']), $result['detail']]);
			}
			$table->render();
		}

		$status = $this->state->getStatus();
		$output->writeln('Calendar version: ' . $status['calendarVersion'] . ', Nextcloud version: ' . $status['nextcloudVersion']);
		if ($status['broken']) {
			$output->writeln('<error>Quick Booking is OFF, visitors are sent to the standard Calendar booking page.</error>');
			$output->writeln('Reason: ' . $status['reason']);
			return 1;
		}
		$output->writeln('<info>Quick Booking is ON.</info>');
		return 0;
	}
}
