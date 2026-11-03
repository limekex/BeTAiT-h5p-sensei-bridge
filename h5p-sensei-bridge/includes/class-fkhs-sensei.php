<?php
/**
 * Sensei Bridge: server-side guards for completion and quiz access.
 *
 * Responsibilities:
 * - Never auto-complete lessons from H5P (Sensei owns completion).
 * - Gate native completion requests before Sensei writes progress.
 * - Correct rejected programmatic completion without deleting learner work.
 * - Gate quiz access until the H5P requirement is satisfied.
 * - Show lightweight front-end notices on redirect (optional UX sugar).
 *
 * @package H5P_Sensei_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class FKHS_Sensei
 */
class FKHS_Sensei {

	/** Rejected completion pairs in this request, for legacy notification filtering. */
	private static $blocked_completions = array();

	/**
	 * Boot Sensei-related hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Sensei handles native lesson-complete POSTs on wp at priority 10.
		// Reject first, before completion or destructive rollback can touch progress.
		add_action( 'wp', array( __CLASS__, 'gate_lesson_completion_request' ), 1 );

		// Other callers are corrected after writing; this action cannot veto earlier listeners.
		add_action( 'sensei_user_lesson_end', array( __CLASS__, 'guard_completion' ), 1, 2 );
		add_filter( 'sensei_send_emails', array( __CLASS__, 'filter_completion_email' ), 10, 5 );

		// UX notices (shown after redirects) and quiz gate.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_add_notice' ) );
		add_action( 'template_redirect', array( __CLASS__, 'gate_quiz_access' ), 1 );
	}

	/**
	 * Find all H5P content IDs referenced in a lesson's content.
	 * Currently scans [h5p id="123"] shortcodes (most common).
	 *
	 * @param int $lesson_id
	 * @return int[] Unique content IDs.
	 */
	public static function get_h5p_ids_for_lesson( int $lesson_id ): array {
		return self::get_h5p_ids_from_content( (string) get_post_field( 'post_content', $lesson_id ) );
	}

	/** Shared discovery for persisted lessons and pending editor content. */
	public static function get_h5p_ids_from_content( string $content ): array {
		$ids = [];

		// Use WordPress parsing: escaped shortcodes and attributes such as data-id
		// must not grant activity membership.
		if ( preg_match_all( '/' . get_shortcode_regex( array( 'h5p' ) ) . '/s', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( '[' === $match[1] && ']' === $match[6] ) {
					continue;
				}
				$attributes = shortcode_parse_atts( $match[3] );
				$id = is_array( $attributes ) ? ( $attributes['id'] ?? null ) : null;
				if ( is_string( $id ) && preg_match( '/^[1-9][0-9]*$/D', $id ) && false !== filter_var( $id, FILTER_VALIDATE_INT ) ) {
					$ids[] = (int) $id;
				}
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		return $ids;
	}


	/**
	 * Actual course enrollment; preview/open-course access alone is not enrollment.
	 *
	 * @param int $user_id   User ID.
	 * @param int $lesson_id Lesson ID.
	 * @return bool
	 */
	public static function user_is_enrolled_in_lesson( int $user_id, int $lesson_id ): bool {
		if ( $user_id <= 0 || $lesson_id <= 0 || 'lesson' !== get_post_type( $lesson_id ) || ! self::access_api_available() ) {
			return false;
		}
		$course_id = (int) Sensei()->lesson->get_course_id( $lesson_id );
		return $course_id > 0 && 'course' === get_post_type( $course_id )
			&& (bool) Sensei_Course::is_user_enrolled( $course_id, $user_id );
	}

	/** Fail closed when the supported Sensei access API is unavailable. */
	public static function access_api_available(): bool {
		if ( ! function_exists( 'Sensei' ) || ! function_exists( 'sensei_can_user_view_lesson' )
			|| ! is_callable( array( 'Sensei_Course', 'is_user_enrolled' ) ) ) {
			return false;
		}
		$sensei = Sensei();
		return isset( $sensei->lesson ) && is_callable( array( $sensei->lesson, 'get_course_id' ) );
	}

	/** Shared read/write access for the current learner's bridge records. */
	public static function user_can_access_lesson( int $user_id, int $lesson_id ): bool {
		if ( $user_id <= 0 || $lesson_id <= 0 || ! self::access_api_available()
			|| 'lesson' !== get_post_type( $lesson_id )
			|| in_array( get_post_status( $lesson_id ), array( 'trash', 'auto-draft' ), true ) ) {
			return false;
		}
		// Preview is scoped to this lesson's editors, not every teacher's role.
		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'edit_post', $lesson_id ) ) {
			return true;
		}
		$course_id = (int) Sensei()->lesson->get_course_id( $lesson_id );
		if ( 'publish' !== get_post_status( $lesson_id ) || 'publish' !== get_post_status( $course_id )
			|| post_password_required( $lesson_id ) || post_password_required( $course_id ) ) {
			return false;
		}
		return self::user_is_enrolled_in_lesson( $user_id, $lesson_id )
			&& (bool) sensei_can_user_view_lesson( $lesson_id, $user_id );
	}

