<?php
/**
 * Metabox: Lesson settings for H5P → Sensei Bridge.
 *
 * Provides per-lesson controls for:
 * - requiring a passed H5P activity to complete the lesson, and
 * - configuring the pass threshold (%),
 * - overriding the pass threshold per H5P found in the lesson content.
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

		// Per-H5P overrides map: [ content_id => threshold_float ]
		$threshold_map = get_post_meta( $post->ID, '_fkhs_threshold_map', true );
		if ( ! is_array( $threshold_map ) ) {
			$threshold_map = array();
		}

		// Collect H5P instances present in this lesson.
		$h5p_items = array();
		if ( method_exists( 'FKHS_Sensei', 'get_h5p_ids_for_lesson' ) ) {
			$ids = FKHS_Sensei::get_h5p_ids_for_lesson( $post->ID );
			if ( ! empty( $ids ) ) {
				global $wpdb;
				$c_table = $wpdb->prefix . 'h5p_contents';
				$l_table = $wpdb->prefix . 'h5p_libraries';

				foreach ( $ids as $cid ) {
					$title = '';
					$type  = '';

					$meta = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT c.title, l.name, l.major_version, l.minor_version
							   FROM {$c_table} c
							   LEFT JOIN {$l_table} l ON l.id = c.library_id
							  WHERE c.id = %d",
							$cid
						),
						ARRAY_A
					);
					if ( $meta ) {
						$title = (string) ( $meta['title'] ?? '' );
						$type  = $meta['name'] ? sprintf( '%s %d.%d', $meta['name'], (int) $meta['major_version'], (int) $meta['minor_version'] ) : '';
					}
					$h5p_items[] = array(
						'id'    => (int) $cid,
						'title' => $title,
						'type'  => $type,
					);
				}
			}
		}

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

		<?php if ( ! empty( $h5p_items ) ) : ?>
			<hr />
			<p><strong><?php esc_html_e( 'Per-H5P thresholds (optional overrides)', 'h5p-sensei-bridge' ); ?></strong></p>
			<p class="description">
				<?php esc_html_e( 'Leave empty to inherit the lesson threshold above. Set a value (0–100) to override for a specific H5P activity.', 'h5p-sensei-bridge' ); ?>
			</p>
			<table class="widefat striped" style="margin:6px 0;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'H5P', 'h5p-sensei-bridge' ); ?></th>
						<th style="width:140px;"><?php esc_html_e( 'Threshold (%)', 'h5p-sensei-bridge' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $h5p_items as $item ) :
						$cid     = (int) $item['id'];
						$current = isset( $threshold_map[ $cid ] ) ? (float) $threshold_map[ $cid ] : '';
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $item['title'] ?: sprintf( __( 'H5P #%d', 'h5p-sensei-bridge' ), $cid ) ); ?></strong><br />
								<small><?php echo esc_html( $item['type'] ); ?> • ID: <?php echo esc_html( (string) $cid ); ?></small>
							</td>
							<td>
								<input
									type="number"
									name="fkhs_threshold_map[<?php echo esc_attr( (string) $cid ); ?>]"
									min="0"
									max="100"
									step="1"
									value="<?php echo ( '' === $current ? '' : esc_attr( (string) $current ) ); ?>"
									placeholder="<?php echo esc_attr( (string) $threshold ); ?>"
									style="width:120px;"
								/>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

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

		// Per-H5P overrides map.
		if ( isset( $_POST['fkhs_threshold_map'] ) && is_array( $_POST['fkhs_threshold_map'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_map = wp_unslash( $_POST['fkhs_threshold_map'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$clean   = array();

			foreach ( $raw_map as $cid => $val ) {
				$cid = (int) $cid;
				if ( '' === $val || null === $val ) {
					// Empty input = inherit lesson threshold → do not store.
					continue;
				}
				if ( is_numeric( $val ) ) {
					$v = (float) $val;
					if ( $v < 0 ) {
						$v = 0.0;
					} elseif ( $v > 100 ) {
						$v = 100.0;
					}
					$clean[ $cid ] = $v;
				}
			}

			if ( ! empty( $clean ) ) {
				update_post_meta( $post_id, '_fkhs_threshold_map', $clean );
			} else {
				// No valid overrides → remove the meta to keep storage clean.
				delete_post_meta( $post_id, '_fkhs_threshold_map' );
			}
		} else {
			// If the map field isn't present (or cleared), remove the meta.
			delete_post_meta( $post_id, '_fkhs_threshold_map' );
		}
	}
}
