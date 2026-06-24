<?php
namespace CGM\FinancialNews;

use CGM\FinancialNews\Core\Database;
use CGM\FinancialNews\Core\Settings;
use CGM\FinancialNews\Core\Scheduler;
use CGM\FinancialNews\Core\Frontend;
use CGM\FinancialNews\Core\Admin\AdminController;
use CGM\FinancialNews\Core\REST\RestController;
use CGM\FinancialNews\Core\Repository\LogRepository;
use CGM\FinancialNews\Core\Repository\NewsRepository;
use CGM\FinancialNews\Core\Repository\TickerRepository;
use CGM\FinancialNews\Core\Service\EodhdService;
use CGM\FinancialNews\Core\Service\OpenAiService;
use CGM\FinancialNews\Core\Service\TranslationService;
use CGM\FinancialNews\Core\Service\NewsProcessor;

/**
 * Main Plugin Bootstrap class.
 * Service Locator / Container pattern.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Container instances registry.
	 *
	 * @var array
	 */
	private array $services = [];

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance of the plugin.
	 *
	 * @return Plugin
	 */
	public static function getInstance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the plugin.
	 */
	public function init() {
		// Initialize Core dependencies.
		$this->services['database']     = new Database();
		$this->services['ticker_repo']  = new TickerRepository();
		$this->services['settings']     = new Settings( $this->services['ticker_repo'] );
		$this->services['log_repo']     = new LogRepository();
		$this->services['news_repo']    = new NewsRepository();

		// Initialize API Service layers.
		$this->services['eodhd_service']   = new EodhdService( $this->services['settings'], $this->services['log_repo'] );
		$this->services['openai_service']  = new OpenAiService( $this->services['settings'], $this->services['log_repo'] );
		$this->services['translate_service'] = new TranslationService( $this->services['settings'], $this->services['log_repo'], $this->services['openai_service'] );

		// Initialize Workflow processor.
		$this->services['news_processor']  = new NewsProcessor(
			$this->services['settings'],
			$this->services['news_repo'],
			$this->services['log_repo'],
			$this->services['eodhd_service'],
			$this->services['openai_service'],
			$this->services['translate_service']
		);

		// Initialize Background scheduler.
		$this->services['scheduler']    = new Scheduler( $this->services['settings'], $this->services['news_processor'], $this->services['log_repo'] );

		// Initialize REST Controllers.
		$this->services['rest']         = new RestController( $this->services['settings'], $this->services['ticker_repo'], $this->services['news_processor'], $this->services['news_repo'], $this->services['log_repo'], $this->services['scheduler'] );

		// Initialize Frontend displays.
		$this->services['frontend']     = new Frontend( $this->services['settings'] );

		// Initialize Administrative panel.
		if ( is_admin() ) {
			$this->services['admin'] = new AdminController();
		}

		// Run WordPress action hook bindings.
		$this->boot();
	}

	/**
	 * Boot hooks and event bindings.
	 */
	private function boot() {
		// Run core hooks.
		$this->services['database']->register_post_types();
		$this->services['scheduler']->hooks();
		$this->services['rest']->register_routes();
		$this->services['frontend']->hooks();

		if ( isset( $this->services['admin'] ) ) {
			$this->services['admin']->hooks();
		}

		// Migrate legacy tickers from settings to the custom table.
		$migrated = $this->services['ticker_repo']->migrate_from_settings();
		if ( $migrated > 0 ) {
			$this->services['log_repo']->info( null, 'ticker_migration', "Migrated {$migrated} ticker(s) from settings to custom table." );
		}
	}

	/**
	 * Retrieve a registered service by key.
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->services[ $key ] ?? null;
	}

	/**
	 * Helper getter for Settings.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->services['settings'];
	}

	/**
	 * Helper getter for Log Repository.
	 *
	 * @return LogRepository
	 */
	public function logger(): LogRepository {
		return $this->services['log_repo'];
	}

	/**
	 * Helper getter for News Repository.
	 *
	 * @return NewsRepository
	 */
	public function news(): NewsRepository {
		return $this->services['news_repo'];
	}

	/**
	 * Helper getter for News Processor.
	 *
	 * @return NewsProcessor
	 */
	public function processor(): NewsProcessor {
		return $this->services['news_processor'];
	}

	/**
	 * Helper getter for Scheduler.
	 *
	 * @return Scheduler
	 */
	public function scheduler(): Scheduler {
		return $this->services['scheduler'];
	}
}
