<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FKHS_Plugin {

	public static function init() {
		add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_front']);
	}

	public static function activate() {
		global $wpdb;
		$table = $wpdb->prefix . FKHS_TABLE;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL,
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
		dbDelta($sql);
	}

	public static function enqueue_front() {
		if ( ! is_user_logged_in() ) return;

		// Enqueue kun på Sensei-leksjoner (CPT 'lesson')
		if ( is_singular('lesson') ) {
			wp_enqueue_script(
				'fkhs-h5p-bridge',
				FKHS_URL . 'assets/js/h5p-sensei-bridge.js',
				[],
				FKHS_VER,
				true
			);

			wp_localize_script('fkhs-h5p-bridge','fkH5P',[
				'restUrl'  => esc_url_raw( rest_url('fkhs/v1/h5p-xapi') ),
				'nonce'    => wp_create_nonce('wp_rest'),
				'lessonId' => get_the_ID(),
				'threshold'=> (float) get_post_meta( get_the_ID(), '_fkhs_pass_threshold', true ) ?: 70.0, // default 70%
				'debug'    => (bool) WP_DEBUG
			]);
		}
	}
}