	/**
	 * No longer auto-complete in response to H5P. Sensei owns completion.
	 * This remains for compatibility with earlier flow; always returns false.
	 *
	 * @param array $args Attempt context (ignored).
	 * @return bool
	 */
	public static function update_progress( array $args ): bool {
		return false;
	}

	/** Reject native completion requests before Sensei writes progress. */
	public static function gate_lesson_completion_request() {
		if ( ! is_user_logged_in() || ! is_singular( 'lesson' )
			|| ! isset( $_POST['quiz_action'], $_POST['woothemes_sensei_complete_lesson_noonce'] )
			|| ! is_string( $_POST['quiz_action'] ) || 'lesson-complete' !== sanitize_text_field( wp_unslash( $_POST['quiz_action'] ) )
			|| ! is_string( $_POST['woothemes_sensei_complete_lesson_noonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woothemes_sensei_complete_lesson_noonce'] ) ), 'woothemes_sensei_complete_lesson_noonce' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$lesson_id = (int) get_queried_object_id();
		foreach ( (array) apply_filters( 'fkhs_completion_exempt_caps', array( 'manage_sensei_grades', 'manage_options' ) ) as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				return;
			}
		}
		if ( self::user_has_passed_h5p( $user_id, $lesson_id ) ) {
			return;
		}

