<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FKHS_REST {

	public static function init() {
		add_action('rest_api_init', [__CLASS__, 'register']);
	}

	public static function register() {
		register_rest_route('fkhs/v1', '/h5p-xapi', [
			'methods'  => 'POST',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'callback' => [__CLASS__, 'handle'],
			'args'     => []
		]);
	}

/* 	public static function handle( WP_REST_Request $req ) {
		$data      = $req->get_json_params();
		$user_id   = get_current_user_id();
		$lesson_id = isset($data['lesson_id']) ? (int) $data['lesson_id'] : 0;
		$threshold = isset($data['threshold']) ? (float) $data['threshold'] : 70.0;
		$stmt      = $data['statement'] ?? [];
		$contentId = isset($data['contentId']) ? (int) $data['contentId'] : null;

		if ( ! $user_id || ! $lesson_id || empty($stmt) ) {
			return new WP_REST_Response(['ok'=>false,'reason'=>'missing_params'], 400);
		}

		// Enrolment / eierskap
		if ( ! FKHS_Sensei::user_is_enrolled_in_lesson( $user_id, $lesson_id ) ) {
			// Ikke fatal – men vi logger ikke progresjon
			return new WP_REST_Response(['ok'=>false,'reason'=>'not_enrolled'], 403);
		}

		$result = $stmt['result'] ?? [];
		$raw    = isset($result['score']['raw']) ? (float) $result['score']['raw'] : null;
		$max    = isset($result['score']['max']) ? (float) $result['score']['max'] : null;

		$success   = isset($result['success']) ? (bool) $result['success'] : null;
		$completed = isset($result['completion']) ? (bool) $result['completion'] : null;

		// Hvis vi ikke har 'success', beregn fra threshold
		if ( null === $success && is_numeric($raw) && is_numeric($max) && $max > 0 ) {
			$pct = ($raw / $max) * 100.0;
			$success = ( $pct + 0.0001 ) >= $threshold;
		}

		self::store_attempt( $user_id, $lesson_id, $contentId, $raw, $max, (int)$success, (int)$completed, wp_json_encode($stmt) );

		// Oppdater Sensei (best effort)
		$updated = FKHS_Sensei::update_progress( [
			'user_id'   => $user_id,
			'lesson_id' => $lesson_id,
			'raw'       => $raw,
			'max'       => $max,
			'passed'    => (bool) $success,
			'completed' => (bool) $completed,
		] );

		return new WP_REST_Response([
			'ok'       => true,
			'progress' => (bool) $updated,
		], 200);
	} */

		public static function handle( WP_REST_Request $req ) {
	$data      = $req->get_json_params();
	$user_id   = get_current_user_id();
	$lesson_id = isset($data['lesson_id']) ? (int) $data['lesson_id'] : 0;
	$threshold = isset($data['threshold']) ? (float) $data['threshold'] : 70.0;
	$stmt      = $data['statement'] ?? [];
	$contentId = isset($data['contentId']) ? (int) $data['contentId'] : null;

	if ( ! $user_id || ! $lesson_id || empty($stmt) ) {
		return new WP_REST_Response(['ok'=>false,'reason'=>'missing_params'], 400);
	}

	if ( ! FKHS_Sensei::user_is_enrolled_in_lesson( $user_id, $lesson_id ) ) {
		return new WP_REST_Response(['ok'=>false,'reason'=>'not_enrolled'], 403);
	}

	$result = $stmt['result'] ?? [];
	$raw    = isset($result['score']['raw']) ? (float) $result['score']['raw'] : null;
	$max    = isset($result['score']['max']) ? (float) $result['score']['max'] : null;

	$success   = isset($result['success']) ? (bool) $result['success'] : null;
	$completed = isset($result['completion']) ? (bool) $result['completion'] : null;

	// DEFINISJON: Bestått = score ≥ terskel hvis vi har score.
	// Hvis vi ikke har score, fall tilbake til result.success.
	$passed = false;
	if ( is_numeric($raw) && is_numeric($max) && $max > 0 ) {
		$passed = ( ($raw / $max) * 100.0 ) >= $threshold;
	} elseif ( null !== $success ) {
		$passed = (bool) $success;
	}

	self::store_attempt( $user_id, $lesson_id, $contentId, $raw, $max, (int)$passed, (int)$completed, wp_json_encode($stmt) );

	// IKKE auto-complete her lenger – Sensei styrer fullføring.
	// Vi returnerer bare ok.
	return new WP_REST_Response([
		'ok' => true,
	], 200);
}
	private static function store_attempt( $user_id, $lesson_id, $content_id, $raw, $max, $passed, $completed, $statement_json ) {
		global $wpdb;
		$table = $wpdb->prefix . FKHS_TABLE;
		$wpdb->insert( $table, [
			'user_id'    => $user_id,
			'lesson_id'  => $lesson_id,
			'content_id' => $content_id ?: null,
			'raw_score'  => is_null($raw) ? null : $raw,
			'max_score'  => is_null($max) ? null : $max,
			'passed'     => $passed ? 1 : 0,
			'completed'  => $completed ? 1 : 0,
			'statement'  => $statement_json,
			'created_at' => current_time('mysql'),
		], [
			'%d','%d','%d','%f','%f','%d','%d','%s','%s'
		]);
	}
}
