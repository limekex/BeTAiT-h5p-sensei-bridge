<?php
/** Placer for the current lesson's authenticated, client-rendered H5P progress. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FKHS_Progress_View {
    public static function init(): void {
        add_action( 'init', static function () {
            add_shortcode( 'fkhs_progress', array( __CLASS__, 'render' ) );
        } );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function enqueue(): void {
        if ( ! is_singular( 'lesson' ) ) { return; }
        $path = 'assets/css/h5p-sensei-progress.css';
        wp_enqueue_style( 'fkhs-progress', FKHS_URL . $path, array(), FKHS_VER . '-' . substr( hash_file( 'sha256', FKHS_DIR . $path ), 0, 12 ) );
    }

    public static function render(): string {
        if ( ! is_singular( 'lesson' ) ) {
            return '<p>' . esc_html__( 'H5P progress is available inside a Sensei lesson.', 'h5p-sensei-bridge' ) . '</p>';
        }
        // No learner, score, nonce or gate decision is embedded in cacheable content.
        // The existing bridge makes one authenticated request for all placements.
        return '<section class="fkhs-progress" data-fkhs-progress aria-label="' . esc_attr__( 'Your H5P progress', 'h5p-sensei-bridge' ) . '">'
            . '<p>' . esc_html__( 'Progress loads when you view this lesson while signed in.', 'h5p-sensei-bridge' ) . '</p>'
            . '<noscript>' . esc_html__( 'Enable JavaScript to view your progress.', 'h5p-sensei-bridge' ) . '</noscript></section>';
    }
}
