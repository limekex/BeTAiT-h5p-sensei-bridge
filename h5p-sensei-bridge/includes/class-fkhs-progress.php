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
			$out[] = self::item( $user_id, $lesson_id, $cid, $attempt, $best, $ever_completed, $metadata[$cid] ?? null );
		}

		return $out;
	}

	/**
	 * Bounded course snapshot, keyed by lesson. Caller authorizes every lesson first.
	 * Select one latest/best row per current (lesson, activity), never full histories.
	 * The existing receipt index bounds each (user, lesson, activity) lookup. No window SQL
	 * is required, retaining compatibility with the existing database requirements.
	 */
	public static function get_lesson_snapshots( int $user_id, array $lesson_ids ): array {
		if ( count( $lesson_ids ) > 200 ) { throw new LengthException( 'Too many lessons.' ); }
		global $wpdb;
		$scope = array(); $pairs = array(); $out = array();
		foreach ( array_unique( array_map( 'intval', $lesson_ids ) ) as $lesson_id ) {
			if ( $lesson_id <= 0 ) { throw new InvalidArgumentException( 'Invalid lesson.' ); }
			$out[$lesson_id] = array();
			foreach ( FKHS_Sensei::get_h5p_ids_for_lesson( $lesson_id ) as $cid ) {
				if ( count( $scope ) >= 500 ) { throw new LengthException( 'Too many activities.' ); }
				$scope[] = $wpdb->prepare( 'SELECT %d AS lesson_id, %d AS content_id', $lesson_id, $cid );
				$pairs[] = array( $lesson_id, $cid );
			}
		}
		if ( ! $scope ) { return $out; }
		$table = $wpdb->prefix . FKHS_TABLE;
		$derived = implode( ' UNION ALL ', $scope );
		// Resolve IDs in scalar subqueries first. Joining on a correlated subquery
		// makes MariaDB repeatedly scan the joined history for every scope row.
		// Fetch those immutable rows in a second bounded query instead. Pin the
		// existing receipt index so stale statistics cannot select a lesson-wide scan.
		$sql = "SELECT s.lesson_id,s.content_id,
			(SELECT x.id FROM {$table} x FORCE INDEX (event_identity) WHERE x.user_id=%d AND x.lesson_id=s.lesson_id AND x.content_id=s.content_id ORDER BY x.created_at DESC,x.id DESC LIMIT 1) AS attempt_id,
			(SELECT y.id FROM {$table} y FORCE INDEX (event_identity) WHERE y.user_id=%d AND y.lesson_id=s.lesson_id AND y.content_id=s.content_id AND y.max_score>0 ORDER BY (y.raw_score/y.max_score) DESC,y.created_at DESC,y.id DESC LIMIT 1) AS best_id,
			EXISTS(SELECT 1 FROM {$table} c FORCE INDEX (event_identity) WHERE c.user_id=%d AND c.lesson_id=s.lesson_id AND c.content_id=s.content_id AND c.completed=1) AS ever_completed
			FROM ({$derived}) s";
		$suppressed = $wpdb->suppress_errors( true );
		try {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $user_id, $user_id, $user_id ), ARRAY_A );
			self::check_read();
			if ( count( $rows ) !== count( $pairs ) ) { throw new RuntimeException( 'Incomplete progress read.' ); }
			$by_pair = array(); $record_ids = array(); $records = array();
			foreach ( $rows as $row ) {
				$by_pair[$row['lesson_id'] . ':' . $row['content_id']] = $row;
				foreach ( array( 'attempt_id', 'best_id' ) as $key ) {
					if ( null !== $row[$key] ) { $record_ids[(int) $row[$key]] = (int) $row[$key]; }
				}
			}
			if ( $record_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $record_ids ), '%d' ) );
				$selected = $wpdb->get_results( $wpdb->prepare(
					"SELECT id,raw_score,max_score,passed,completed FROM {$table} WHERE user_id=%d AND id IN ({$placeholders})",
					array_merge( array( $user_id ), array_values( $record_ids ) )
				), ARRAY_A );
				self::check_read();
				if ( count( $selected ) !== count( $record_ids ) ) { throw new RuntimeException( 'Progress changed during read.' ); }
				foreach ( $selected as $record ) { $records[(int) $record['id']] = $record; }
			}
			foreach ( $pairs as list( $lesson_id, $cid ) ) {
				$row = $by_pair[$lesson_id . ':' . $cid] ?? null;
				if ( ! $row ) { throw new RuntimeException( 'Missing progress read.' ); }
				$attempt = null !== $row['attempt_id'] ? $records[(int) $row['attempt_id']] : null;
				$best = null !== $row['best_id'] ? $records[(int) $row['best_id']] : null;
				$out[$lesson_id][] = self::item( $user_id, $lesson_id, $cid, $attempt, $best, (bool) $row['ever_completed'], null );
			}
			return $out;
		} finally { $wpdb->suppress_errors( $suppressed ); }
	}

	/** One policy for individual lessons and batched course snapshots. */
	private static function item( int $user_id, int $lesson_id, int $cid, ?array $attempt, ?array $best, bool $ever_completed, ?array $meta ): array {
		$has_attempt = ! empty( $attempt );
		$latest_completed = false;
		$best_pct = null; $best_raw = null; $best_max = null;
		if ( $best && is_numeric( $best['raw_score'] ) && is_numeric( $best['max_score'] ) && (float) $best['max_score'] > 0 ) {
			$best_raw = (float) $best['raw_score'];
			$best_max = (float) $best['max_score'];
			$best_pct = ( $best_raw / $best_max ) * 100.0;
		}

		// Metadata (tittel/type)
		$h5p_title = ''; $h5p_type = '';
		if ( $meta ) {
			$h5p_title = (string) ( $meta['title'] ?? '' );
			$h5p_type = $meta['name'] ? sprintf( '%s %d.%d', $meta['name'], (int)$meta['major_version'], (int)$meta['minor_version'] ) : '';
		}

		$eff_threshold = FKHS_Thresholds::get_effective_threshold( $lesson_id, $cid, $user_id );
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
		return $item;
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
