<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sensei-bridge:
 * - Ingen auto-complete fra H5P.
 * - Server-guard på sensei_user_lesson_end som ruller tilbake hvis H5P ikke er bestått,
 *   men kun når “Krev H5P bestått” er aktivert på leksjonen, og ikke for admin/lærere.
 */
class FKHS_Sensei {

	public static function init() {
		// Når Sensei prøver å fullføre leksjon, valider H5P-kravet først
		add_action( 'sensei_user_lesson_end', [ __CLASS__, 'guard_completion' ], 1, 2 );
		// Frontend notice hvis rullet tilbake
		add_action( 'template_redirect', [ __CLASS__, 'maybe_add_notice' ] );
	}

	public static function user_is_enrolled_in_lesson( int $user_id, int $lesson_id ): bool {
		// For MVP: anser innlogget elev med tilgang som ok.
		// Du kan stramme inn ved å sjekke kurs-tilknytning.
		if ( ! $user_id || ! $lesson_id ) return false;
		return true;
	}

	/**
	 * IKKE auto-fullfør lenger. Vi bare lagrer forsøk.
	 * Returnerer alltid false for nå (ingen direkte progresjonsoppdatering).
	 */
	public static function update_progress( array $args ): bool {
		return false;
	}

	public static function guard_completion( int $user_id, int $lesson_id ) {
		// Respekter flagget på leksjonen
		$require_pass = (bool) get_post_meta( $lesson_id, '_fkhs_require_pass', true );
		if ( ! $require_pass ) return;

		// Unntak: administrator/undervisningsroller kan overstyre
		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_sensei_grades' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . FKHS_TABLE;

		// Siste forsøk registrert for denne brukeren og leksjonen
		$attempt = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id=%d AND lesson_id=%d ORDER BY created_at DESC LIMIT 1",
			$user_id, $lesson_id
		), ARRAY_A );

		$threshold = (float) get_post_meta( $lesson_id, '_fkhs_pass_threshold', true );
		if ( ! $threshold ) $threshold = 70.0;

		$passed = false;
		if ( $attempt ) {
			// Definisjon: bestått = score ≥ terskel (hvis vi har tall)
			if ( is_numeric($attempt['raw_score']) && is_numeric($attempt['max_score']) && $attempt['max_score'] > 0 ) {
				$passed = ( ($attempt['raw_score'] / $attempt['max_score']) * 100 ) >= $threshold;
			} else {
				// Fallback: bruk lagret "passed" bool hvis score ikke finnes
				$passed = ( (int) $attempt['passed'] === 1 );
			}
		}

		// Ikke bestått? Rull tilbake fullføring øyeblikkelig
		if ( ! $passed && class_exists('Sensei_Utils') && is_callable( ['Sensei_Utils','sensei_remove_user_from_lesson'] ) ) {
			Sensei_Utils::sensei_remove_user_from_lesson( $lesson_id, $user_id );

			// Vennlig redirect m/notice til eleven selv
			if ( $user_id === get_current_user_id() && ! headers_sent() ) {
				wp_safe_redirect( add_query_arg( 'fkhs_not_passed', '1', get_permalink( $lesson_id ) ) );
				exit;
			}
		}
	}

	public static function maybe_add_notice() {
		if ( isset($_GET['fkhs_not_passed']) && is_singular('lesson') ) {
			add_action('wp_footer', function () {
				echo '<div style="position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);background:#222;color:#fff;padding:.75rem 1rem;border-radius:.5rem;z-index:9999">'
					. esc_html__('Du må bestå H5P-aktiviteten før leksjonen kan fullføres.', 'fkhs')
					. '</div>';
			});
		}
	}
}
