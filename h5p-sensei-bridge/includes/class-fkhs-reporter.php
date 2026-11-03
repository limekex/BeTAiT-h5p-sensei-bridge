<?php
/**
 * Admin report shell and escaped rows for the authorized history endpoint.
 *
 * All filtering and pagination are performed by FKHS_Report_Query.
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
		add_action( 'rest_api_init', array( 'FKHS_Report_Query', 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );

	}

	/**
	 * Register the top-level admin menu.
	 *
	 * @return void
	 */
	public static function menu() {
		if ( ! FKHS_Report_Query::can_view() ) { return; }
		add_menu_page(
			__( 'H5P → Sensei', 'h5p-sensei-bridge' ), // Page title.
			__( 'H5P → Sensei', 'h5p-sensei-bridge' ), // Menu title.
			'read',                                     // Checked above and on every read.
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
			$hash = is_readable( $path ) ? hash_file( 'sha256', $path ) : false;
			$ver  = $hash ? FKHS_VER . '-' . substr( $hash, 0, 12 ) : FKHS_VER;

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
				'url' => rest_url( 'fkhs/v1/report' ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n' => array(
					'loading' => __( 'Loading attempts…', 'h5p-sensei-bridge' ),
					'loadError' => __( 'Could not load attempts. Retry, or sign in again if your session expired.', 'h5p-sensei-bridge' ),
					'retry' => __( 'Retry', 'h5p-sensei-bridge' ),
					'sort'               => __( 'Sort', 'h5p-sensei-bridge' ),
					'close' => __( 'Close', 'h5p-sensei-bridge' ),
					'from' => __( 'From', 'h5p-sensei-bridge' ),
					'to' => __( 'To', 'h5p-sensei-bridge' ),
					'comparison' => __( 'Comparison', 'h5p-sensei-bridge' ),
					'value' => __( 'Value', 'h5p-sensei-bridge' ),
					'atLeast' => __( 'At least', 'h5p-sensei-bridge' ),
					'atMost' => __( 'At most', 'h5p-sensei-bridge' ),
					'rowsPerPage' => __( 'Rows per page', 'h5p-sensei-bridge' ),
					'sortColumn' => __( 'Sort by %s', 'h5p-sensei-bridge' ),
					'filterColumn' => __( 'Filter %s', 'h5p-sensei-bridge' ),
					'activeFilterColumn' => __( 'Filter %s (active)', 'h5p-sensei-bridge' ),
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
			if ( ! FKHS_Report_Query::can_view() ) {
				return;
			}

			?>
			<div class="wrap">
				<h1 id="fkhs-report-title"><?php esc_html_e( 'H5P → Sensei (attempt history)', 'h5p-sensei-bridge' ); ?></h1>

				<div class="fkhs-report-scroll" role="region" aria-labelledby="fkhs-report-title" tabindex="0">
				<table id="fkhs-report-table" class="widefat striped">
					<thead>
						<tr>
							<th scope="col" data-key="date"><?php esc_html_e( 'Date', 'h5p-sensei-bridge' ); ?></th>
							<th scope="col" data-key="user"><?php esc_html_e( 'User', 'h5p-sensei-bridge' ); ?></th>
							<th scope="col" data-key="lesson"><?php esc_html_e( 'Lesson', 'h5p-sensei-bridge' ); ?></th>
							<th scope="col" data-key="h5p"><?php esc_html_e( 'H5P Content', 'h5p-sensei-bridge' ); ?></th>
							<th scope="col" data-key="score"><?php esc_html_e( 'Score', 'h5p-sensei-bridge' ); ?></th>
							<th scope="col" data-key="criterion"><?php esc_html_e( 'Criterion', 'h5p-sensei-bridge' ); ?></th>
							<th scope="col" data-key="passed"><?php esc_html_e( 'Passed', 'h5p-sensei-bridge' ); ?></th>
							<th scope="col" data-key="completed"><?php esc_html_e( 'Completed', 'h5p-sensei-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody>

					</tbody>
				</table>
				</div>

                <p class="description"><?php esc_html_e( 'Search covers all attempts you may view. Teachers see lessons in courses they own. Dates use the stored site time. Passed and completed are stored attempt flags; the criterion is the current effective threshold and cannot be globally filtered or sorted.', 'h5p-sensei-bridge' ); ?></p>
                <noscript><p><?php esc_html_e( 'JavaScript is required to search this report.', 'h5p-sensei-bridge' ); ?></p></noscript>
			</div>
			<?php
		}

    /** Render only the bounded, authorized page; no raw xAPI data is exposed. */
    public static function render_rows( array $rows, array $metadata ): string {
        ob_start();
        try {
						foreach ( $rows as $r ) :
							$user_id     = (int) $r['user_id'];
							$user_login  = $r['user_login'] ?: ( '#' . $user_id );
							$lesson_id   = (int) $r['lesson_id'];
							$lesson_link = get_edit_post_link( $lesson_id );

							// Lesson title: prefer stored snapshot; fall back to current live title.
							$stored_title = isset( $r['lesson_title'] ) ? trim( (string) $r['lesson_title'] ) : '';
							$live_title   = $r['live_title'];
							$lesson_title = $stored_title ?: ( $live_title ?: '—' );

							// H5P metadata lookup (safe on multisite/prefix).
							$content_id = ! empty( $r['content_id'] ) ? (int) $r['content_id'] : 0;
							$h5p_title  = '—';
							$h5p_type   = '—';
							$h5p_link   = '';

							$h5p = $metadata[ $content_id ] ?? null;
                            if ( $h5p ) {
                                $h5p_title = $h5p['title'];
                                $h5p_type = $h5p['name'] ? sprintf( '%s %d.%d', $h5p['name'], $h5p['major_version'], $h5p['minor_version'] ) : '—';
                                if ( current_user_can( 'manage_options' ) || ( (int) $h5p['user_id'] === get_current_user_id() && current_user_can( 'edit_h5p_contents' ) ) ) {
                                    $h5p_link = admin_url( 'admin.php?page=h5p&task=edit&id=' . $content_id );
                                }
                            }

							// Use the same "effective threshold" logic as the frontend/REST.
							$eff_threshold = FKHS_Thresholds::get_effective_threshold(
								(int) $lesson_id,
								$content_id ?: null,
								(int) $user_id
							);
							$eff_threshold = is_numeric( $eff_threshold ) ? (float) $eff_threshold : 0.0;

							// Display label: "Completion only" if 0, otherwise "N%".
							$criterion_label = ( $eff_threshold > 0 )
								? ( $eff_threshold . '%' )
								: __( 'Completion only', 'h5p-sensei-bridge' );

							$raw = is_null( $r['raw_score'] ) ? '—' : (float) $r['raw_score'];
							$max = is_null( $r['max_score'] ) ? '—' : (float) $r['max_score'];
							?>
							<tr
								data-attempt-id="<?php echo (int) $r['id']; ?>"
                                data-date-iso="<?php echo esc_attr( gmdate( 'Y-m-d\TH:i:s', strtotime( $r['created_at'] ) ) ); ?>"
								data-user="<?php echo esc_attr( $user_login ); ?>"
								data-lesson-title="<?php echo esc_attr( $lesson_title ); ?>"
								data-score-raw="<?php echo esc_attr( is_null( $r['raw_score'] ) ? '' : (float) $r['raw_score'] ); ?>"
								data-score-max="<?php echo esc_attr( is_null( $r['max_score'] ) ? '' : (float) $r['max_score'] ); ?>"
								data-criterion="<?php echo esc_attr( $eff_threshold ); ?>"
								data-passed="<?php echo esc_attr( ! empty( $r['passed'] ) ? '1' : '0' ); ?>"
								data-completed="<?php echo esc_attr( ! empty( $r['completed'] ) ? '1' : '0' ); ?>"
							>
								<td><?php echo esc_html( $r['created_at'] ); ?></td>
								<td><?php echo esc_html( $user_login ); ?></td>
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
										echo esc_html( $h5p ? $h5p_title . ' (' . $h5p_type . ')' : '#' . $content_id );
									}
									?>
								</td>
								<td><?php echo esc_html( is_string( $raw ) || is_string( $max ) ? "{$raw} / {$max}" : ( $raw . ' / ' . $max ) ); ?></td>
								<td><?php echo esc_html( $criterion_label ); ?></td>
								<td><?php echo ! empty( $r['passed'] ) ? '✓' : '–'; ?></td>
								<td><?php echo ! empty( $r['completed'] ) ? '✓' : '–'; ?></td>
							</tr>
							<?php
						endforeach;
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

}
