<?php
/**
 * Metabox: Lesson settings for H5P → Sensei Bridge.
 *
 * Provides per-H5P threshold controls only:
 * - 0   → no pass requirement (completion-only)
 * - 1–100 → require score ≥ threshold (%)
 *
 * @package H5P_Sensei_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class FKHS_Metabox {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post_lesson', array( __CLASS__, 'save' ) );
	}

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

	public static function render( WP_Post $post ) {
		wp_nonce_field( 'fkhs_lesson_meta', 'fkhs_lesson_meta_nonce' );

		$threshold_map = get_post_meta( $post->ID, '_fkhs_threshold_map', true );
		if ( ! is_array( $threshold_map ) ) {
			$threshold_map = array();
		}

		// Detect H5P in lesson
		$h5p_items = array();
		if ( method_exists( 'FKHS_Sensei', 'get_h5p_ids_for_lesson' ) ) {
			$ids = FKHS_Sensei::get_h5p_ids_for_lesson( $post->ID );
			if ( ! empty( $ids ) ) {
				global $wpdb;
				$c_table = $wpdb->prefix . 'h5p_contents';
				$l_table = $wpdb->prefix . 'h5p_libraries';

				foreach ( $ids as $cid ) {
					$title = ''; $type = '';
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
					$h5p_items[] = array( 'id' => (int) $cid, 'title' => $title, 'type' => $type );
				}
			}
		}
		?>
		<p class="description">
			<?php esc_html_e( 'Set thresholds per H5P. Use 0 for no pass requirement (completion-only).', 'h5p-sensei-bridge' ); ?>
		</p>
		<?php if ( empty( $h5p_items ) ) : ?>
			<p><em><?php esc_html_e( 'No H5P items detected in this lesson yet.', 'h5p-sensei-bridge' ); ?></em></p>
		<?php else : ?>
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
						$current = isset( $threshold_map[ $cid ] ) ? (float) $threshold_map[ $cid ] : 0;
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
									min="0" max="100" step="1"
									value="<?php echo esc_attr( (string) $current ); ?>"
									style="width:120px;"
								/>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['fkhs_lesson_meta_nonce'] ) || ! wp_verify_nonce( $_POST['fkhs_lesson_meta_nonce'], 'fkhs_lesson_meta' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		// Only per-H5P map.
		if ( isset( $_POST['fkhs_threshold_map'] ) && is_array( $_POST['fkhs_threshold_map'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_map = wp_unslash( $_POST['fkhs_threshold_map'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$clean   = array();
			foreach ( $raw_map as $cid => $val ) {
				$cid = (int) $cid;
				if ( ! is_numeric( $val ) ) { continue; }
				$v = (float) $val;
				if ( $v < 0 )   { $v = 0.0; }
				if ( $v > 100 ) { $v = 100.0; }
				$clean[ $cid ] = $v;
			}
			if ( ! empty( $clean ) ) {
				update_post_meta( $post_id, '_fkhs_threshold_map', $clean );
			} else {
				delete_post_meta( $post_id, '_fkhs_threshold_map' );
			}
		} else {
			delete_post_meta( $post_id, '_fkhs_threshold_map' );
		}

		// OPTIONAL: you may want to remove legacy metas if you’re fully dropping globals:
		delete_post_meta( $post_id, '_fkhs_pass_threshold' );
		delete_post_meta( $post_id, '_fkhs_require_h5p_pass' );
	}
}
