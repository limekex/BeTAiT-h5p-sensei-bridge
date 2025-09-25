<?php
/**
 * Sensei Bridge: server-side guards for completion and quiz access.
 *
 * Responsibilities:
 * - Never auto-complete lessons from H5P (Sensei owns completion).
 * - Veto completion via 'sensei_user_lesson_end' when "Require H5P pass"
 *   is enabled on the lesson and the user hasn't reached the threshold.
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

		// Only apply when the lesson explicitly requires H5P pass.
		$require_h5p = (bool) get_post_meta( $lesson_id, '_fkhs_require_h5p_pass', true );
		if ( ! $require_h5p ) {
			return;
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
	 * using the latest stored attempt and current (server-side) threshold.
	 *
	 * @param int $user_id   User ID.
	 * @param int $lesson_id Lesson ID.
	 * @return bool
	 */
	public static function user_has_passed_h5p( int $user_id, int $lesson_id ): bool {
		global $wpdb;
		$table     = $wpdb->prefix . FKHS_TABLE;
		$threshold = (float) get_post_meta( $lesson_id, '_fkhs_pass_threshold', true );
		if ( ! $threshold ) { $threshold = 70.0; }

		$content_ids = self::get_h5p_ids_for_lesson( $lesson_id );
		if ( empty( $content_ids ) ) {
			return true; // ingen H5P i leksjonen
		}

		foreach ( $content_ids as $cid ) {
			// "Beste" forsøk: først alle som er passert, deretter høyest score-ratio, så nyest
			$attempt = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT passed, raw_score, max_score
					FROM {$table}
					WHERE user_id=%d AND lesson_id=%d AND content_id=%d
					ORDER BY (passed = 1) DESC,
							CASE WHEN max_score IS NOT NULL AND max_score > 0 THEN raw_score / max_score ELSE 0 END DESC,
							created_at DESC
					LIMIT 1",
					$user_id, $lesson_id, $cid
				),
				ARRAY_A
			);

			if ( ! $attempt ) {
				return false; // ingen forsøk på denne H5P enda
			}

			$ok = false;
			if ( (int) $attempt['passed'] === 1 ) {
				$ok = true;
			} elseif ( is_numeric( $attempt['raw_score'] ) && is_numeric( $attempt['max_score'] ) && (float) $attempt['max_score'] > 0 ) {
				$pct = ( (float) $attempt['raw_score'] / (float) $attempt['max_score'] ) * 100.0;
				$ok  = $pct >= $threshold;
			}

			if ( ! $ok ) {
				return false; // én av flere ikke bestått → hele leksjonen ikke bestått
			}
		}

		return true; // alle H5P er bestått på beste forsøk
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

		// Only enforce when the lesson explicitly requires H5P pass.
		$require_h5p = (bool) get_post_meta( $lesson_id, '_fkhs_require_h5p_pass', true );
		if ( ! $require_h5p ) {
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
