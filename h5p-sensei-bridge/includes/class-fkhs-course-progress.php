<?php
/** Current learner's bounded, read-only H5P course overview. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FKHS_Course_Progress {
    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'register' ), 32 );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function register(): void {
        add_shortcode( 'fkhs_course_progress', array( __CLASS__, 'render' ) );
        register_block_type( 'fkhs/course-progress', array(
            'api_version' => 3, 'editor_style' => 'fkhs-editor',
            'uses_context' => array( 'postId', 'postType' ),
            'supports' => array( 'html' => false, 'align' => array( 'wide', 'full' ), 'spacing' => array( 'margin' => true, 'padding' => true ) ),
            'render_callback' => array( __CLASS__, 'render_block' ),
        ) );
    }

    public static function routes(): void {
        register_rest_route( 'fkhs/v1', '/course-progress', array(
            array(
                'methods' => 'GET', 'permission_callback' => static function () { return is_user_logged_in(); },
                'callback' => array( __CLASS__, 'status' ),
                'args' => array( 'course_id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
            ),
            'schema' => array( __CLASS__, 'schema' ),
        ) );
    }

    public static function schema(): array {
        $counts = array_fill_keys( array( 'required_count', 'met_count', 'optional_count', 'activity_count' ), array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 500 ) );
        $lesson = array_merge( $counts, array(
            'lesson_id' => array( 'type' => 'integer', 'minimum' => 1 ),
            'title' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string', 'format' => 'uri' ),
        ) );
        $properties = array_merge( $counts, array(
            'ok' => array( 'type' => 'boolean', 'enum' => array( true ) ),
            'course_id' => array( 'type' => 'integer', 'minimum' => 1 ),
            'limited' => array( 'type' => 'boolean' ),
            'lessons' => array( 'type' => 'array', 'maxItems' => 200, 'items' => array(
                'type' => 'object', 'properties' => $lesson, 'required' => array_keys( $lesson ), 'additionalProperties' => false,
            ) ),
        ) );
        return array( '$schema' => 'http://json-schema.org/draft-04/schema#', 'title' => 'fkhs-course-progress',
            'type' => 'object', 'properties' => $properties, 'required' => array_keys( $properties ), 'additionalProperties' => false );
    }

    private static function response( array $data, int $status = 200 ): WP_REST_Response {
        $response = new WP_REST_Response( $data, $status );
        $response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
        $response->header( 'Pragma', 'no-cache' );
        return $response;
    }

    /** Bounded equivalent of Sensei's published membership/order, checked again per lesson. */
    public static function lessons( int $course_id ): array {
        global $wpdb;
        $suppressed = $wpdb->suppress_errors( true );
        try {
            $query = new WP_Query( array(
                'post_type' => 'lesson', 'post_status' => 'publish', 'posts_per_page' => 201,
                'no_found_rows' => true, 'orderby' => array( 'date' => 'ASC', 'ID' => 'ASC' ),
                'meta_query' => array( array( 'key' => '_lesson_course', 'value' => $course_id ) ),
                'update_post_term_cache' => false,
            ) );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Lesson storage unavailable.' ); }
            $lessons = $query->posts;
            if ( count( $lessons ) > 200 ) { throw new LengthException( 'Too many lessons.' ); }
            foreach ( $lessons as $lesson ) {
                if ( $lesson->post_type !== 'lesson' || $lesson->post_status !== 'publish' || (int) get_post_meta( $lesson->ID, '_lesson_course', true ) !== $course_id ) {
                    throw new RuntimeException( 'Unexpected lesson scope.' );
                }
            }
            usort( $lessons, static function ( $a, $b ) use ( $course_id ) {
                $a_order = (int) get_post_meta( $a->ID, '_order_' . $course_id, true ) ?: 100000;
                $b_order = (int) get_post_meta( $b->ID, '_order_' . $course_id, true ) ?: 100000;
                return ( $a_order <=> $b_order ) ?: ( strcmp( $a->post_date, $b->post_date ) ?: ( $a->ID <=> $b->ID ) );
            } );
            // Honor Sensei's final lesson ordering/subset hook, but never expand to
            // foreign, duplicate or unpublished lessons through a custom filter.
            $lessons = apply_filters( 'sensei_course_get_lessons', $lessons, $course_id );
            if ( ! is_array( $lessons ) ) { throw new RuntimeException( 'Invalid lesson list.' ); }
            if ( count( $lessons ) > 200 ) { throw new LengthException( 'Too many lessons.' ); }
            $seen = array();
            foreach ( $lessons as $lesson ) {
                if ( ! $lesson instanceof WP_Post || isset( $seen[$lesson->ID] ) || $lesson->post_type !== 'lesson' || $lesson->post_status !== 'publish' || (int) get_post_meta( $lesson->ID, '_lesson_course', true ) !== $course_id ) {
                    throw new RuntimeException( 'Unexpected filtered lesson scope.' );
                }
                $seen[$lesson->ID] = true;
            }
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Lesson metadata unavailable.' ); }
            return array_values( $lessons );
        } finally { $wpdb->suppress_errors( $suppressed ); }
    }

    public static function status( WP_REST_Request $request ): WP_REST_Response {
        $value = $request->get_param( 'course_id' );
        $user_id = get_current_user_id();
        if ( ! $user_id ) { return self::response( array( 'ok' => false, 'reason' => 'authentication_required' ), 401 ); }
        if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $value ) || ! filter_var( $value, FILTER_VALIDATE_INT ) ) {
            return self::response( array( 'ok' => false, 'reason' => 'invalid_course_id' ), 400 );
        }
        $course_id = (int) $value;
        if ( ! FKHS_Sensei::access_api_available() || ! class_exists( 'H5P_Plugin' ) || ! shortcode_exists( 'h5p' ) ) {
            return self::response( array( 'ok' => false, 'reason' => 'dependencies_unavailable' ), 503 );
        }
        $course = get_post( $course_id );
        $editor = $course && ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'edit_post', $course_id ) );
        if ( ! $course || $course->post_type !== 'course' || in_array( $course->post_status, array( 'trash', 'auto-draft' ), true ) ||
            ( ! $editor && ( $course->post_status !== 'publish' || post_password_required( $course_id ) || ! Sensei_Course::is_user_enrolled( $course_id, $user_id ) ) ) ) {
            return self::response( array( 'ok' => false, 'reason' => 'course_access_denied' ), 403 );
        }
        try {
            $lessons = self::lessons( $course_id );
            $allowed = array(); $limited = false;
            foreach ( $lessons as $lesson ) {
                if ( (int) Sensei()->lesson->get_course_id( $lesson->ID ) !== $course_id ) { throw new RuntimeException( 'Unexpected Sensei course.' ); }
                if ( FKHS_Sensei::user_can_access_lesson( $user_id, $lesson->ID ) ) { $allowed[$lesson->ID] = $lesson; }
                else { $limited = true; }
            }
            $snapshots = FKHS_Progress::get_lesson_snapshots( $user_id, array_keys( $allowed ) );
            $result = array( 'ok' => true, 'course_id' => $course_id, 'required_count' => 0, 'met_count' => 0, 'optional_count' => 0, 'activity_count' => 0, 'limited' => $limited, 'lessons' => array() );
            foreach ( $snapshots as $lesson_id => $items ) {
                if ( ! $items ) { continue; }
                $row = array( 'lesson_id' => $lesson_id, 'title' => get_the_title( $lesson_id ), 'url' => get_permalink( $lesson_id ), 'required_count' => 0, 'met_count' => 0, 'optional_count' => 0, 'activity_count' => count( $items ) );
                foreach ( $items as $item ) {
                    if ( $item['counts_for_gate'] ) { ++$row['required_count']; if ( $item['requirement_met'] ) { ++$row['met_count']; } }
                    else { ++$row['optional_count']; }
                }
                foreach ( array( 'required_count', 'met_count', 'optional_count', 'activity_count' ) as $key ) { $result[$key] += $row[$key]; }
                $result['lessons'][] = $row;
            }
            return self::response( $result );
        } catch ( LengthException $error ) {
            return self::response( array( 'ok' => false, 'reason' => 'course_too_large' ), 422 );
        } catch ( Throwable $error ) {
            return self::response( array( 'ok' => false, 'reason' => 'progress_unavailable' ), 503 );
        }
    }

    private static function visible(): bool {
        if ( ! is_singular( 'course' ) ) { return false; }
        try {
            foreach ( self::lessons( (int) get_queried_object_id() ) as $lesson ) {
                if ( FKHS_Sensei::get_h5p_ids_for_lesson( $lesson->ID ) ) { return true; }
            }
            return false;
        } catch ( Throwable $error ) { return true; } // Surface a read/size error through the authenticated API.
    }

    public static function enqueue(): void {
        if ( ! self::visible() ) { return; }
        $css = 'assets/css/h5p-sensei-progress.css';
        wp_enqueue_style( 'fkhs-progress', FKHS_URL . $css, array(), FKHS_VER . '-' . substr( hash_file( 'sha256', FKHS_DIR . $css ), 0, 12 ) );
        if ( ! is_user_logged_in() ) { return; }
        $js = 'assets/js/h5p-sensei-course-progress.js';
        wp_enqueue_script( 'fkhs-course-progress', FKHS_URL . $js, array( 'wp-i18n' ), FKHS_VER . '-' . substr( hash_file( 'sha256', FKHS_DIR . $js ), 0, 12 ), true );
        wp_set_script_translations( 'fkhs-course-progress', 'h5p-sensei-bridge', FKHS_DIR . 'languages' );
        wp_localize_script( 'fkhs-course-progress', 'fkhsCourseProgress', array(
            'courseId' => get_queried_object_id(), 'url' => rest_url( 'fkhs/v1/course-progress' ), 'nonce' => wp_create_nonce( 'wp_rest' ),
            'loginUrl' => wp_login_url( get_permalink( get_queried_object_id() ) ),
            'labels' => array(
                'title' => __( 'Your H5P progress in this course', 'h5p-sensei-bridge' ),
                'loading' => __( 'Checking course progress…', 'h5p-sensei-bridge' ),
                'refresh' => __( 'Refresh progress', 'h5p-sensei-bridge' ),
                'error' => __( 'Course progress could not be loaded. Try again, or open a lesson to check its progress.', 'h5p-sensei-bridge' ),
                'auth' => __( 'Sign in with access to this course, then reload this page to view your progress.', 'h5p-sensei-bridge' ),
                'login' => __( 'Sign in in a new tab', 'h5p-sensei-bridge' ),
                'count' => __( '%1$d of %2$d H5P requirements met', 'h5p-sensei-bridge' ),
                'optional' => __( 'Optional activities: %d', 'h5p-sensei-bridge' ),
                'noRequired' => __( 'No H5P activities currently block progression.', 'h5p-sensei-bridge' ),
                'limited' => __( 'Only lessons you can currently access are included. More H5P requirements may appear as lessons become available.', 'h5p-sensei-bridge' ),
                'scope' => __( 'This overview shows H5P requirements. Sensei tracks lesson and course completion separately.', 'h5p-sensei-bridge' ),
                'optionalHelp' => __( 'Optional activities must be completed once you have started an attempt.', 'h5p-sensei-bridge' ),
            ),
        ) );
    }

    public static function render_block( $attributes, $content, $block ): string {
        if ( ! self::visible() || ( isset( $block->context['postId'] ) && (int) $block->context['postId'] !== (int) get_queried_object_id() ) ) { return ''; }
        return self::markup( get_block_wrapper_attributes( array( 'class' => 'fkhs-progress' ) ) );
    }

    public static function render(): string { return self::visible() ? self::markup( 'class="fkhs-progress"' ) : ''; }

    private static function markup( string $wrapper ): string {
        return '<section ' . $wrapper . ' data-fkhs-course-progress aria-label="' . esc_attr__( 'Your H5P progress in this course', 'h5p-sensei-bridge' ) . '">'
            . '<p>' . esc_html__( 'Sign in with access to this course to view your H5P progress.', 'h5p-sensei-bridge' ) . '</p>'
            . '<noscript>' . esc_html__( 'Enable JavaScript to view your progress.', 'h5p-sensei-bridge' ) . '</noscript></section>';
    }
}
