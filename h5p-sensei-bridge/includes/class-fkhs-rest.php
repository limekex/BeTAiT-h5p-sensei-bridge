<?php
/**
 * REST Controller: Receives H5P xAPI statements from the front-end bridge.
 *
 * Responsibilities:
 * - Authenticate (cookie + REST nonce handled by WordPress).
 * - Validate payload and enrollment.
 * - Compute "passed" using server-side threshold policy:
 *     • threshold > 0  → score% ≥ threshold (fallback to result.success)
 *     • threshold == 0 → completion-only (fallback to success/verb)
 * - Persist attempt snapshot (including lesson title).
 * - DO NOT mark completion here (Sensei owns completion; we veto elsewhere).
 *
 * @package H5P_Sensei_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FKHS_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_rest_route(
			'fkhs/v1',
			'/h5p-xapi',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => static function () { return is_user_logged_in(); },
				'callback'            => array( __CLASS__, 'handle' ),
				'args'                => array(
					'lesson_id' => array(
						'description' => __( 'Sensei lesson ID receiving this attempt.', 'h5p-sensei-bridge' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'contentId' => array(
						'description' => __( 'H5P content ID if known.', 'h5p-sensei-bridge' ),
						'type'        => 'integer',
						'required'    => false,
					),
					'threshold' => array(
						'description' => __( 'Client-side threshold hint (server overrides).', 'h5p-sensei-bridge' ),
						'type'        => 'number',
						'required'    => false,
					),
					'statement' => array(
						'description' => __( 'xAPI statement object from H5P.', 'h5p-sensei-bridge' ),
						'type'        => 'object',
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			'fkhs/v1',
			'/h5p-status',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () { return is_user_logged_in(); },
				'callback'            => array( __CLASS__, 'status' ),
				'args'                => array(
					'lesson_id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
	}

	private static function apply_no_cache_headers( $response ) {
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Expires', '0' );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'litespeed_control_set_nocache' );
		}
		return $response;
	}

public static function status( WP_REST_Request $req ) {
	$user_id   = get_current_user_id();
	$lesson_id = (int) $req->get_param( 'lesson_id' );

	if ( ! $user_id || ! $lesson_id ) {
		$resp = new WP_REST_Response( array( 'ok' => false, 'reason' => 'missing_params' ), 400 );
		return self::apply_no_cache_headers( $resp );
	}

	$ids = FKHS_Sensei::get_h5p_ids_for_lesson( $lesson_id );
	if ( empty( $ids ) ) {
		$resp = new WP_REST_Response( array( 'ok' => true, 'items' => array() ), 200 );
		return self::apply_no_cache_headers( $resp );
	}

	global $wpdb;
	$table = $wpdb->prefix . FKHS_TABLE;

	$out = array();

	foreach ( $ids as $cid ) {
		// Siste forsøk (kan være "tomt" progressed)
		$attempt = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT raw_score, max_score, passed, completed, created_at
				 FROM {$table}
				 WHERE user_id=%d AND lesson_id=%d AND content_id=%d
				 ORDER BY created_at DESC LIMIT 1",
				$user_id, $lesson_id, $cid
			),
			ARRAY_A
		);

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
				 ORDER BY (raw_score/max_score) DESC, created_at DESC
				 LIMIT 1",
				$user_id, $lesson_id, $cid
			),
			ARRAY_A
		);
		if ( $best && is_numeric( $best['raw_score'] ) && is_numeric( $best['max_score'] ) && (float) $best['max_score'] > 0 ) {
			$best_raw = (float) $best['raw_score'];
			$best_max = (float) $best['max_score'];
			$best_pct = ( $best_raw / $best_max ) * 100.0;
		}

		// Metadata (tittel/type)
		$h5p_title = ''; $h5p_type = '';
		$c_table = $wpdb->prefix . 'h5p_contents';
		$l_table = $wpdb->prefix . 'h5p_libraries';
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
			$h5p_title = (string) ( $meta['title'] ?? '' );
			$h5p_type  = $meta['name'] ? sprintf( '%s %d.%d', $meta['name'], (int) $meta['major_version'], (int) $meta['minor_version'] ) : '';
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

		$out[] = array(
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
	}

	$resp = new WP_REST_Response( array( 'ok' => true, 'items' => $out ), 200 );
	return self::apply_no_cache_headers( $resp );
}


	public static function handle( \WP_REST_Request $req ) {
		$data      = $req->get_json_params();
		$user_id   = get_current_user_id();
		$lesson_id = isset( $data['lesson_id'] ) ? (int) $data['lesson_id'] : 0;
		$stmt      = isset( $data['statement'] ) ? $data['statement'] : array();
		$contentId = isset( $data['contentId'] ) ? (int) $data['contentId'] : null;

		if ( ! $user_id || ! $lesson_id || empty( $stmt ) || ! is_array( $stmt ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => __( 'Missing or invalid parameters.', 'h5p-sensei-bridge' ) ), 400 );
		}

		if ( ! FKHS_Sensei::user_is_enrolled_in_lesson( $user_id, $lesson_id ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => __( 'User is not enrolled for this lesson.', 'h5p-sensei-bridge' ) ), 403 );
		}

		$threshold = FKHS_Thresholds::get_effective_threshold( (int) $lesson_id, $contentId ? (int) $contentId : null, (int) $user_id );
		$threshold = (float) apply_filters( 'fkhs_threshold_for_lesson', $threshold, $lesson_id, $user_id ); // BC

		// Extract from result{} (if present)
		$result    = isset( $stmt['result'] ) ? $stmt['result'] : array();
		$raw       = isset( $result['score']['raw'] ) ? (float) $result['score']['raw'] : null;
		$max       = isset( $result['score']['max'] ) ? (float) $result['score']['max'] : null;
		$success   = array_key_exists( 'success', $result ) ? (bool) $result['success'] : null;

		// New: robust completion inference (covers H5P progress extensions)
		$completed = self::infer_completion_from_stmt( $stmt );

		// (Optional) still try to infer success from verb if it was missing
		if ( $success === null ) {
			$verb_id = isset( $stmt['verb']['id'] ) ? (string) $stmt['verb']['id'] : '';
			$success = (bool) preg_match( '#/(passed)$#', $verb_id );
		}

		// Pass policy
		$passed = false;
		if ( $threshold > 0.0 ) {
			if ( is_numeric( $raw ) && is_numeric( $max ) && $max > 0 ) {
				$passed = ( ( $raw / $max ) * 100.0 ) >= $threshold;
			} elseif ( null !== $success ) {
				$passed = (bool) $success;
			}
		} else {
			$passed = ( $completed !== null ) ? (bool) $completed : (bool) $success;
		}

		$passed = (bool) apply_filters( 'fkhs_calculated_passed', $passed, $stmt, $lesson_id, $user_id );

		$should_log = (bool) apply_filters( 'fkhs_should_log_attempt', true, $stmt );
    // NYTT: Ikke lagre støy for completion-only hvis ikke completed
      if ( (float) $threshold === 0.0 && empty( $completed ) ) {
        $should_log = false;
      }

    if ( $should_log ) {
    self::store_attempt(
        $user_id,
        $lesson_id,
        $contentId ?: null,
        $raw,
        $max,
        (int) $passed,
        (int) $completed,
        wp_json_encode( $stmt )
    );
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        global $wpdb;
        error_log( sprintf(
            '[FKHS] insert user=%d lesson=%d cid=%s passed=%d completed=%d last_error=%s',
            $user_id, $lesson_id, $contentId ?: 'NULL', (int)$passed, (int)$completed, $wpdb->last_error ?: '(none)'
        ) );
    }
}


		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	private static function store_attempt( $user_id, $lesson_id, $content_id, $raw, $max, $passed, $completed, $statement_json ) {
		global $wpdb;
		$table        = $wpdb->prefix . FKHS_TABLE;
		$lesson_title = get_the_title( $lesson_id );

		$wpdb->insert(
			$table,
			array(
				'user_id'      => (int) $user_id,
				'lesson_id'    => (int) $lesson_id,
				'lesson_title' => $lesson_title,
				'content_id'   => $content_id ?: null,
				'raw_score'    => is_null( $raw ) ? null : (float) $raw,
				'max_score'    => is_null( $max ) ? null : (float) $max,
				'passed'       => $passed ? 1 : 0,
				'completed'    => $completed ? 1 : 0,
				'statement'    => $statement_json,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%f', '%f', '%d', '%d', '%s', '%s' )
		);
	}

      /**
     * Infer completion from an xAPI statement.
     * Checks (in order):
     *  - explicit result.completion === true
     *  - verb.id ends with '/completed' (or '/answered' as some libs finalize with it)
     *  - known extension bags anywhere we can reasonably expect them:
     *      • result.extensions
     *      • context.extensions
     *      • object.definition.extensions   ← NEW (Course Presentation often uses this)
     *      • top-level extensions (rare)
     *    Keys we look for:
     *      • https://h5p.org/x-api/completed  (bool)
     *      • https://h5p.org/x-api/progress   (0–100 or 0–1)
     *      • http://id.tincanapi.com/extension/progress (some libs)
     * If verb === progressed and progress >= 100% (or >= 0.999), treat as completed.
     *
     * Returns: bool|null  (null if we found no signal)
     */
    private static function infer_completion_from_stmt( array $stmt ) : ?bool {
      // 1) result.completion (canonical)
      if ( isset( $stmt['result'] ) && is_array( $stmt['result'] ) && array_key_exists( 'completion', $stmt['result'] ) ) {
        return (bool) $stmt['result']['completion'];
      }

      // 2) verb: completed (or answered used as finalization)
      $verb_id = isset( $stmt['verb']['id'] ) ? (string) $stmt['verb']['id'] : '';
      if ( $verb_id && preg_match( '#/(completed|answered)$#', $verb_id ) ) {
        return true;
      }

      // Helper: try to read completion/progress from an extensions bag
      $scan_exts = static function( $exts ) : array {
        $out = array( 'completed' => null, 'progress' => null );
        if ( ! is_array( $exts ) ) return $out;

        // boolean completion flag used by several H5P libs
        $completed_keys = array(
          'https://h5p.org/x-api/completed',
        );
        foreach ( $completed_keys as $k ) {
          if ( array_key_exists( $k, $exts ) ) {
            $out['completed'] = (bool) $exts[ $k ];
            break;
          }
        }

        // progress (either 0..100 or 0..1) — try multiple keys
        $progress_keys = array(
          'https://h5p.org/x-api/progress',
          'http://id.tincanapi.com/extension/progress',
        );
        foreach ( $progress_keys as $k ) {
          if ( array_key_exists( $k, $exts ) && is_numeric( $exts[ $k ] ) ) {
            $out['progress'] = (float) $exts[ $k ];
            break;
          }
        }

        return $out;
      };

      // 3) result.extensions
      if ( isset( $stmt['result']['extensions'] ) ) {
        $fx = $scan_exts( $stmt['result']['extensions'] );
        if ( $fx['completed'] !== null ) return (bool) $fx['completed'];
        if ( is_numeric( $fx['progress'] ) ) {
          $prog = (float) $fx['progress'];
          if ( $prog >= 100.0 || $prog >= 0.999 ) return true;
        }
      }

      // 4) context.extensions (many H5P libs)
      if ( isset( $stmt['context']['extensions'] ) ) {
        $fx = $scan_exts( $stmt['context']['extensions'] );
        if ( $fx['completed'] !== null ) return (bool) $fx['completed'];
        if ( is_numeric( $fx['progress'] ) ) {
          $prog = (float) $fx['progress'];
          if ( $prog >= 100.0 || $prog >= 0.999 ) return true;
        }
      }

      // 5) object.definition.extensions  ← NEW: Course Presentation often uses this
      if ( isset( $stmt['object']['definition']['extensions'] ) ) {
        $fx = $scan_exts( $stmt['object']['definition']['extensions'] );
        if ( $fx['completed'] !== null ) return (bool) $fx['completed'];
        if ( is_numeric( $fx['progress'] ) ) {
          $prog = (float) $fx['progress'];
          if ( $prog >= 100.0 || $prog >= 0.999 ) return true;
        }
      }

      // 6) top-level extensions (rare, but cheap to check)
      if ( isset( $stmt['extensions'] ) ) {
        $fx = $scan_exts( $stmt['extensions'] );
        if ( $fx['completed'] !== null ) return (bool) $fx['completed'];
        if ( is_numeric( $fx['progress'] ) ) {
          $prog = (float) $fx['progress'];
          if ( $prog >= 100.0 || $prog >= 0.999 ) return true;
        }
      }

      // 7) If verb is progressed and we found no bags above, we can’t infer.
      // (We only mark completed on progressed if we *saw* progress >= 100.)
      return null;
    }



}
