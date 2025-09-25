<?php
/**
 * Admin Reporter: Lists recent H5P → Sensei attempts.
 *
 * Shows the last N attempts (default 200) with user, lesson, H5P content,
 * score, criterion, and pass/completion flags. Useful for QA and auditing.
 *
 * @package H5P_Sensei_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class FKHS_Reporter
 */
class FKHS_Reporter {

	/**
	 * Boot admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );

	}

	/**
	 * Register the top-level admin menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'H5P → Sensei', 'h5p-sensei-bridge' ), // Page title.
			__( 'H5P → Sensei', 'h5p-sensei-bridge' ), // Menu title.
			'manage_options',                           // Capability.
			'fkhs-report',                               // Menu slug.
			array( __CLASS__, 'render' ),               // Callback.
			'dashicons-yes',                             // Icon.
			58                                           // Position.
		);
	}


	/**
	 * Enqueue admin scripts/styles.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
		public static function enqueue_admin( $hook ) {
			// Bare på vår rapport-side: admin.php?page=fkhs-report
			if ( 'toplevel_page_fkhs-report' !== $hook ) {
				return;
			}

			$rel  = 'assets/js/h5p-sensei-admin-report.js';
			$path = FKHS_DIR . $rel;
			$url  = FKHS_URL . $rel;
			$ver  = file_exists( $path ) ? (string) filemtime( $path ) : FKHS_VER;

			// 1) Registrer skriptet først (med wp-i18n som dependency)
			wp_register_script(
				'fkhs-admin-report',
				$url,
				array( 'wp-i18n' ),
				$ver,
				true
			);

			// 2) Knytt oversettelser (bygg .po/.mo i FKHS_DIR/languages/)
			wp_set_script_translations(
				'fkhs-admin-report',
				'h5p-sensei-bridge',
				FKHS_DIR . 'languages'
			);

			// 3) Lokaliser (fallback-tekster dersom wp.i18n ikke er tilgjengelig i runtime)
			wp_localize_script( 'fkhs-admin-report', 'fkhsAdmin', array(
				'i18n' => array(
					'sort'               => __( 'Sort', 'h5p-sensei-bridge' ),
					'clickToFilter'      => __( 'Click to filter', 'h5p-sensei-bridge' ),
					'filteredClickToEdit'=> __( 'Filtered – click to edit', 'h5p-sensei-bridge' ),
					'filter'             => __( 'Filter', 'h5p-sensei-bridge' ),
					'user'               => __( 'User', 'h5p-sensei-bridge' ),
					'lesson'             => __( 'Lesson', 'h5p-sensei-bridge' ),
					'dateRange'          => __( 'Date range', 'h5p-sensei-bridge' ),
					'scorePct'           => __( 'Score (%)', 'h5p-sensei-bridge' ),
					'criterionPct'       => __( 'Criterion (%)', 'h5p-sensei-bridge' ),
					'passed'             => __( 'Passed', 'h5p-sensei-bridge' ),
					'completed'          => __( 'Completed', 'h5p-sensei-bridge' ),
					'noFilterForColumn'  => __( 'No filter for this column.', 'h5p-sensei-bridge' ),
					'clear'              => __( 'Clear', 'h5p-sensei-bridge' ),
					'apply'              => __( 'Apply', 'h5p-sensei-bridge' ),
					'resetFilters'       => __( 'Reset filters', 'h5p-sensei-bridge' ),
					'prev'               => __( 'Prev', 'h5p-sensei-bridge' ),
					'next'               => __( 'Next', 'h5p-sensei-bridge' ),
					'rowsLabel'          => __( 'Rows: %d', 'h5p-sensei-bridge' ),
					'any'                => __( 'Any', 'h5p-sensei-bridge' ),
					'yes'                => __( 'Yes', 'h5p-sensei-bridge' ),
					'no'                 => __( 'No', 'h5p-sensei-bridge' ),
				),
			) );

			// 4) Enqueue
			wp_enqueue_script( 'fkhs-admin-report' );
		}



	/**
	 * Render the report table.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . FKHS_TABLE;

		/**
		 * Filter the number of rows to show in the report.
		 *
		 * @param int $limit Default 200.
		 */
		$limit = (int) apply_filters( 'fkhs_report_limit', 200 );
		if ( $limit < 1 ) {
			$limit = 200;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $limit is cast int above.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT {$limit}", ARRAY_A );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'H5P → Sensei (latest attempts)', 'h5p-sensei-bridge' ); ?></h1>

			<table id="fkhs-report-table" class="widefat striped">
				<thead>
					<tr>	
						<th data-key="date"><?php esc_html_e( 'Date', 'h5p-sensei-bridge' ); ?></th>
						<th data-key="user"><?php esc_html_e( 'User', 'h5p-sensei-bridge' ); ?></th>
						<th data-key="lesson"><?php esc_html_e( 'Lesson', 'h5p-sensei-bridge' ); ?></th>
						<th data-key="h5p"><?php esc_html_e( 'H5P Content', 'h5p-sensei-bridge' ); ?></th>
						<th data-key="score"><?php esc_html_e( 'Score', 'h5p-sensei-bridge' ); ?></th>
						<th data-key="criterion"><?php esc_html_e( 'Criterion', 'h5p-sensei-bridge' ); ?></th>
						<th data-key="passed"><?php esc_html_e( 'Passed', 'h5p-sensei-bridge' ); ?></th>
						<th data-key="completed"><?php esc_html_e( 'Completed', 'h5p-sensei-bridge' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="8"><?php esc_html_e( 'No data yet.', 'h5p-sensei-bridge' ); ?></td>
					</tr>
				<?php
				else :
					foreach ( $rows as $r ) :
						$user_id     = (int) $r['user_id'];
						$user        = get_user_by( 'id', $user_id );
						$lesson_id   = (int) $r['lesson_id'];
						$lesson_link = get_edit_post_link( $lesson_id );
						$threshold   = (float) get_post_meta( $lesson_id, '_fkhs_pass_threshold', true );
						if ( ! $threshold ) {
							$threshold = 70.0;
						}

						// Lesson title: prefer stored snapshot; fall back to current live title.
						$stored_title = isset( $r['lesson_title'] ) ? trim( (string) $r['lesson_title'] ) : '';
						$live_title   = get_the_title( $lesson_id );
						$lesson_title = $stored_title ?: ( $live_title ?: '—' );

						// H5P metadata lookup (safe on multisite/prefix).
						$content_id = ! empty( $r['content_id'] ) ? (int) $r['content_id'] : 0;
						$h5p_title  = '—';
						$h5p_type   = '—';
						$h5p_link   = '';

						if ( $content_id ) {
							$c_table = $wpdb->prefix . 'h5p_contents';
							$l_table = $wpdb->prefix . 'h5p_libraries';

							$h5p = $wpdb->get_row(
								$wpdb->prepare(
									"SELECT c.id, c.title, l.name, l.major_version, l.minor_version
									 FROM {$c_table} c
									 LEFT JOIN {$l_table} l ON l.id = c.library_id
									 WHERE c.id = %d",
									$content_id
								),
								ARRAY_A
							);

							if ( $h5p ) {
								$lib_name   = isset( $h5p['name'] ) ? (string) $h5p['name'] : '';
								$lib_major  = isset( $h5p['major_version'] ) ? (int) $h5p['major_version'] : 0;
								$lib_minor  = isset( $h5p['minor_version'] ) ? (int) $h5p['minor_version'] : 0;
								$h5p_type   = $lib_name ? sprintf( '%s %d.%d', $lib_name, $lib_major, $lib_minor ) : '—';
								$h5p_title  = ! empty( $h5p['title'] ) ? (string) $h5p['title'] : '—';
								$h5p_link   = admin_url( 'admin.php?page=h5p&task=edit&id=' . $content_id );
							}
						}

						$raw = is_null( $r['raw_score'] ) ? '—' : (float) $r['raw_score'];
						$max = is_null( $r['max_score'] ) ? '—' : (float) $r['max_score'];
						?>
						<tr
							data-date-iso="<?php echo esc_attr( gmdate( 'Y-m-d\TH:i:s', strtotime( $r['created_at'] ) ) ); ?>"
							data-user="<?php echo esc_attr( $user ? $user->user_login : ( '#' . (int) $r['user_id'] ) ); ?>"
							data-lesson-title="<?php echo esc_attr( $lesson_title ); ?>"
							data-score-raw="<?php echo esc_attr( is_null( $r['raw_score'] ) ? '' : (float) $r['raw_score'] ); ?>"
							data-score-max="<?php echo esc_attr( is_null( $r['max_score'] ) ? '' : (float) $r['max_score'] ); ?>"
							data-criterion="<?php echo esc_attr( $threshold ); ?>"
							data-passed="<?php echo esc_attr( ! empty( $r['passed'] ) ? '1' : '0' ); ?>"
							data-completed="<?php echo esc_attr( ! empty( $r['completed'] ) ? '1' : '0' ); ?>"
							>
							<td><?php echo esc_html( $r['created_at'] ); ?></td>
							<td><?php echo $user ? esc_html( $user->user_login ) : esc_html( '#' . $user_id ); ?></td>
							<td>
								<?php if ( $lesson_link ) : ?>
									<a href="<?php echo esc_url( $lesson_link ); ?>">
										<?php echo esc_html( $lesson_title ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html( $lesson_title ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php
								if ( $h5p_link ) {
									// Example: "My Interactive (H5P.InteractiveVideo 1.26)".
									printf(
										'<a href="%1$s">%2$s (%3$s)</a>',
										esc_url( $h5p_link ),
										esc_html( $h5p_title ),
										esc_html( $h5p_type )
									);
								} else {
									echo '—';
								}
								?>
							</td>
							<td><?php echo esc_html( is_string( $raw ) || is_string( $max ) ? "{$raw} / {$max}" : ( $raw . ' / ' . $max ) ); ?></td>
							<td><?php echo esc_html( $threshold ) . '%'; ?></td>
							<td><?php echo ! empty( $r['passed'] ) ? '✓' : '–'; // Symbols kept simple on purpose. ?></td>
							<td><?php echo ! empty( $r['completed'] ) ? '✓' : '–'; ?></td>
						</tr>
						<?php
					endforeach;
				endif;
				?>
				</tbody>
			</table>

			<p class="description">
				<?php
				/* translators: %d: number of rows displayed in the report. */
				printf( esc_html__( 'Showing the latest %d attempts.', 'h5p-sensei-bridge' ), (int) $limit );
				?>
			</p>
		</div>
		<?php
	}
}