		// Also neutralize the action if output has made a redirect impossible.
		unset( $_POST['quiz_action'] );
		if ( ! headers_sent() ) {
			wp_safe_redirect( add_query_arg( 'fkhs_not_passed', '1', get_permalink( $lesson_id ) ) );
			exit;
		}
	}

	/**
	 * Correct completion if the lesson requires H5P pass and the user has not passed.
	 * Admin/teachers are exempt.
	 *
	 * @param int $user_id   User completing.
	 * @param int $lesson_id Lesson being completed.
	 * @return void
	 */
	public static function guard_completion( int $user_id, int $lesson_id ) {
		$key = $user_id . ':' . $lesson_id;
		unset( self::$blocked_completions[ $key ] );
		// Capability exemptions: allow privileged users to override.
		$exempt_caps = apply_filters(
			'fkhs_completion_exempt_caps',
			array( 'manage_sensei_grades', 'manage_options' )
		);
		foreach ( (array) $exempt_caps as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				return;
			}
		}

		// Has the user passed according to our stored attempts/threshold?
		if ( self::user_has_passed_h5p( $user_id, $lesson_id ) ) {
			return; // OK to complete.
		}

		self::$blocked_completions[ $key ] = true;
		self::reopen_progress( $user_id, $lesson_id );
		// No redirect or exit: this hook also runs in REST, CLI and background jobs.
	}

	/**
	 * Reopen status only. Sensei's remove/reset APIs delete answers and grades.
	 * Both repositories are needed: table-backed quiz status can independently
	 * make user_completed_lesson() return true even after reopening the lesson.
	 *
	 * @throws RuntimeException When the API is unsupported or correction does not persist.
	 */
	private static function reopen_progress( int $user_id, int $lesson_id ): void {
		$sensei = function_exists( 'Sensei' ) ? Sensei() : null;
		$lesson_repository = $sensei->lesson_progress_repository ?? null;
		$quiz_repository = $sensei->quiz_progress_repository ?? null;
		if ( ! is_callable( array( $lesson_repository, 'get' ) ) || ! is_callable( array( $lesson_repository, 'save' ) )
			|| ! is_callable( array( $sensei->lesson ?? null, 'lesson_quizzes' ) ) ) {
			throw new RuntimeException( 'H5P Bridge cannot correct completion: unsupported Sensei progress API.' );
		}
		$quiz_id = (int) $sensei->lesson->lesson_quizzes( $lesson_id );
		if ( $quiz_id && ( ! is_callable( array( $quiz_repository, 'get' ) ) || ! is_callable( array( $quiz_repository, 'save' ) ) ) ) {
			throw new RuntimeException( 'H5P Bridge cannot correct completion: unsupported Sensei quiz progress API.' );
		}
		// Read both before saving: the comments backend shares one progress row.
		$lesson_progress = $lesson_repository->get( $lesson_id, $user_id );
		$quiz_progress = $quiz_id ? $quiz_repository->get( $quiz_id, $user_id ) : null;
		foreach ( array( $lesson_progress, $quiz_progress ) as $progress ) {
			if ( $progress && ( ! is_callable( array( $progress, 'start' ) ) || ! is_callable( array( $progress, 'get_started_at' ) ) ) ) {
				throw new RuntimeException( 'H5P Bridge cannot correct completion: unsupported Sensei progress model.' );
			}
		}
		if ( $quiz_progress ) {
			$quiz_progress->start( $quiz_progress->get_started_at() );
			$quiz_repository->save( $quiz_progress );
		}
		if ( $lesson_progress ) {
			$lesson_progress->start( $lesson_progress->get_started_at() );
			$lesson_repository->save( $lesson_progress );
		}
		// Some Sensei repositories do not throw on a failed SQL update.
		// Re-read persisted status rather than reporting a successful correction.
		foreach ( array( array( $lesson_repository, $lesson_id, $lesson_progress ), array( $quiz_repository, $quiz_id, $quiz_progress ) ) as $target ) {
			if ( ! $target[2] ) {
				continue;
			}
			$stored = $target[0]->get( $target[1], $user_id );
			if ( ! $stored || ! is_callable( array( $stored, 'get_status' ) ) || 'in-progress' !== $stored->get_status() ) {
				throw new RuntimeException( 'H5P Bridge could not persist the completion correction.' );
			}
		}
	}

	/** Legacy completion mail does not check status; modern Sensei mail does. */
	public static function filter_completion_email( $send, $to, $subject, $message, $type ) {
		global $sensei_email_data;
		if ( 'legacy-email' !== $type || ! doing_action( 'sensei_user_lesson_end' )
			|| 'teacher-completed-lesson' !== ( $sensei_email_data['template'] ?? '' ) ) {
			return $send;
		}
		$key = (int) ( $sensei_email_data['learner_id'] ?? 0 ) . ':' . (int) ( $sensei_email_data['lesson_id'] ?? 0 );
		return isset( self::$blocked_completions[ $key ] ) ? false : $send;
	}


	/**
	 * Determine if the user has passed the H5P requirement for a lesson
	 * using the same per-item policy as the REST status response.
	 *
	 * @param int $user_id   User ID.
	 * @param int $lesson_id Lesson ID.
	 * @return bool
	 */
	public static function user_has_passed_h5p( int $user_id, int $lesson_id ): bool {
		try {
			$items = FKHS_Progress::get_items( $user_id, $lesson_id, false );
		} catch ( RuntimeException $error ) {
			return false;
		}
		$gate  = FKHS_Progress::summarize( $items );
		return $gate['passed'];
	}



	/**
	 * Gate access to the quiz until H5P has been passed for the owning lesson.
	 * Admin/teachers are exempt.
	 *
	 * @return void
	 */
	public static function gate_quiz_access() {
		if ( ! is_user_logged_in() || ! is_singular( 'quiz' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		// Capability exemptions (same as completion guard).
		$exempt_caps = apply_filters(
			'fkhs_quiz_gate_exempt_caps',
			array( 'manage_sensei_grades', 'manage_options' )
		);
		foreach ( (array) $exempt_caps as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				return;
			}
		}

		$quiz_id = get_the_ID();

		// Find the owning lesson for this quiz.
		$lesson_id = (int) get_post_meta( $quiz_id, '_lesson_id', true );
		if ( ! $lesson_id && function_exists( 'Sensei' ) && isset( Sensei()->quiz ) && method_exists( Sensei()->quiz, 'get_lesson_id' ) ) {
			$lesson_id = (int) Sensei()->quiz->get_lesson_id( $quiz_id );
		}
		if ( ! $lesson_id ) {
			return;
		}

		// Block if not passed yet; redirect back with a notice.
		if ( ! self::user_has_passed_h5p( $user_id, $lesson_id ) ) {
			if ( ! headers_sent() ) {
				wp_safe_redirect(
					add_query_arg( 'fkhs_quiz_blocked', '1', get_permalink( $lesson_id ) )
				);
				exit;
			}
		}
	}

	/**
	 * Output minimal notices on the lesson page after redirects.
	 * These are intentionally simple (no dependencies on theme styles).
	 *
	 * @return void
	 */
	public static function maybe_add_notice() {
		if ( ! is_singular( 'lesson' ) ) {
			return;
		}

		$msg = '';

		if ( isset( $_GET['fkhs_not_passed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg = __( 'You must pass the H5P activity before the lesson can be completed.', 'h5p-sensei-bridge' );
		} elseif ( isset( $_GET['fkhs_quiz_blocked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg = __( 'You must pass the H5P activity before you can take the quiz.', 'h5p-sensei-bridge' );
		}

		/**
		 * Filter the notice text. Return empty string to suppress it.
		 *
		 * @param string $msg Current message.
		 */
		$msg = apply_filters( 'fkhs_notice_text', $msg );

		if ( ! $msg ) {
			return;
		}

		add_action(
			'wp_footer',
			static function () use ( $msg ) {
				// The bridge moves this into its feedback area; without JS it remains in normal flow.
				echo '<div id="fkhs-redirect-notice" role="status" style="background:#222;color:#fff;padding:.75rem 1rem;margin:.75rem 0;border-radius:.5rem">'
					. esc_html( $msg )
					. '</div>';
			}
		);
	}
}
