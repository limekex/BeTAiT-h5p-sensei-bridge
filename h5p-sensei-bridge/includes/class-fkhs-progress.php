<?php
/** Shared progress reads. Preserve develop's best-score/sticky-completion policy. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FKHS_Progress {
	private static function check_read(): void {
		global $wpdb;
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Progress storage is unavailable.' ); }
	}
	public static function get_items( int $user_id, int $lesson_id, bool $with_metadata = true ): array {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			return self::query_items( $user_id, $lesson_id, $with_metadata );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	private static function query_items( int $user_id, int $lesson_id, bool $with_metadata ): array {
		$ids = FKHS_Sensei::get_h5p_ids_for_lesson( $lesson_id );
		if ( empty( $ids ) ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . FKHS_TABLE;

		// Resolve titles/libraries once for all activities instead of one query per item.
		$metadata = array();
		if ( $with_metadata ) {
			$c_table = $wpdb->prefix . 'h5p_contents';
			$l_table = $wpdb->prefix . 'h5p_libraries';
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$records = $wpdb->get_results(
				$wpdb->prepare( "SELECT c.id, c.title, l.name, l.major_version, l.minor_version
					FROM {$c_table} c LEFT JOIN {$l_table} l ON l.id=c.library_id
					WHERE c.id IN ($placeholders)", $ids ), ARRAY_A
			);
			self::check_read();
			foreach ( $records as $record ) { $metadata[(int)$record['id']] = $record; }
		}
		$out = array();

		foreach ( $ids as $cid ) {
			// Siste forsøk (kan være "tomt" progressed)
			$attempt = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT raw_score, max_score, passed, completed, created_at
					FROM {$table}
					WHERE user_id=%d AND lesson_id=%d AND content_id=%d
					ORDER BY created_at DESC, id DESC LIMIT 1",
					$user_id, $lesson_id, $cid
				),
				ARRAY_A
			);

			self::check_read();
			$has_attempt      = ! empty( $attempt );
			$latest_completed = false;

			// Beste prosent (for visning / krav-kalkyle)
			$best_pct = null; $best_raw = null; $best_max = null;
			$best = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT raw_score, max_score
					FROM {$table}
					WHERE user_id=%d AND lesson_id=%d AND content_id=%d
					AND max_score IS NOT NULL AND max_score > 0
					ORDER BY (raw_score/max_score) DESC, created_at DESC, id DESC
					LIMIT 1",
					$user_id, $lesson_id, $cid
				),
				ARRAY_A
			);
			self::check_read();
			if ( $best && is_numeric( $best['raw_score'] ) && is_numeric( $best['max_score'] ) && (float) $best['max_score'] > 0 ) {
				$best_raw = (float) $best['raw_score'];
				$best_max = (float) $best['max_score'];
				$best_pct = ( $best_raw / $best_max ) * 100.0;
			}

			// Metadata (tittel/type)
			$h5p_title = ''; $h5p_type = '';
			$meta = $metadata[$cid] ?? null;
			if ( $meta ) {
				$h5p_title = (string) ( $meta['title'] ?? '' );
				$h5p_type = $meta['name'] ? sprintf( '%s %d.%d', $meta['name'], (int)$meta['major_version'], (int)$meta['minor_version'] ) : '';
			}

			$eff_threshold = FKHS_Thresholds::get_effective_threshold( (int) $lesson_id, (int) $cid, (int) $user_id );

			// Om det noensinne finnes completed=1 for brukeren på dette innholdet
			$ever_completed = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$table}
					WHERE user_id=%d AND lesson_id=%d AND content_id=%d AND completed=1
					LIMIT 1",
					$user_id, $lesson_id, $cid
				)
			);

			self::check_read();
			// Siste-resultat (kan være "tomt" progressed) → beregn "latest"
			$latest_pass = false; $latest_raw = null; $latest_max = null; $latest_pct = null;

			if ( $attempt ) {
				$latest_raw       = is_numeric( $attempt['raw_score'] ) ? (float) $attempt['raw_score'] : null;
				$latest_max       = is_numeric( $attempt['max_score'] ) ? (float) $attempt['max_score'] : null;
				$latest_completed = ( isset( $attempt['completed'] ) && (int) $attempt['completed'] === 1 );

				if ( (int) $attempt['passed'] === 1 ) {
					$latest_pass = true;
				} elseif ( $latest_raw !== null && $latest_max && $latest_max > 0 && $eff_threshold > 0 ) {
					$latest_pct  = ( $latest_raw / $latest_max ) * 100.0;
					$latest_pass = ( $latest_pct >= $eff_threshold );
				}
			}
			if ( $latest_pct === null && $latest_raw !== null && $latest_max ) {
				$latest_pct = ( $latest_raw / $latest_max ) * 100.0;
			}

			// *** Sticky completion for completion-only (threshold == 0) ***
			// Hvis bruker noen gang har completed=1, hold completed=true (og dermed passed=true for completion-only),
			// selv om den "siste raden" er en senere progressed uten resultater.
			if ( (float) $eff_threshold === 0.0 ) {
				if ( $ever_completed ) {
					$latest_completed = true;
					$latest_pass      = true; // for completion-only er "passed" == "completed"
				}
			}

			$item = array(
				'content_id' => (int) $cid,
				'title'      => $h5p_title,
				'type'       => $h5p_type,
				'threshold'  => $eff_threshold, // 0 = completion-only
				'latest'     => array(
					'raw'         => $latest_raw,
					'max'         => $latest_max,
					'pct'         => $latest_pct,
					'passed'      => $latest_pass,
					'completed'   => $latest_completed,
					'has_attempt' => (bool) $has_attempt,
				),
				'best'       => array(
					'raw' => $best_raw,
					'max' => $best_max,
					'pct' => $best_pct,
				),
				'has_attempt' => (bool) $has_attempt, // BC
			);
			$item['counts_for_gate'] = $eff_threshold > 0 || $has_attempt;
			$item['requirement_met'] = $eff_threshold > 0
				? ( $latest_pass || ( null !== $best_pct && $best_pct >= $eff_threshold ) )
				: $latest_completed;
			$out[] = $item;
		}

		return $out;
	}

	public static function summarize( array $items ): array {
		$required = false;
		$passed   = true;
		foreach ( $items as $item ) {
			if ( $item['counts_for_gate'] ) {
				$required = true;
				$passed   = $passed && $item['requirement_met'];
			}
		}
		return array( 'required' => $required, 'passed' => $passed );
	}
}
