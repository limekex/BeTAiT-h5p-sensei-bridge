<?php
/**
 * Core plugin bootstrap for front-end wiring and DB setup.
 *
 * Responsibilities:
 * - Create/upgrade the attempts table on activation or schema bump.
 * - Enqueue the front-end bridge script on Sensei lesson pages (logged-in users only).
 * - Provide a small filter surface for conditional enqueueing and script data.
 *
 * @package H5P_Sensei_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class FKHS_Plugin
 */
class FKHS_Plugin {

	/**
	 * Boot runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ) );
	}

	/**
	 * Runs on plugin activation. Creates/updates the attempts table via dbDelta
	 * and persists the current schema version.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		$table   = $wpdb->prefix . FKHS_TABLE;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// NOTE: dbDelta is idempotent; safe to run on every activation.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL,
			lesson_title VARCHAR(255) NULL,
			content_id BIGINT UNSIGNED NULL,
			raw_score FLOAT NULL,
			max_score FLOAT NULL,
			passed TINYINT(1) DEFAULT 0,
			completed TINYINT(1) DEFAULT 0,
			statement LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_lesson (user_id, lesson_id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );

		// Remember current DB schema version for future upgrades.
		update_option( 'fkhs_db_ver', FKHS_DB_VER );
	}

	/**
	 * Runs when FKHS_DB_VER changes (called from admin_init in main file).
	 * Keeps the table in sync with the latest schema using dbDelta.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		global $wpdb;

		$table   = $wpdb->prefix . FKHS_TABLE;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL,
			lesson_title VARCHAR(255) NULL,
			content_id BIGINT UNSIGNED NULL,
			raw_score FLOAT NULL,
			max_score FLOAT NULL,
			passed TINYINT(1) DEFAULT 0,
			completed TINYINT(1) DEFAULT 0,
			statement LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_lesson (user_id, lesson_id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Front-end bridge script enqueue.
	 *
	 * Loads only on single Sensei lesson pages for logged-in users.
	 * Provides REST route, nonce, current lesson ID, pass threshold and requirement.
	 *
	 * @return void
	 */
	public static function enqueue_front() {
		// Logged-in guard: we only record/act on identified users.
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Sensei lesson singular guard (CPT 'lesson').
		if ( ! is_singular( 'lesson' ) ) {
			return;
		}

		/**
		 * Allow site owners to disable the bridge script on certain lessons,
		 * or force-enable in custom contexts.
		 *
		 * @param bool $should_enqueue Default true.
		 */
		$should_enqueue = apply_filters( 'fkhs_should_enqueue_bridge', true );
		if ( ! $should_enqueue ) {
			return;
		}

		$lesson_id = get_the_ID();

		// Resolve per-lesson settings with sane defaults.
		$threshold = (float) get_post_meta( $lesson_id, '_fkhs_pass_threshold', true );
		if ( ! $threshold ) {
			$threshold = 70.0;
		}
		$require = (bool) get_post_meta( $lesson_id, '_fkhs_require_h5p_pass', true );

		// Version the asset with filemtime when possible (cache-busting in dev).
		$script_rel_path = 'assets/js/h5p-sensei-bridge.js';
		$script_path     = FKHS_DIR . $script_rel_path;
		$script_url      = FKHS_URL . $script_rel_path;
		$asset_ver       = file_exists( $script_path ) ? (string) filemtime( $script_path ) : FKHS_VER;

		// Register + enqueue the script.
		wp_register_script(
			'fkhs-h5p-bridge',
			$script_url,
			array(), // No hard deps; H5P exposes window.H5P when present.
			$asset_ver,
			true
		);

		// Når du enqueuer skriptet:
		wp_set_script_translations('fkhs-admin-report', 'h5p-sensei-bridge', plugin_dir_path(__FILE__) . 'languages');


		/**
		 * Localized data passed to the bridge script.
		 * You may filter this to add custom flags for your theme/flow.
		 */
		$script_data = array(
			'restUrl'   => esc_url_raw( rest_url( 'fkhs/v1/h5p-xapi' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'lessonId'  => (int) $lesson_id,
			'threshold' => (float) $threshold,
			'require'   => (bool) $require,
			'debug'     => (bool) WP_DEBUG,
			'bypass'    => (bool) ( current_user_can( 'manage_sensei_grades' ) || current_user_can( 'manage_options' ) ),
			'i18n' => array(
				'completeH5PFirst'     => __( 'Complete the H5P first', 'h5p-sensei-bridge' ),
				'blockedNoticeQuiz'    => __( 'You need to pass the in-lesson tasks before you can take the quiz.', 'h5p-sensei-bridge' ),
				'blockedNoticeLesson'  => __( 'You need to pass the in-lesson tasks before you can complete the lesson.', 'h5p-sensei-bridge' ),
				'overlayPassed'        => __( 'This task is passed: %1$s / %2$s points (accepted).', 'h5p-sensei-bridge' ),
				'overlayNotPassed'     => __( 'Taken earlier, not passed. Best score: %1$s / %2$s. Required: %3$s. Click to retry.', 'h5p-sensei-bridge' ),
				'practiceAgain'        => __( 'Practice again', 'h5p-sensei-bridge' ),
				'retryNow'             => __( 'Retry now', 'h5p-sensei-bridge' ),

				'statusHeading'        => __( 'Lesson tasks status', 'h5p-sensei-bridge' ),
				'statusAllPassed'      => __( 'All tasks are passed. You can continue.', 'h5p-sensei-bridge' ),
				'statusProgress'       => __( '%1$d of %2$d tasks passed', 'h5p-sensei-bridge' ),
				'statusRemaining'      => __( 'Remaining:', 'h5p-sensei-bridge' ),
				'statusViewTask'       => __( 'View task', 'h5p-sensei-bridge' ),
				'statusRemainingItem' => __( '%1$s — best %2$s, required %3$s', 'h5p-sensei-bridge' ),
				'taskPassed'        => __( 'Task passed', 'h5p-sensei-bridge' ),
				'notPassedYet'      => __( 'Not passed yet', 'h5p-sensei-bridge' ),

			),
		);

		/**
		 * Filter the localized script data before it is printed.
		 *
		 * @param array $script_data Current payload.
		 * @param int   $lesson_id   Current lesson ID.
		 */
		$script_data = apply_filters( 'fkhs_bridge_script_data', $script_data, $lesson_id );

		wp_localize_script( 'fkhs-h5p-bridge', 'fkH5P', $script_data );
		wp_enqueue_script( 'fkhs-h5p-bridge' );
	}

	
}
