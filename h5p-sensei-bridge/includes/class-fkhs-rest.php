<?php
/**
 * REST Controller: Receives H5P xAPI statements from the front-end bridge.
 *
 * Responsibilities:
 * - Authenticate (cookie + REST nonce handled by WordPress).
 * - Validate payload and enrollment.
 * - Compute "passed" using server-side threshold policy:
 *     • threshold > 0  → score% ≥ threshold (fallback to result.success)
 *     • threshold == 0 → explicit root completion (no interaction timer)
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
						'event_id' => array(
							'description' => __( 'Stable UUID for retries of this event.', 'h5p-sensei-bridge' ),
							'type' => 'string',
							'required' => false,
						),
					'lesson_id' => array(
						'description' => __( 'Sensei lesson ID receiving this attempt.', 'h5p-sensei-bridge' ),
						'type'        => 'integer',
							'minimum'     => 1,
						'required'    => true,
					),
					'contentId' => array(
							'description' => __( 'H5P content ID belonging to this lesson.', 'h5p-sensei-bridge' ),
						'type'        => 'integer',
							'minimum'     => 1,
							'required'    => true,
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
						'lesson_id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
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

	private static function error_response( string $reason, int $status ): WP_REST_Response {
		return self::apply_no_cache_headers( new WP_REST_Response( array( 'ok' => false, 'reason' => $reason ), $status ) );
	}

	/** Accept integer query strings without coercing booleans, arrays or fractions. */
	private static function positive_id( $value ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) {
			return 0;
		}
		$id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		return false === $id ? 0 : $id;
	}

	/** Both handlers call this before reading progress or accepting an attempt. */
	private static function access_error( int $user_id, int $lesson_id ): ?WP_REST_Response {
		if ( $user_id <= 0 ) {
			return self::error_response( 'authentication_required', 401 );
		}
		if ( ! $lesson_id ) {
			return self::error_response( 'invalid_lesson_id', 400 );
		}
		if ( ! FKHS_Sensei::access_api_available() || ! class_exists( 'H5P_Plugin' ) || ! shortcode_exists( 'h5p' ) ) {
			return self::error_response( 'dependencies_unavailable', 503 );
		}
		if ( ! FKHS_Sensei::user_can_access_lesson( $user_id, $lesson_id ) ) {
			// Do not distinguish inaccessible lessons from missing lessons.
			return self::error_response( 'lesson_access_denied', 403 );
		}
		return null;
	}

	private static function is_object_array( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) ) {
				return false;
			}
		}
		return true;
	}

	private static function is_finite_number( $value ): bool {
		return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value );
	}

	/** Validate fields consumed by this bridge, without demanding a full LRS statement. */
	private static function valid_statement( $stmt ): bool {
		if ( ! self::is_object_array( $stmt ) || empty( $stmt ) ) {
			return false;
		}
		// Walk only the object paths read below or in completion inference.
		$paths = array(
			array( 'verb' ), array( 'result' ), array( 'result', 'score' ),
			array( 'context' ), array( 'object' ), array( 'object', 'definition' ),
			array( 'result', 'extensions' ), array( 'context', 'extensions' ),
			array( 'object', 'definition', 'extensions' ), array( 'extensions' ),
		);
		foreach ( $paths as $path ) {
			$node = $stmt;
			foreach ( $path as $key ) {
				if ( ! array_key_exists( $key, $node ) ) {
					break;
				}
				$node = $node[ $key ];
				if ( ! self::is_object_array( $node ) ) {
					return false;
				}
			}
		}
		if ( ! isset( $stmt['verb']['id'] ) || ! is_string( $stmt['verb']['id'] ) || '' === trim( $stmt['verb']['id'] ) ) {
			return false;
		}
		if ( isset( $stmt['object'] ) && array_key_exists( 'id', $stmt['object'] )
			&& ( ! is_string( $stmt['object']['id'] ) || '' === trim( $stmt['object']['id'] ) ) ) {
			return false;
		}
		$result = $stmt['result'] ?? array();
		foreach ( array( 'success', 'completion' ) as $key ) {
			if ( array_key_exists( $key, $result ) && ! is_bool( $result[ $key ] ) ) {
				return false;
			}
		}
		$score = $result['score'] ?? array();
		foreach ( array( 'raw', 'max', 'min', 'scaled' ) as $key ) {
			if ( array_key_exists( $key, $score ) && ! self::is_finite_number( $score[ $key ] ) ) {
				return false;
			}
		}
		if ( ( isset( $score['max'] ) && $score['max'] < 0 )
			|| ( isset( $score['raw'], $score['max'] ) && $score['raw'] > $score['max'] )
			|| ( isset( $score['min'], $score['max'] ) && $score['min'] > $score['max'] )
			|| ( isset( $score['raw'], $score['min'] ) && $score['raw'] < $score['min'] )
			|| ( isset( $score['scaled'] ) && ( $score['scaled'] < -1 || $score['scaled'] > 1 ) ) ) {
			return false;
		}
		$bags = array( $result['extensions'] ?? array(), $stmt['context']['extensions'] ?? array(),
			$stmt['object']['definition']['extensions'] ?? array(), $stmt['extensions'] ?? array() );
		foreach ( $bags as $bag ) {
			if ( array_key_exists( 'https://h5p.org/x-api/completed', $bag ) && ! is_bool( $bag['https://h5p.org/x-api/completed'] ) ) {
				return false;
			}
			foreach ( array( 'https://h5p.org/x-api/progress', 'http://id.tincanapi.com/extension/progress' ) as $key ) {
				if ( array_key_exists( $key, $bag ) && ( ! self::is_finite_number( $bag[ $key ] ) || $bag[ $key ] < 0 || $bag[ $key ] > 100 ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public static function status( WP_REST_Request $req ) {
		$user_id   = get_current_user_id();
		$lesson_id = self::positive_id( $req->get_param( 'lesson_id' ) );

		$error = self::access_error( $user_id, $lesson_id );
		if ( $error ) {
			return $error;
		}

		try {
			$items = FKHS_Progress::get_items( $user_id, $lesson_id );
		} catch ( RuntimeException $error ) {
			return self::error_response( 'progress_unavailable', 503 );
		}
		$gate  = FKHS_Progress::summarize( $items );
		$resp  = new WP_REST_Response( array(
			'ok' => true, 'items' => $items,
			'required' => $gate['required'], 'passed' => $gate['passed'],
		), 200 );
		return self::apply_no_cache_headers( $resp );
	}


	public static function handle( \WP_REST_Request $req ) {
		$data      = $req->get_json_params();
		$user_id   = get_current_user_id();
		if ( ! self::is_object_array( $data ) ) {
			return self::error_response( 'invalid_payload', 400 );
		}
		$lesson_id = self::positive_id( $data['lesson_id'] ?? null );
		$stmt      = $data['statement'] ?? null;
		$contentId = self::positive_id( $data['contentId'] ?? null );
		$event_id = $data['event_id'] ?? null;
		$error     = self::access_error( $user_id, $lesson_id );
		if ( $error ) {
			return $error;
		}
		if ( ! $contentId || ! self::valid_statement( $stmt ) ) {
			return self::error_response( 'invalid_payload', 400 );
		}
		if ( array_key_exists( 'event_id', $data ) && ( ! is_string( $event_id ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $event_id ) ) ) {
			return self::error_response( 'invalid_event_id', 400 );
		}
		$event_id = $event_id === null ? null : strtolower( $event_id );
		if ( ! in_array( $contentId, FKHS_Sensei::get_h5p_ids_for_lesson( $lesson_id ), true ) ) {
			return self::error_response( 'content_not_in_lesson', 400 );
		}
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}h5p_contents WHERE id = %d", $contentId ) );
		if ( ! $exists ) {
			return self::error_response( 'content_unavailable', 400 );
		}
		$extensions = $stmt['object']['definition']['extensions'] ?? array();
		foreach ( array( 'http://h5p.org/x-api/h5p-local-content-id', 'https://h5p.org/x-api/h5p-local-content-id' ) as $key ) {
			if ( array_key_exists( $key, $extensions ) && self::positive_id( $extensions[ $key ] ) !== $contentId ) {
				return self::error_response( 'content_identity_conflict', 400 );
			}
		}
		$statement_json = wp_json_encode( self::canonical_payload( $stmt ) );
		if ( false === $statement_json ) {
			return self::error_response( 'invalid_payload', 400 );
		}
		$payload_hash = hash( 'sha256', $statement_json );
		if ( $event_id !== null ) {
			$previous = self::stored_event( $user_id, $lesson_id, $contentId, $event_id, $payload_hash );
			if ( $previous ) { return $previous; }
		}
		// A nested question's score/completion is not the enclosing activity's result.
		if ( self::is_nested_statement( $stmt ) ) {
			return self::skipped_response( 'nested_activity' );
		}

		// Effektiv terskel (per leksjon + innhold + bruker).
		$threshold = FKHS_Thresholds::get_effective_threshold(
			(int) $lesson_id,
			$contentId ? (int) $contentId : null,
			(int) $user_id
		);

		// Ekstraher fra result{} hvis tilstede
		$result  = isset( $stmt['result'] ) ? $stmt['result'] : array();
		$raw     = isset( $result['score']['raw'] ) ? (float) $result['score']['raw'] : null;
		$max     = isset( $result['score']['max'] ) ? (float) $result['score']['max'] : null;
		$success = array_key_exists( 'success', $result ) ? (bool) $result['success'] : null;

		// Robust completion-deteksjon (progress/extension-nøkler m.m.)
		$completed = self::infer_completion_from_stmt( $stmt, $lesson_id, $contentId );

		// Om success mangler, forsøk å tolke fra verb
		if ( $success === null ) {
			$verb_id = isset( $stmt['verb']['id'] ) ? (string) $stmt['verb']['id'] : '';
			$success = (bool) preg_match( '#/(passed)$#', $verb_id );
		}

		// Pass-policy:
		//  - threshold > 0: score% >= threshold (fallback til success)
		//  - threshold = 0: pass følger completed (fallback til success)
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

		// Skal vi logge? (standard: ja)
		$should_log = (bool) apply_filters( 'fkhs_should_log_attempt', true, $stmt );

		// NYTT: Unngå støy for completion-only (threshold=0) frem til det faktisk er completed=true.
		// Merk: empty(false/null) → true, empty(true) → false; vi ønsker å hoppe over logging når IKKE completed.
		if ( (float) $threshold === 0.0 && empty( $completed ) ) {
			$should_log = false;
		}

		if ( $should_log ) {
			return self::store_attempt(
				$user_id,
				$lesson_id,
				$contentId ?: null,
				$raw,
				$max,
				(int) $passed,
				(int) (bool) $completed, // normaliser til 0/1
				$statement_json,
				$event_id,
				$payload_hash
			);
		}

		return self::skipped_response( (float) $threshold === 0.0 && empty( $completed ) ? 'completion_pending' : 'filtered' );
	}

	private static function store_attempt( $user_id, $lesson_id, $content_id, $raw, $max, $passed, $completed, $statement_json, $event_id, $payload_hash ) {
		global $wpdb;
		$table        = $wpdb->prefix . FKHS_TABLE;
		$lesson_title = get_the_title( $lesson_id );

		$previous_suppression = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
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
				'event_id'     => $event_id,
				'payload_hash' => $payload_hash,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%f', '%f', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $previous_suppression );
		if ( 1 !== $inserted ) {
			// Unique index arbitrates concurrent delivery of the same event.
			if ( $event_id !== null ) {
				$previous = self::stored_event( $user_id, $lesson_id, $content_id, $event_id, $payload_hash );
				if ( $previous ) { return $previous; }
			}
			return self::error_response( 'storage_failed', 500 );
		}
		return self::saved_response( (int) $wpdb->insert_id, false );
	}


	private static function canonical_payload( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		if ( self::is_object_array( $value ) ) { ksort( $value ); }
		foreach ( $value as $key => $item ) { $value[ $key ] = self::canonical_payload( $item ); }
		return $value;
	}

	private static function saved_response( int $attempt_id, bool $duplicate ): WP_REST_Response {
		return self::apply_no_cache_headers( new WP_REST_Response( array(
			'ok' => true, 'stored' => true, 'duplicate' => $duplicate, 'attempt_id' => $attempt_id,
		), 200 ) );
	}

	private static function skipped_response( string $reason ): WP_REST_Response {
		return self::apply_no_cache_headers( new WP_REST_Response( array(
			'ok' => true, 'stored' => false, 'skipped' => true, 'reason' => $reason,
		), 200 ) );
	}

	/** Null means no matching event. Database failures must never look like a miss. */
	private static function stored_event( int $user, int $lesson, int $content, string $event, string $hash ): ?WP_REST_Response {
		global $wpdb;
		$table = $wpdb->prefix . FKHS_TABLE;
		$suppressed = $wpdb->suppress_errors( true );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, payload_hash FROM {$table} WHERE user_id=%d AND lesson_id=%d AND content_id=%d AND event_id=%s",
			$user, $lesson, $content, $event
		), ARRAY_A );
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );
		if ( $error ) { return self::error_response( 'storage_unavailable', 503 ); }
		if ( ! $row ) { return null; }
		if ( ! hash_equals( (string) $row['payload_hash'], $hash ) ) {
			return self::error_response( 'event_id_conflict', 409 );
		}
		return self::saved_response( (int) $row['id'], true );
	}

	private static function is_nested_statement( array $stmt ): bool {
		$ext = $stmt['object']['definition']['extensions'] ?? array();
		return array_key_exists( 'http://h5p.org/x-api/h5p-subContentId', $ext )
			|| array_key_exists( 'https://h5p.org/x-api/h5p-subContentId', $ext )
			|| (bool) preg_match( '/[?&]subContentId=/i', $stmt['object']['id'] ?? '' );
	}

	/** Explicit root completion wins. Progress requires an explicitly configured scale. */
	private static function infer_completion_from_stmt( array $stmt, int $lesson_id = 0, int $content_id = 0 ): ?bool {
		if ( self::is_nested_statement( $stmt ) ) { return null; }
		if ( array_key_exists( 'completion', $stmt['result'] ?? array() ) ) {
			return $stmt['result']['completion'];
		}
		$bags = array( $stmt['result']['extensions'] ?? array(), $stmt['context']['extensions'] ?? array(),
			$stmt['object']['definition']['extensions'] ?? array(), $stmt['extensions'] ?? array() );
		$explicit = null;
		foreach ( $bags as $bag ) {
			if ( array_key_exists( 'https://h5p.org/x-api/completed', $bag ) ) {
				if ( false === $bag['https://h5p.org/x-api/completed'] ) { return false; }
				$explicit = true;
			}
		}
		if ( $explicit !== null ) { return $explicit; }
		if ( preg_match( '#/completed$#', $stmt['verb']['id'] ?? '' ) ) { return true; }
		foreach ( $bags as $bag ) {
			foreach ( array( 'https://h5p.org/x-api/progress', 'http://id.tincanapi.com/extension/progress' ) as $key ) {
				if ( ! isset( $bag[ $key ] ) ) { continue; }
				// No guessing between 1% and a full 0..1 ratio. Configure only after
				// checking real events from this activity/library on staging.
				$scale = apply_filters( 'fkhs_completion_progress_scale', null, $key, $stmt, $lesson_id, $content_id );
				$value = $bag[ $key ];
				if ( 'percent' === $scale && $value >= 0 && $value <= 100 ) { return $value === 100 || $value === 100.0; }
				if ( 'ratio' === $scale && $value >= 0 && $value <= 1 ) { return $value === 1 || $value === 1.0; }
			}
		}
		return null;
	}
}
