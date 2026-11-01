<?php
/**
 * Helper: threshold resolution (backward compatible).
 *
 * Order of precedence:
 *   1) Per-H5P:  _fkhs_threshold_map[content_id]   (0–100, 0 = completion-only)
 *   2) Legacy per-H5P: _fkhs_h5p_thresholds[content_id]
 *   3) Lesson-level: _fkhs_pass_threshold
 *   4) Default: 70.0
 *
 * Filters:
 *   - fkhs_threshold_for_item( float $thr, int $lesson_id, int $content_id, int $user_id )
 *   - fkhs_threshold_for_content( float $thr, int $lesson_id, int $content_id, int $user_id ) // alias for BC with older snippets
 *   - fkhs_threshold_for_lesson( float $thr, int $lesson_id, int $user_id )
 */
if ( ! class_exists( 'FKHS_Thresholds' ) ) {
	class FKHS_Thresholds {

		/** @var array<string,float> */
		private static $cache = array(); // key: "{$lesson_id}:{$content_id}:{$user_id}"

		public static function get_effective_threshold( int $lesson_id, ?int $content_id, int $user_id ): float {
			$key = $lesson_id . ':' . ((string) ($content_id ?? 0)) . ':' . $user_id;
			if ( isset( self::$cache[ $key ] ) ) {
				return self::$cache[ $key ];
			}

			// 1) New per-H5P map
			$map = get_post_meta( $lesson_id, '_fkhs_threshold_map', true );
			if ( is_array( $map ) && $content_id && isset( $map[ $content_id ] ) && is_numeric( $map[ $content_id ] ) ) {
				$thr = max( 0.0, min( 100.0, (float) $map[ $content_id ] ) );
				$thr = (float) apply_filters( 'fkhs_threshold_for_item', $thr, $lesson_id, $content_id, $user_id );
				$thr = (float) apply_filters( 'fkhs_threshold_for_content', $thr, $lesson_id, $content_id, $user_id );
				return self::$cache[ $key ] = max( 0.0, min( 100.0, (float) apply_filters( 'fkhs_threshold_for_lesson', $thr, $lesson_id, $user_id ) ) );
			}

			// 2) Legacy per-H5P map
			$legacy = get_post_meta( $lesson_id, '_fkhs_h5p_thresholds', true );
			if ( is_array( $legacy ) && $content_id && isset( $legacy[ $content_id ] ) && is_numeric( $legacy[ $content_id ] ) ) {
				$thr = max( 0.0, min( 100.0, (float) $legacy[ $content_id ] ) );
				$thr = (float) apply_filters( 'fkhs_threshold_for_item', $thr, $lesson_id, $content_id, $user_id );
				$thr = (float) apply_filters( 'fkhs_threshold_for_content', $thr, $lesson_id, $content_id, $user_id );
				return self::$cache[ $key ] = max( 0.0, min( 100.0, (float) apply_filters( 'fkhs_threshold_for_lesson', $thr, $lesson_id, $user_id ) ) );
			}

			// 3) Lesson-level (BC)
			$lesson_thr = get_post_meta( $lesson_id, '_fkhs_pass_threshold', true );
			$lesson_thr = is_numeric( $lesson_thr ) ? (float) $lesson_thr : 70.0;
			$lesson_thr = max( 0.0, min( 100.0, (float) apply_filters( 'fkhs_threshold_for_lesson', $lesson_thr, $lesson_id, $user_id ) ) );

			return self::$cache[ $key ] = $lesson_thr;
		}
	}
}
