<?php
namespace CGM\FinancialNews\Core;

use CGM\FinancialNews\Core\Service\NewsProcessor;
use CGM\FinancialNews\Core\Repository\LogRepository;

/**
 * Background Task Scheduler using Action Scheduler.
 */
class Scheduler {

	private NewsProcessor $processor;
	private LogRepository $logger;
	private Settings $settings;

	/**
	 * Constructor.
	 */
	public function __construct( Settings $settings, NewsProcessor $processor, LogRepository $logger ) {
		$this->settings  = $settings;
		$this->processor = $processor;
		$this->logger    = $logger;
	}

	/**
	 * Register actions with WordPress.
	 */
	public function hooks() {
		// Hook recurring fetch worker.
		add_action( 'cgm_fetch_news_cron', [ $this, 'run_fetch_news' ] );

		// Hook background news item processor.
		add_action( 'cgm_process_news_item', [ $this, 'run_process_news_item' ], 10, 1 );

		// Hook into admin_init to schedule the cron if not already scheduled.
		add_action( 'admin_init', [ $this, 'schedule_cron' ] );
	}

	/**
	 * Schedule the recurring news fetch if not already registered.
	 */
	public function schedule_cron() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		// Schedule to run every 4 hours.
		if ( false === as_next_scheduled_action( 'cgm_fetch_news_cron' ) ) {
			as_schedule_recurring_action(
				time(),
				4 * HOUR_IN_SECONDS,
				'cgm_fetch_news_cron',
				[],
				'cgm-news-group'
			);
			$this->logger->info( null, 'scheduler_cron_registered', 'Scheduled recurring cron action "cgm_fetch_news_cron" (every 4 hours).' );
		}
	}

	/**
	 * Callback for cgm_fetch_news_cron recurring action.
	 */
	public function run_fetch_news() {
		$this->logger->info( null, 'scheduler_fetch_run', 'Action Scheduler running scheduled news fetch.' );
		try {
			$this->processor->fetch_and_queue_news_for_all_tickers();
		} catch ( \Throwable $e ) {
			$this->logger->error( null, 'scheduler_fetch_failed', 'Uncaught exception during fetch: ' . $e->getMessage() );
		}
	}

	/**
	 * Callback for cgm_process_news_item background job.
	 *
	 * @param int $registry_id
	 */
	public function run_process_news_item( int $registry_id ) {
		$this->logger->info( null, 'scheduler_process_run', sprintf( 'Action Scheduler starting job for registry item %d.', $registry_id ) );
		try {
			$this->processor->process_news_item( $registry_id );
		} catch ( \Throwable $e ) {
			$this->logger->error( null, 'scheduler_process_failed', sprintf( 'Uncaught exception in job %d: %s', $registry_id, $e->getMessage() ) );
		}
	}

	/**
	 * Check next execution details.
	 *
	 * @return array
	 */
	public function get_status(): array {
		$status = [
			'cron_scheduled' => false,
			'next_run'       => null,
			'action_scheduler_active' => function_exists( 'as_next_scheduled_action' ),
		];

		if ( $status['action_scheduler_active'] ) {
			$next = as_next_scheduled_action( 'cgm_fetch_news_cron' );
			$status['cron_scheduled'] = ( false !== $next );
			$status['next_run']       = $next ? date( 'Y-m-d H:i:s', $next ) : null;
		}

		return $status;
	}
}
