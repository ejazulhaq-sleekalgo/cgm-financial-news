<?php
namespace CGM\FinancialNews\Core\REST;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use CGM\FinancialNews\Core\Settings;
use CGM\FinancialNews\Core\Service\NewsProcessor;
use CGM\FinancialNews\Core\Repository\NewsRepository;
use CGM\FinancialNews\Core\Repository\LogRepository;
use CGM\FinancialNews\Core\Scheduler;
use CGM\FinancialNews\Plugin;

/**
 * Custom WordPress REST API Controller for CGM Admin Panel.
 */
class RestController extends WP_REST_Controller {

	private Settings $settings;
	private NewsProcessor $processor;
	private NewsRepository $news_repo;
	private LogRepository $logger;
	private Scheduler $scheduler;

	private string $namespace_str = 'cgm-financial-news/v1';

	/**
	 * Constructor.
	 */
	public function __construct(
		Settings $settings,
		NewsProcessor $processor,
		NewsRepository $news_repo,
		LogRepository $logger,
		Scheduler $scheduler
	) {
		$this->settings  = $settings;
		$this->processor = $processor;
		$this->news_repo = $news_repo;
		$this->logger    = $logger;
		$this->scheduler = $scheduler;
	}

	/**
	 * Hook and register REST routes.
	 */
	public function register_routes() {
		add_action( 'rest_api_init', [ $this, 'register_endpoints' ] );
	}

	/**
	 * Registers endpoints.
	 */
	public function register_endpoints() {
		// Settings
		register_rest_route( $this->namespace_str, '/settings', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_settings' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
		] );

