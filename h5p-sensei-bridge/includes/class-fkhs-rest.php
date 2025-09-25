<?php
/**
 * REST Controller: Receives H5P xAPI statements from the front-end bridge.
 *
 * Responsibilities:
 * - Authenticate (cookie + REST nonce handled by WordPress).
 * - Validate payload and enrollment.
 * - Compute "passed" using server-side threshold (score ≥ threshold),
 *   falling back to xAPI result.success when score is missing.
 * - Persist attempt snapshot (including lesson title).
 * - DO NOT mark completion here (Sensei owns completion; we veto elsewhere).
 *
 * @package H5P_Sensei_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class FKHS_REST
 */
class FKHS_REST {

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			'fkhs/v1',
			'/h5p-xapi',
			array(
				'methods'             => \WP_REST_Server::CREATABLE, // POST.
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'callback'            => array( __CLASS__, 'handle' ),
				// Basic argument hints (JSON body) — deep validation occurs inside handle().
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
						'description' => __( 'Client-side threshold hint (server will override from lesson meta).', 'h5p-sensei-bridge' ),
						'type'        => 'number',
						'required'    => false,
					),
					'statement' => array(
						/* translators: xAPI statement payload (object). */
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
					'lesson_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Add strong no-cache headers to a REST response (browser, proxies, LSCache).
	 *
	 * @param WP_REST_Response $response Response object to modify.
	 * @return WP_REST_Response
	 */
	private static function apply_no_cache_headers( $response ) {
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Expires', '0' );
			// LiteSpeed/LSCache hint:
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}
		// WordPress helper also sets conservative headers.
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		// LSCache plugin API (no-op if plugin ikke er aktiv):
		if ( function_exists( 'do_action' ) ) {
			// Tving no-cache for akkurat denne responsen.
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

		$threshold = (float) get_post_meta( $lesson_id, '_fkhs_pass_threshold', true );
		if ( ! $threshold ) {
			$threshold = 70.0;
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
			// Latest attempt for current user/content/lesson
			$attempt = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT raw_score, max_score, passed, created_at
					   FROM {$table}
					  WHERE user_id=%d AND lesson_id=%d AND content_id=%d
					  ORDER BY created_at DESC LIMIT 1",
					$user_id,
					$lesson_id,
					$cid
				),
				ARRAY_A
			);

			// Best pct ever for this user/content/lesson (optional but nice)
			$best_pct = null;
			$best_raw = null;
			$best_max = null;

			$best = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT raw_score, max_score
					   FROM {$table}
					  WHERE user_id=%d AND lesson_id=%d AND content_id=%d
					    AND max_score IS NOT NULL AND max_score > 0
					  ORDER BY (raw_score/max_score) DESC, created_at DESC
					  LIMIT 1",
					$user_id,
					$lesson_id,
					$cid
				),
				ARRAY_A
			);
			if ( $best && is_numeric( $best['raw_score'] ) && is_numeric( $best['max_score'] ) && (float) $best['max_score'] > 0 ) {
				$best_raw = (float) $best['raw_score'];
				$best_max = (float) $best['max_score'];
				$best_pct = ( $best_raw / $best_max ) * 100.0;
			}

			// Title/type (nice to have)
			$h5p_title = '';
			$h5p_type  = '';
			$c_table   = $wpdb->prefix . 'h5p_contents';
			$l_table   = $wpdb->prefix . 'h5p_libraries';
			$meta      = $wpdb->get_row(
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

			$latest_pass = false;
			$latest_raw  = null;
			$latest_max  = null;
			$latest_pct  = null;
			if ( $attempt ) {
				$latest_raw = is_numeric( $attempt['raw_score'] ) ? (float) $attempt['raw_score'] : null;
				$latest_max = is_numeric( $attempt['max_score'] ) ? (float) $attempt['max_score'] : null;
				if ( (int) $attempt['passed'] === 1 ) {
					$latest_pass = true;
				} elseif ( $latest_raw !== null && $latest_max && $latest_max > 0 ) {
					$latest_pct  = ( $latest_raw / $latest_max ) * 100.0;
					$latest_pass = $latest_pct >= $threshold;
				}
			}
			if ( $latest_pct === null && $latest_raw !== null && $latest_max ) {
				$latest_pct = ( $latest_raw / $latest_max ) * 100.0;
			}

			$out[] = array(
				'content_id' => $cid,
				'title'      => $h5p_title,
				'type'       => $h5p_type,
				'threshold'  => $threshold,
				'latest'     => array(
					'raw'    => $latest_raw,
					'max'    => $latest_max,
					'pct'    => $latest_pct,
					'passed' => $latest_pass,
				),
				'best'       => array(
					'raw' => $best_raw,
					'max' => $best_max,
					'pct' => $best_pct,
				),
			);
		}

		$resp = new WP_REST_Response( array( 'ok' => true, 'items' => $out ), 200 );
		return self::apply_no_cache_headers( $resp );
	}

	/**
	 * Handle POST /fkhs/v1/h5p-xapi
	 *
	 * @param WP_REST_Request $req Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $req ) {
		$data      = $req->get_json_params(); // Already decoded; no need for wp_unslash.
		$user_id   = get_current_user_id();
		$lesson_id = isset( $data['lesson_id'] ) ? (int) $data['lesson_id'] : 0;
		$stmt      = isset( $data['statement'] ) ? $data['statement'] : array();
		$contentId = isset( $data['contentId'] ) ? (int) $data['contentId'] : null;

		if ( ! $user_id || ! $lesson_id || empty( $stmt ) || ! is_array( $stmt ) ) {
			return new \WP_REST_Response(
				array(
					'ok'     => false,
					'reason' => __( 'Missing or invalid parameters.', 'h5p-sensei-bridge' ),
				),
				400
			);
		}

		// Enrollment / ownership — best-effort guard (tighten in your environment if needed).
		if ( ! FKHS_Sensei::user_is_enrolled_in_lesson( $user_id, $lesson_id ) ) {
			return new \WP_REST_Response(
				array(
					'ok'     => false,
					'reason' => __( 'User is not enrolled for this lesson.', 'h5p-sensei-bridge' ),
				),
				403
			);
		}

		// Resolve threshold on the SERVER (ignore/treat client value as hint only).
		$threshold = (float) get_post_meta( $lesson_id, '_fkhs_pass_threshold', true );
		if ( ! $threshold ) {
			$threshold = 70.0;
		}
		/**
		 * Filter the effective threshold for a given lesson/user before pass calculation.
		 *
		 * @param float $threshold Effective threshold (0–100).
		 * @param int   $lesson_id Lesson ID.
		 * @param int   $user_id   User ID.
		 */
		$threshold = (float) apply_filters( 'fkhs_threshold_for_lesson', $threshold, $lesson_id, $user_id );

		// Extract xAPI result fields.
		$result    = isset( $stmt['result'] ) ? $stmt['result'] : array();
		$raw       = isset( $result['score']['raw'] ) ? (float) $result['score']['raw'] : null;
		$max       = isset( $result['score']['max'] ) ? (float) $result['score']['max'] : null;
		$success   = array_key_exists( 'success', $result ) ? (bool) $result['success'] : null;
		$completed = array_key_exists( 'completion', $result ) ? (bool) $result['completion'] : null;

		// Compute "passed":
		// Primary rule: if we have score, passed = (raw/max)*100 ≥ threshold.
		// Fallback: use result.success when score is unavailable.
		$passed = false;
		if ( is_numeric( $raw ) && is_numeric( $max ) && $max > 0 ) {
			$passed = ( ( $raw / $max ) * 100.0 ) >= $threshold;
		} elseif ( null !== $success ) {
			$passed = (bool) $success;
		}

		/**
		 * Allow custom pass calculation policies.
		 *
		 * @param bool  $passed    Calculated pass.
		 * @param array $statement Full xAPI statement.
		 * @param int   $lesson_id Lesson ID.
		 * @param int   $user_id   User ID.
		 */
		$passed = (bool) apply_filters( 'fkhs_calculated_passed', $passed, $stmt, $lesson_id, $user_id );

		/**
		 * Allow short-circuit logging (e.g., to ignore certain H5P types).
		 *
		 * @param bool  $should_log Default true.
		 * @param array $statement  xAPI statement.
		 */
		$should_log = (bool) apply_filters( 'fkhs_should_log_attempt', true, $stmt );

		if ( $should_log ) {
			self::store_attempt(
				$user_id,
				$lesson_id,
				$contentId,
				$raw,
				$max,
				(int) $passed,           // <- store calculated pass (not raw success)
				(int) $completed,
				wp_json_encode( $stmt )
			);
		}

		// Do NOT auto-complete; Sensei controls completion.
		return new \WP_REST_Response(
			array(
				'ok' => true,
			),
			200
		);
	}

	/**
	 * Persist a single attempt row into the attempts table.
	 *
	 * @param int         $user_id        User ID.
	 * @param int         $lesson_id      Lesson ID.
	 * @param int|null    $content_id     H5P content ID or null.
	 * @param float|null  $raw            Raw score or null.
	 * @param float|null  $max            Max score or null.
	 * @param int         $passed         1|0 (calculated pass).
	 * @param int         $completed      1|0 (xAPI completion flag if present).
	 * @param string|null $statement_json JSON-encoded xAPI statement for audit trails.
	 * @return void
	 */
	private static function store_attempt( $user_id, $lesson_id, $content_id, $raw, $max, $passed, $completed, $statement_json ) {
		global $wpdb;

		$table        = $wpdb->prefix . FKHS_TABLE;
		$lesson_title = get_the_title( $lesson_id ); // Snapshot title at attempt time.

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
}
