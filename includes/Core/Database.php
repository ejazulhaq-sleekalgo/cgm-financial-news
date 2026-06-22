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
		// Flush rewrite rules on activation to ensure CPT URLs work immediately.
		self::register_post_types_static();
		flush_rewrite_rules();
	}

	/**
	 * Deactivate plugin cleanup (if necessary).
	 */
	public static function deactivate() {
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
	}

	/**
	 * Register Custom Post Types and Custom Taxonomies.
	 */
	public function register_post_types() {
		add_action( 'init', [ $this, 'register_cgm_cpt' ] );
	}

	/**
	 * Static wrapper for activation helper.
	 */
	public static function register_post_types_static() {
		$db = new self();
		$db->register_cgm_cpt();
	}

	/**
	 * Registers cgm_news custom post type and cgm_ticker custom taxonomy.
	 */
	public function register_cgm_cpt() {
		// Register Custom Taxonomy: Ticker Symbol
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

		register_taxonomy( 'cgm_ticker', [ 'cgm_news' ], $taxonomy_args );

		// Register Custom Post Type: Financial News
		$cpt_labels = [
			'name'               => _x( 'Financial News', 'post type general name', 'cgm-financial-news' ),
			'singular_name'      => _x( 'News Article', 'post type singular name', 'cgm-financial-news' ),
			'menu_name'          => _x( 'Financial News', 'admin menu', 'cgm-financial-news' ),
			'name_admin_bar'     => _x( 'Financial News', 'add new on admin bar', 'cgm-financial-news' ),
			'add_new'            => _x( 'Add New', 'news article', 'cgm-financial-news' ),
			'add_new_item'       => __( 'Add New News Article', 'cgm-financial-news' ),
			'new_item'           => __( 'New News Article', 'cgm-financial-news' ),
			'edit_item'          => __( 'Edit News Article', 'cgm-financial-news' ),
			'view_item'          => __( 'View News Article', 'cgm-financial-news' ),
			'all_items'          => __( 'All Articles', 'cgm-financial-news' ),
			'search_items'       => __( 'Search Financial News', 'cgm-financial-news' ),
			'parent_item_colon'  => __( 'Parent Articles:', 'cgm-financial-news' ),
			'not_found'          => __( 'No articles found.', 'cgm-financial-news' ),
			'not_found_in_trash' => __( 'No articles found in Trash.', 'cgm-financial-news' ),
		];

		$cpt_args = [
			'labels'             => $cpt_labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => false, // We will show it inside our custom React Dashboard instead or standard WP menu. Let's keep it visible under a sub-page or register under our admin slug. Actually, setting to true makes it show in sidebar menu, but we can register it or hide/show as needed. Let's show it in admin so the admin can review articles.
			'query_var'          => true,
			'rewrite'            => [ 'slug' => 'financial-news' ],
			'capability_type'    => 'post',
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => null,
			'supports'           => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
			'taxonomies'         => [ 'cgm_ticker' ],
			'show_in_rest'       => true,
		];

		register_post_type( 'cgm_news', $cpt_args );
	}
}
