<?php
/**
 * Plugin Name: CGM - Financial News
 * Description: Fully automated financial news publishing system that retrieves, enriches, rewrites, and publishes ticker-specific news using EODHD and OpenAI.
 * Version:     1.0.0
 * Author:      CGM
 * License:     GPL-2.0-or-later
 * Text Domain: cgm-financial-news
 * Requires PHP: 8.2
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'CGM_FN_VERSION', '1.0.0' );
define( 'CGM_FN_FILE', __FILE__ );
define( 'CGM_FN_PATH', plugin_dir_path( __FILE__ ) );
define( 'CGM_FN_URL', plugin_dir_url( __FILE__ ) );

// Load Composer Autoloader.
if ( file_exists( CGM_FN_PATH . 'vendor/autoload.php' ) ) {
	require_once CGM_FN_PATH . 'vendor/autoload.php';
}

// Load Action Scheduler.
if ( file_exists( CGM_FN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once CGM_FN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

/**
 * Main function to instantiate and run the plugin.
 */
function cgm_financial_news_init() {
	if ( class_exists( 'CGM\\FinancialNews\\Plugin' ) ) {
		$plugin = CGM\FinancialNews\Plugin::getInstance();
		$plugin->init();
	}
}

// Hook initialization. Priority 1 to ensure it runs before standard hooks.
add_action( 'plugins_loaded', 'cgm_financial_news_init', 1 );

// Activation & Deactivation hooks.
register_activation_hook( __FILE__, function() {
	if ( class_exists( 'CGM\\FinancialNews\\Core\\Database' ) ) {
		CGM\FinancialNews\Core\Database::activate();
	}
} );

register_deactivation_hook( __FILE__, function() {
	if ( class_exists( 'CGM\\FinancialNews\\Core\\Database' ) ) {
		CGM\FinancialNews\Core\Database::deactivate();
	}
} );
