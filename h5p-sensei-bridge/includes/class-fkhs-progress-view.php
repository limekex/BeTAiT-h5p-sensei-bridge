<?php
/** Template-safe placement of the current lesson's authenticated H5P progress. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FKHS_Progress_View {
    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'register' ), 31 );
        add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function register(): void {
        add_shortcode( 'fkhs_progress', array( __CLASS__, 'render' ) );
        register_block_type( 'fkhs/progress', array(
            'api_version' => 3,
            'editor_style' => 'fkhs-editor',
            'uses_context' => array( 'postId', 'postType' ),
            'supports' => array( 'html' => false, 'align' => array( 'wide', 'full' ), 'spacing' => array( 'margin' => true, 'padding' => true ) ),
            'render_callback' => array( __CLASS__, 'render_block' ),
        ) );
    }

    /** Standalone editor UI: also loaded in Site Editor and template-part editing. */
    public static function enqueue_editor(): void {
        wp_enqueue_style( 'fkhs-editor' );
        $path = 'assets/js/h5p-sensei-progress-editor.js';
        wp_enqueue_script( 'fkhs-progress-editor', FKHS_URL . $path, array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n' ), FKHS_VER . '-' . substr( hash_file( 'sha256', FKHS_DIR . $path ), 0, 12 ), true );
        wp_set_script_translations( 'fkhs-progress-editor', 'h5p-sensei-bridge', FKHS_DIR . 'languages' );
        wp_localize_script( 'fkhs-progress-editor', 'fkhsProgressBlock', array(
            'title' => __( 'H5P progress', 'h5p-sensei-bridge' ),
            'description' => __( 'Shows the learner’s H5P progress in the current lesson. Hidden automatically in lessons without H5P activities and on other pages.', 'h5p-sensei-bridge' ),
        ) );
    }

    private static function visible(): bool {
        return is_singular( 'lesson' ) && ! empty( FKHS_Sensei::get_h5p_ids_for_lesson( (int) get_queried_object_id() ) );
    }

    public static function enqueue(): void {
        if ( ! self::visible() ) { return; }
        $path = 'assets/css/h5p-sensei-progress.css';
        wp_enqueue_style( 'fkhs-progress', FKHS_URL . $path, array(), FKHS_VER . '-' . substr( hash_file( 'sha256', FKHS_DIR . $path ), 0, 12 ) );
    }

    public static function render_block( $attributes, $content, $block ): string {
        // Query-loop items must not display another lesson's current-page status.
        if ( ! self::visible() || ( isset( $block->context['postId'] ) && (int) $block->context['postId'] !== (int) get_queried_object_id() ) ) { return ''; }
        // Render directly: template markup does not necessarily pass through do_shortcode.
        // Ignore saved shortcode fallback to support already placed blocks without duplication.
        return self::markup( get_block_wrapper_attributes( array( 'class' => 'fkhs-progress' ) ) );
    }

    public static function render(): string {
        return self::visible() ? self::markup( 'class="fkhs-progress"' ) : '';
    }

    private static function markup( string $wrapper ): string {
        // No learner, score, nonce or gate decision is embedded in cacheable content.
        return '<section ' . $wrapper . ' data-fkhs-progress aria-label="' . esc_attr__( 'Your H5P progress', 'h5p-sensei-bridge' ) . '">'
            . '<p>' . esc_html__( 'Progress loads when you view this lesson while signed in.', 'h5p-sensei-bridge' ) . '</p>'
            . '<noscript>' . esc_html__( 'Enable JavaScript to view your progress.', 'h5p-sensei-bridge' ) . '</noscript></section>';
    }
}
