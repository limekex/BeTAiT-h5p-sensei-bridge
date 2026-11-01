<?php
/**
 * Sensei Bridge: server-side guards for completion and quiz access.
 *
 * Responsibilities:
 * - Never auto-complete lessons from H5P (Sensei owns completion).
 * - Veto completion via 'sensei_user_lesson_end' when the shared
 *   per-activity progress policy has unmet requirements.
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

	/**
	 * Boot Sensei-related hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Veto after Sensei attempts to complete a lesson.
		add_action( 'sensei_user_lesson_end', array( __CLASS__, 'guard_completion' ), 1, 2 );

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
		$ids = [];
		$content = (string) get_post_field( 'post_content', $lesson_id );

		// Shortcode: [h5p id="123"] / [h5p id=123]
		if ( preg_match_all( '/\[h5p\s+[^\]]*?id=["\']?(\d+)["\']?/i', $content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[] = (int) $id;
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		return $ids;
	}


	/**
	 * Lightweight enrollment check.
	 * Tighten in your environment if you require strict course enrollment.
	 *
	 * @param int $user_id   User ID.
	 * @param int $lesson_id Lesson ID.
	 * @return bool
	 */
	public static function user_is_enrolled_in_lesson( int $user_id, int $lesson_id ): bool {
		// Best-effort default (override with your own policy if needed).
		return (bool) $user_id && (bool) $lesson_id;
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

	/**
	 * Veto completion if the lesson requires H5P pass and the user hasn't passed.
	 * Admin/teachers are exempt.
	 *
	 * @param int $user_id   User completing.
	 * @param int $lesson_id Lesson being completed.
	 * @return void
	 */
	public static function guard_completion( int $user_id, int $lesson_id ) {
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

		// Roll back completion if available in this Sensei version.
		if ( class_exists( 'Sensei_Utils' ) && is_callable( array( 'Sensei_Utils', 'sensei_remove_user_from_lesson' ) ) ) {
			Sensei_Utils::sensei_remove_user_from_lesson( $lesson_id, $user_id );
		}

		// If this is the current viewer, redirect back to the lesson with a notice.
		if ( $user_id === get_current_user_id() && ! headers_sent() ) {
			wp_safe_redirect(
				add_query_arg( 'fkhs_not_passed', '1', get_permalink( $lesson_id ) )
			);
			exit;
		}
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
		$items = FKHS_Progress::get_items( $user_id, $lesson_id, false );
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
				// Simple, theme-agnostic bubble. Replace with your own banner/notice system if desired.
				echo '<div style="position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);background:#222;color:#fff;padding:.75rem 1rem;border-radius:.5rem;z-index:9999">'
					. esc_html( $msg )
					. '</div>';
			}
		);
	}
}
