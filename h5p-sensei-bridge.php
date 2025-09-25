<?php
/**
 * Plugin Name:       BeTAiT H5P → Sensei Bridge
 * Description:       Captures H5P xAPI results for logged-in users and enforces "pass to complete" in Sensei LMS. Stores attempts and provides an admin report (MVP).
 * Author:            BeTAiT / Bjørn-Tore
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       h5p-sensei-bridge
 * Domain Path:       /languages
 *
 * @package H5P_Sensei_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * -------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------
 *
 * Note: Keep these stable — other files rely on them.
 */
define( 'FKHS_VER', '0.1.0' );                       // Plugin version.
define( 'FKHS_FILE', __FILE__ );                     // Absolute path to this file.
define( 'FKHS_DIR', plugin_dir_path( __FILE__ ) );   // Plugin directory path (with trailing slash).
define( 'FKHS_URL', plugin_dir_url( __FILE__ ) );    // Plugin URL (with trailing slash).
define( 'FKHS_TABLE', 'fkhs_attempts' );             // DB table (without prefix).
define( 'FKHS_DB_VER', '0.2.0' );                    // DB schema version for attempts table (handles upgrades).

/**
 * -------------------------------------------------------------------------
 * Includes
 * -------------------------------------------------------------------------
 */
require_once FKHS_DIR . 'includes/class-fkhs-plugin.php';
require_once FKHS_DIR . 'includes/class-fkhs-rest.php';
require_once FKHS_DIR . 'includes/class-fkhs-sensei.php';
require_once FKHS_DIR . 'includes/class-fkhs-reporter.php';
require_once FKHS_DIR . 'includes/class-fkhs-metabox.php';

/**
 * -------------------------------------------------------------------------
 * Internationalization
 * -------------------------------------------------------------------------
 *
 * Loads translations from /languages. Ensure your plugin folder name matches
 * the Text Domain for best results when generating .pot/.po files.
 */
add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain(
			'h5p-sensei-bridge',
			false,
			dirname( plugin_basename( FKHS_FILE ) ) . '/languages'
		);
	}
);

/**
 * -------------------------------------------------------------------------
 * Activation / Uninstall
 * -------------------------------------------------------------------------
 */

/**
 * Runs on plugin activation:
 * - Creates/updates DB table for attempts (via dbDelta).
 * - Persists current DB schema version as option.
 *
 * @return void
 */
register_activation_hook( __FILE__, array( 'FKHS_Plugin', 'activate' ) );

/**
 * Runs on plugin uninstall (entirely removing the plugin).
 * Keep data by default for safety/audit. If you want to drop the table,
 * implement DROP TABLE here.
 *
 * @return void
 */
register_uninstall_hook(
	__FILE__,
	'fkhs_uninstall'
);

if ( ! function_exists( 'fkhs_uninstall' ) ) {
	/**
	 * Uninstall callback.
	 *
	 * @return void
	 */
	function fkhs_uninstall() {
		// Intentionally left blank to preserve data.
		// Example (dangerous): DROP TABLE {$wpdb->prefix} . FKHS_TABLE;
	}
}

/**
 * -------------------------------------------------------------------------
 * Bootstrap (runtime)
 * -------------------------------------------------------------------------
 *
 * Wires up all components on load.
 */
add_action(
	'plugins_loaded',
	static function () {
		FKHS_Plugin::init();
		FKHS_REST::init();
		FKHS_Sensei::init();
		FKHS_Reporter::init();
		FKHS_Metabox::init();
	}
);

/**
 * -------------------------------------------------------------------------
 * Admin init: run DB upgrades when schema version changes
 * -------------------------------------------------------------------------
 *
 * We re-run dbDelta when FKHS_DB_VER changes (safe, idempotent).
 */
add_action(
	'admin_init',
	static function () {
		if ( get_option( 'fkhs_db_ver' ) !== FKHS_DB_VER ) {
			FKHS_Plugin::maybe_upgrade();
			update_option( 'fkhs_db_ver', FKHS_DB_VER );
		}
	}
);

/**
 * -------------------------------------------------------------------------
 * (Optional) One-off backfill helper
 * -------------------------------------------------------------------------
 *
 * If you need to backfill lesson titles for existing rows, temporarily
 * uncomment the block below, load any WP admin page once, then comment it
 * out again.
 */
/*
add_action(
	'admin_init',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'fkhs_backfilled_titles' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . FKHS_TABLE;

		$rows = $wpdb->get_results(
			"SELECT id, lesson_id FROM {$table} WHERE lesson_title IS NULL LIMIT 1000",
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$title = get_the_title( (int) $row['lesson_id'] );
			if ( $title ) {
				$wpdb->update(
					$table,
					array( 'lesson_title' => $title ),
					array( 'id' => (int) $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		update_option( 'fkhs_backfilled_titles', 1 );
	}
);
*/
