<?php
namespace CGM\FinancialNews\Core;

/**
 * Handles database table installation, CPT, and Taxonomy registrations.
 */
class Database {

	/**
	 * Activate plugin database setup.
	 */
	public static function activate() {
		self::create_tables();
		flush_rewrite_rules();
	}

	/**
	 * Deactivate plugin cleanup (if necessary).
	 */
	public static function deactivate() {
		self::drop_tables();
		// Flush rewrite rules on deactivation.
		flush_rewrite_rules();
	}

	/**
	 * Create custom database tables.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. News Registry Table (for duplicate checks & processing log)
		$table_registry = $wpdb->prefix . 'cgm_news_registry';
		$sql_registry   = "CREATE TABLE $table_registry (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			ticker varchar(20) NOT NULL,
			source_id varchar(100) NOT NULL,
			source_url varchar(512) NOT NULL,
			source_title text NOT NULL,
			source_content longtext NOT NULL,
			content_hash varchar(64) NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'pending',
			post_id bigint(20) DEFAULT NULL,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			published_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY ticker (ticker),
			KEY source_id (source_id),
			KEY source_url (source_url(191)),
			KEY content_hash (content_hash)
		) $charset_collate;";

		dbDelta( $sql_registry );

		// 2. Logs Table (diagnostic logging)
		$table_logs = $wpdb->prefix . 'cgm_logs';
		$sql_logs   = "CREATE TABLE $table_logs (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			level varchar(20) NOT NULL,
			ticker varchar(20) DEFAULT NULL,
			action varchar(50) NOT NULL,
			message text NOT NULL,
			context longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY ticker (ticker),
			KEY timestamp (timestamp)
		) $charset_collate;";

		dbDelta( $sql_logs );

		// 3. Ticker Config Table (replaces serialized tickers in settings)
		$table_tickers = $wpdb->prefix . 'cgm_tickers';
		$sql_tickers   = "CREATE TABLE $table_tickers (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			symbol varchar(20) NOT NULL,
			alias varchar(20) NOT NULL,
			news_limit tinyint(3) NOT NULL DEFAULT 3,
			status varchar(10) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY symbol (symbol),
			KEY status (status)
		) $charset_collate;";

		dbDelta( $sql_tickers );
	}

	/**
	 * Drop tables.
	 */
	public static function drop_tables() {
		global $wpdb;
		$table_registry = $wpdb->prefix . 'cgm_news_registry';
		$table_logs     = $wpdb->prefix . 'cgm_logs';
		$table_tickers  = $wpdb->prefix . 'cgm_tickers';

		$wpdb->query( "DROP TABLE IF EXISTS $table_registry" );
		$wpdb->query( "DROP TABLE IF EXISTS $table_logs" );
		$wpdb->query( "DROP TABLE IF EXISTS $table_tickers" );
	}

	/**
	 * Register custom taxonomy associated with WordPress default 'post' type.
	 */
	public function register_post_types() {
		add_action( 'init', [ $this, 'register_cgm_taxonomy' ] );
	}

	/**
	 * Registers cgm_ticker custom taxonomy associated with the default 'post' type.
	 */
	public function register_cgm_taxonomy() {
		$taxonomy_labels = [
			'name'              => _x( 'Ticker Symbols', 'taxonomy general name', 'cgm-financial-news' ),
			'singular_name'     => _x( 'Ticker Symbol', 'taxonomy singular name', 'cgm-financial-news' ),
			'search_items'      => __( 'Search Ticker Symbols', 'cgm-financial-news' ),
			'all_items'         => __( 'All Ticker Symbols', 'cgm-financial-news' ),
			'parent_item'       => __( 'Parent Ticker Symbol', 'cgm-financial-news' ),
			'parent_item_colon' => __( 'Parent Ticker Symbol:', 'cgm-financial-news' ),
			'edit_item'         => __( 'Edit Ticker Symbol', 'cgm-financial-news' ),
			'update_item'       => __( 'Update Ticker Symbol', 'cgm-financial-news' ),
			'add_new_item'      => __( 'Add New Ticker Symbol', 'cgm-financial-news' ),
			'new_item_name'     => __( 'New Ticker Symbol Name', 'cgm-financial-news' ),
			'menu_name'         => __( 'Ticker Symbols', 'cgm-financial-news' ),
		];

		$taxonomy_args = [
			'hierarchical'      => false,
			'labels'            => $taxonomy_labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => [ 'slug' => 'ticker' ],
			'show_in_rest'      => true, // Enable Gutenberg and WP REST API support.
		];

		register_taxonomy( 'cgm_ticker', [ 'post' ], $taxonomy_args );
	}
}
