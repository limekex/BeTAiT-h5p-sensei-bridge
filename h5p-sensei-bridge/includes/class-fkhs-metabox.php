<?php
/**
 * Metabox: Lesson settings for H5P → Sensei Bridge.
 *
 * Provides per-lesson controls for:
 * - requiring a passed H5P activity to complete the lesson, and
 * - configuring the pass threshold (%).
 *
 * @package H5P_Sensei_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class FKHS_Metabox
 *
 * Registers and renders the lesson-side metabox and persists its settings.
 */
class FKHS_Metabox {

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post_lesson', array( __CLASS__, 'save' ) );
	}

	/**
	 * Register the metabox for the 'lesson' post type.
	 *
	 * @return void
	 */
	public static function add() {
		add_meta_box(
			'fkhs-lesson-settings',
			__( 'H5P → Sensei Bridge', 'h5p-sensei-bridge' ),
			array( __CLASS__, 'render' ),
			'lesson',
			'side',
			'default'
		);
	}

	/**
	 * Render metabox UI.
	 *
	 * @param WP_Post $post Current lesson post object.
	 * @return void
	 */
	public static function render( WP_Post $post ) {
		// Nonce for save verification.
		wp_nonce_field( 'fkhs_lesson_meta', 'fkhs_lesson_meta_nonce' );

		// Current values.
		$threshold = (float) get_post_meta( $post->ID, '_fkhs_pass_threshold', true );
		if ( ! $threshold ) {
			$threshold = 70.0; // default threshold (%).
		}

		$require_h5p = (bool) get_post_meta( $post->ID, '_fkhs_require_h5p_pass', true );

		// Field IDs (for a11y labels).
		$checkbox_id = 'fkhs-require-h5p-pass';
		$number_id   = 'fkhs-pass-threshold';
		?>
		<p>
			<label for="<?php echo esc_attr( $checkbox_id ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( $checkbox_id ); ?>"
					name="fkhs_require_h5p_pass"
					value="1"
					<?php checked( $require_h5p, true ); ?>
				/>
				<strong><?php esc_html_e( 'Require H5P pass to complete this lesson', 'h5p-sensei-bridge' ); ?></strong>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'A pass is defined as score percentage greater than or equal to the criterion below.', 'h5p-sensei-bridge' ); ?>
		</p>

		<p><strong><?php esc_html_e( 'Criterion (pass threshold in %)', 'h5p-sensei-bridge' ); ?></strong></p>
		<p>
			<input
				type="number"
				id="<?php echo esc_attr( $number_id ); ?>"
				name="fkhs_pass_threshold"
				min="0"
				max="100"
				step="1"
				value="<?php echo esc_attr( (string) $threshold ); ?>"
				style="width:120px;"
			/>
			<span class="description"><?php esc_html_e( 'Default is 70%.', 'h5p-sensei-bridge' ); ?></span>
		</p>

		<p class="description">
			<?php esc_html_e( 'Place the H5P activity inside the lesson content. The bridge captures xAPI results and checks this requirement when the lesson is being completed.', 'h5p-sensei-bridge' ); ?>
		</p>
		<?php
	}

	/**
	 * Persist metabox values.
	 *
	 * Runs on 'save_post_lesson'. Validates nonce and capability, and sanitizes input.
	 *
	 * @param int $post_id Lesson post ID.
	 * @return void
	 */
	public static function save( $post_id ) {
		// Nonce check.
		if ( ! isset( $_POST['fkhs_lesson_meta_nonce'] ) || ! wp_verify_nonce( $_POST['fkhs_lesson_meta_nonce'], 'fkhs_lesson_meta' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		// Autosave guard.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Capability guard.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Threshold (0–100). Accepts numeric strings; clamp range.
		if ( isset( $_POST['fkhs_pass_threshold'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw       = wp_unslash( $_POST['fkhs_pass_threshold'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$threshold = is_numeric( $raw ) ? (float) $raw : 70.0;
			if ( $threshold < 0 ) {
				$threshold = 0.0;
			} elseif ( $threshold > 100 ) {
				$threshold = 100.0;
			}
			update_post_meta( $post_id, '_fkhs_pass_threshold', $threshold );
		}

		// Require flag (checkbox).
		$require = isset( $_POST['fkhs_require_h5p_pass'] ) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $post_id, '_fkhs_require_h5p_pass', $require );
	}
}