		// Tickers Info (active list + stats)
		register_rest_route( $this->namespace_str, '/tickers', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_tickers' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
		] );

		// Processing Queue
		register_rest_route( $this->namespace_str, '/queue', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_queue' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
		] );

		// Manual job commands
		register_rest_route( $this->namespace_str, '/queue/run', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'run_queue_item' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'id' => [
					'required'          => true,
					'validate_callback' => function( $param ) {
						return is_numeric( $param );
					},
				],
			],
		] );

		register_rest_route( $this->namespace_str, '/queue/fetch', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'trigger_manual_fetch' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( $this->namespace_str, '/queue/reset', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'reset_queue_item' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'id' => [
					'required'          => true,
					'validate_callback' => function( $param ) {
						return is_numeric( $param );
					},
				],
			],
		] );

		// Logging & Diagnostics
		register_rest_route( $this->namespace_str, '/logs', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_logs' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
		] );

		register_rest_route( $this->namespace_str, '/logs/clear', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'clear_logs' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		// API Connectivity Tests
		register_rest_route( $this->namespace_str, '/test-connection', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'test_connection' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'service' => [
					'required' => true,
					'type'     => 'string',
					'enum'     => [ 'eodhd', 'openai' ],
				],
				'key'     => [
					'required' => true,
					'type'     => 'string',
				],
			],
		] );

		// System Health & Dashboard Stats
		register_rest_route( $this->namespace_str, '/stats', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_stats' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );
	}

	/**
	 * Access control callback. Ensure only administrators can access REST endpoints.
	 */
	public function check_permission( WP_REST_Request $request ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /settings
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->settings->get_all();
		// Mask sensitive API Keys.
		if ( ! empty( $data['eodhd_api_key'] ) ) {
			$data['eodhd_api_key_masked'] = substr( $data['eodhd_api_key'], 0, 4 ) . '...' . substr( $data['eodhd_api_key'], -4 );
		}
		if ( ! empty( $data['openai_api_key'] ) ) {
			$data['openai_api_key_masked'] = substr( $data['openai_api_key'], 0, 4 ) . '...' . substr( $data['openai_api_key'], -4 );
		}
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * POST /settings
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();

		// Handle key preservation if masked is sent back
		$current = $this->settings->get_all();
		if ( isset( $params['eodhd_api_key'] ) && ( trim( $params['eodhd_api_key'] ) === '...' || empty( $params['eodhd_api_key'] ) || strpos( $params['eodhd_api_key'], '...' ) !== false ) ) {
			$params['eodhd_api_key'] = $current['eodhd_api_key'] ?? '';
		}
		if ( isset( $params['openai_api_key'] ) && ( trim( $params['openai_api_key'] ) === '...' || empty( $params['openai_api_key'] ) || strpos( $params['openai_api_key'], '...' ) !== false ) ) {
			$params['openai_api_key'] = $current['openai_api_key'] ?? '';
		}

		$success = $this->settings->save( $params );

		if ( $success ) {
			return new WP_REST_Response( [ 'success' => true, 'message' => 'Settings saved successfully.' ], 200 );
		}
		return new WP_REST_Response( [ 'success' => false, 'message' => 'Failed to save settings or no values changed.' ], 400 );
	}

	/**
	 * GET /tickers
	 */
	public function get_tickers( WP_REST_Request $request ): WP_REST_Response {
		$tickers = $this->settings->get_tickers();
		$enriched = [];

		foreach ( $tickers as $ticker ) {
			$symbol = $ticker['symbol'];
			$enriched[] = [
				'symbol'          => $symbol,
				'alias'           => $ticker['alias'] ?? $symbol,
				'limit'           => $ticker['limit'] ?? 3,
				'status'          => $ticker['status'] ?? 'active',
				'today_published' => $this->news_repo->get_today_published_count( $symbol ),
				'total_published' => $this->news_repo->get_queue_count( 'processed', $symbol ),
				'pending_count'   => $this->news_repo->get_queue_count( 'pending', $symbol ),
			];
		}

		return new WP_REST_Response( $enriched, 200 );
	}

	/**
	 * GET /queue
	 */
	public function get_queue( WP_REST_Request $request ): WP_REST_Response {
		$limit  = max( 10, min( 100, intval( $request->get_param( 'limit' ) ?: 50 ) ) );
		$page   = max( 1, intval( $request->get_param( 'page' ) ?: 1 ) );
		$status = $request->get_param( 'status' ) ?: null;
		$ticker = $request->get_param( 'ticker' ) ?: null;

		$offset = ( $page - 1 ) * $limit;
		$items  = $this->news_repo->get_queue( $limit, $offset, $status, $ticker );
		$total  = $this->news_repo->get_queue_count( $status, $ticker );

		// Enrich each record with WP Post link if published.
		foreach ( $items as &$item ) {
			if ( ! empty( $item['post_id'] ) ) {
				$item['post_edit_url'] = get_edit_post_link( $item['post_id'] );
				$item['post_view_url'] = get_permalink( $item['post_id'] );
			}
		}

		return new WP_REST_Response( [
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'limit' => $limit,
		], 200 );
	}

	/**
	 * POST /queue/run
	 */
	public function run_queue_item( WP_REST_Request $request ): WP_REST_Response {
		$id = intval( $request->get_param( 'id' ) );
		$success = $this->processor->process_news_item( $id );

		if ( $success ) {
			return new WP_REST_Response( [ 'success' => true, 'message' => 'Article processed and published successfully.' ], 200 );
		}

		$item = $this->news_repo->get( $id );
		$err  = $item['error_message'] ?? 'Factual validation failed, content was irrelevant, or API error occurred. Check Logs page for details.';
		return new WP_REST_Response( [ 'success' => false, 'message' => $err ], 400 );
	}

	/**
	 * POST /queue/fetch
	 */
	public function trigger_manual_fetch( WP_REST_Request $request ): WP_REST_Response {
		// Run fetching synchronously. Action Scheduler will handle processing.
		$this->processor->fetch_and_queue_news_for_all_tickers();
		return new WP_REST_Response( [ 'success' => true, 'message' => 'News fetched from EODHD and queued successfully.' ], 200 );
	}

	/**
	 * POST /queue/reset
	 */
	public function reset_queue_item( WP_REST_Request $request ): WP_REST_Response {
		$id      = intval( $request->get_param( 'id' ) );
		$success = $this->news_repo->reset_item( $id );

		if ( $success ) {
			return new WP_REST_Response( [ 'success' => true, 'message' => 'Queue item status reset to pending.' ], 200 );
		}
		return new WP_REST_Response( [ 'success' => false, 'message' => 'Failed to reset queue item.' ], 400 );
	}

	/**
	 * GET /logs
	 */
	public function get_logs( WP_REST_Request $request ): WP_REST_Response {
		$limit  = max( 10, min( 200, intval( $request->get_param( 'limit' ) ?: 50 ) ) );
		$page   = max( 1, intval( $request->get_param( 'page' ) ?: 1 ) );
		$level  = $request->get_param( 'level' ) ?: null;
		$ticker = $request->get_param( 'ticker' ) ?: null;
		$search = $request->get_param( 'search' ) ?: null;

		$offset = ( $page - 1 ) * $limit;
		$items  = $this->logger->get_logs( $limit, $offset, $level, $ticker, $search );
		$total  = $this->logger->get_count( $level, $ticker, $search );

		return new WP_REST_Response( [
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'limit' => $limit,
		], 200 );
	}

	/**
	 * POST /logs/clear
	 */
	public function clear_logs( WP_REST_Request $request ): WP_REST_Response {
		$success = $this->logger->clear_logs();
		return new WP_REST_Response( [ 'success' => $success ], 200 );
	}

	/**
	 * POST /test-connection
	 */
	public function test_connection( WP_REST_Request $request ): WP_REST_Response {
		$service = $request->get_param( 'service' );
		$key     = $request->get_param( 'key' );

		$success = false;

		if ( 'eodhd' === $service ) {
			$eodhd_test = Plugin::getInstance()->get( 'eodhd_service' );
			$success    = $eodhd_test->test_connection( $key );
		} elseif ( 'openai' === $service ) {
			$openai_test = Plugin::getInstance()->get( 'openai_service' );
			$success     = $openai_test->test_connection( $key );
		}

		if ( $success ) {
			return new WP_REST_Response( [ 'success' => true, 'message' => 'Connection test passed successfully.' ], 200 );
		}
		return new WP_REST_Response( [ 'success' => false, 'message' => 'Connection test failed. Please verify API key.' ], 400 );
	}

	/**
	 * GET /stats
	 */
	public function get_stats( WP_REST_Request $request ): WP_REST_Response {
		$tickers = $this->settings->get_tickers();

		$total_published = $this->news_repo->get_queue_count( 'processed' );
		$total_failed    = $this->news_repo->get_queue_count( 'failed' );
		$total_pending   = $this->news_repo->get_queue_count( 'pending' );
		$total_skipped   = $this->news_repo->get_queue_count( 'skipped_irrelevant' );

		// Count published today.
		$today_published = 0;
		foreach ( $tickers as $ticker ) {
			$today_published += $this->news_repo->get_today_published_count( $ticker['symbol'] );
		}

		// Calculate success rate.
		$all_attempts = $total_published + $total_failed;
		$success_rate = $all_attempts > 0 ? round( ( $total_published / $all_attempts ) * 100, 1 ) : 100;

		// Get Scheduler info.
		$scheduler_status = $this->scheduler->get_status();

		// Fetch last 5 published articles.
		$query = new \WP_Query([
			'post_type'      => 'cgm_news',
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => 5,
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);

		$recent_articles = [];
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();
				
				// Get Ticker Taxonomy terms
				$terms = wp_get_object_terms( $id, 'cgm_ticker' );
				$ticker_term = ! empty( $terms ) && ! is_wp_error( $terms ) ? $terms[0]->name : 'Unknown';

				$recent_articles[] = [
					'id'         => $id,
					'title'      => get_the_title(),
					'date'       => get_the_date( 'Y-m-d H:i:s' ),
					'status'     => get_post_status( $id ),
					'ticker'     => $ticker_term,
					'sentiment'  => get_post_meta( $id, '_cgm_sentiment', true ) ?: 'Neutral',
					'relevance'  => get_post_meta( $id, '_cgm_importance', true ) ?: 0,
					'view_url'   => get_permalink( $id ),
					'edit_url'   => get_edit_post_link( $id ),
				];
			}
			wp_reset_postdata();
		}

		return new WP_REST_Response( [
			'total_published'  => $total_published,
			'total_failed'     => $total_failed,
			'total_pending'    => $total_pending,
			'total_skipped'    => $total_skipped,
			'today_published'  => $today_published,
			'success_rate'     => $success_rate,
			'scheduler'        => $scheduler_status,
			'recent_articles'  => $recent_articles,
		], 200 );
	}
}
