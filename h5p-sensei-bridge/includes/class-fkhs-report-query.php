<?php
/** Bounded, authorized reads of attempt history. Never select the xAPI payload. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FKHS_Report_Query {
    public static function can_view(): bool {
        return current_user_can( 'manage_options' ) || (
            class_exists( 'Sensei_Teacher' ) && current_user_can( 'manage_sensei_grades' ) && current_user_can( 'edit_courses' )
        );
    }

    public static function register(): void {
        register_rest_route( 'fkhs/v1', '/report', array(
            'methods' => 'GET',
            'permission_callback' => static function () {
                return self::can_view() ? true : new WP_Error( 'fkhs_report_forbidden', __( 'You cannot view this report.', 'h5p-sensei-bridge' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
            },
            'callback' => static function ( WP_REST_Request $request ) {
                $result = self::get_page( $request->get_query_params() );
                $response = is_wp_error( $result ) ? rest_convert_error_to_response( $result ) : new WP_REST_Response( $result );
                $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
                return $response;
            },
        ) );
    }

    /** Reject invalid input rather than silently running a different search. */
    public static function normalize( array $input ) {
        $defaults = array( 'page'=>1, 'per_page'=>50, 'sort'=>'date', 'direction'=>'desc', 'user'=>'', 'lesson'=>'', 'dateFrom'=>'', 'dateTo'=>'', 'scoreCmp'=>'any', 'scoreVal'=>'', 'passed'=>'any', 'completed'=>'any', 'content_id'=>0 );
        $args = array_intersect_key( $input, $defaults ) + $defaults;
        foreach ( $args as $key => $value ) {
            if ( ! is_scalar( $value ) ) { return self::invalid(); }
            $args[ $key ] = trim( (string) $value );
        }
        foreach ( array( 'page'=>array(1,1000000), 'per_page'=>array(1,200), 'content_id'=>array(0,PHP_INT_MAX) ) as $key => $bounds ) {
            if ( ! preg_match( '/^\d+$/D', $args[$key] ) || (float) $args[$key] < $bounds[0] || (float) $args[$key] > $bounds[1] ) { return self::invalid(); }
            $args[$key] = (int) $args[$key];
        }
        foreach ( array( 'sort'=>array('date','user','lesson','score','passed','completed'), 'direction'=>array('asc','desc'), 'scoreCmp'=>array('any','ge','le'), 'passed'=>array('any','yes','no'), 'completed'=>array('any','yes','no') ) as $key => $values ) {
            if ( ! in_array( $args[$key], $values, true ) ) { return self::invalid(); }
        }
        foreach ( array( 'user', 'lesson' ) as $key ) {
            if ( strlen( $args[$key] ) > 200 ) { return self::invalid(); }
        }
        foreach ( array( 'dateFrom', 'dateTo' ) as $key ) {
            $value = $args[$key];
            if ( '' !== $value && ( ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts ) || ! checkdate( (int)$parts[2], (int)$parts[3], (int)$parts[1] ) ) ) { return self::invalid(); }
        }
        if ( $args['dateFrom'] && $args['dateTo'] && $args['dateFrom'] > $args['dateTo'] ) { return self::invalid(); }
        if ( '' !== $args['scoreVal'] && ( ! is_numeric( $args['scoreVal'] ) || ! is_finite( (float)$args['scoreVal'] ) || (float)$args['scoreVal'] < 0 || (float)$args['scoreVal'] > 100 ) ) { return self::invalid(); }
        if ( 'any' !== $args['scoreCmp'] && '' === $args['scoreVal'] ) { return self::invalid(); }
        // A runtime/user-dependent threshold cannot be represented by a global SQL sort/filter.
        if ( ( isset($input['critCmp']) && 'any' !== $input['critCmp'] ) || ( isset($input['critVal']) && '' !== $input['critVal'] ) ) { return self::invalid(); }
        return $args;
    }

    private static function invalid(): WP_Error {
        return new WP_Error( 'fkhs_report_parameters', __( 'Invalid report filters.', 'h5p-sensei-bridge' ), array( 'status'=>400 ) );
    }

    /** Build a single scope shared by COUNT and page reads; no unbounded course-ID list. */
    private static function query_parts( array $args ): array {
        global $wpdb;
        $table = $wpdb->prefix . FKHS_TABLE;
        $from = " FROM {$table} a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id LEFT JOIN {$wpdb->posts} p ON p.ID=a.lesson_id";
        $lesson = "COALESCE(NULLIF(TRIM(a.lesson_title), ''), p.post_title, '—')";
        $user = "COALESCE(u.user_login, CONCAT('#', a.user_id))";
        $score = '(CASE WHEN a.max_score > 0 THEN 100.0*a.raw_score/a.max_score ELSE NULL END)';
        $where = array( '1=1' );
        if ( ! current_user_can( 'manage_options' ) ) {
            // Sensei assigns the teacher as course author. Require a live course relationship;
            // orphaned/deleted lessons and third-party/co-teacher grants fail closed.
            $where[] = $wpdb->prepare( "p.post_type='lesson' AND p.post_status NOT IN ('trash','auto-draft') AND EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} c ON c.ID=pm.meta_value
                WHERE pm.post_id=a.lesson_id AND pm.meta_key='_lesson_course'
                  AND c.post_type='course' AND c.post_status NOT IN ('trash','auto-draft') AND c.post_author=%d
            )", get_current_user_id() );
        }
        foreach ( array( 'user'=>$user, 'lesson'=>$lesson ) as $key => $expr ) {
            if ( '' !== $args[$key] ) { $where[] = $wpdb->prepare( "$expr LIKE %s", '%' . $wpdb->esc_like( $args[$key] ) . '%' ); }
        }
        if ( $args['content_id'] ) { $where[] = $wpdb->prepare( 'a.content_id=%d', $args['content_id'] ); }
        if ( $args['dateFrom'] ) { $where[] = $wpdb->prepare( 'a.created_at >= %s', $args['dateFrom'] . ' 00:00:00' ); }
        if ( $args['dateTo'] ) { $where[] = $wpdb->prepare( 'a.created_at <= %s', $args['dateTo'] . ' 23:59:59' ); }
        foreach ( array( 'passed','completed' ) as $key ) {
            if ( 'any' !== $args[$key] ) { $where[] = $wpdb->prepare( "a.{$key}=%d", 'yes' === $args[$key] ? 1 : 0 ); }
        }
        if ( 'any' !== $args['scoreCmp'] ) {
            $operator = 'ge' === $args['scoreCmp'] ? '>=' : '<=';
            $where[] = $wpdb->prepare( "$score $operator %f", (float) $args['scoreVal'] );
        }
        $sorts = array( 'date'=>'a.created_at', 'user'=>$user, 'lesson'=>$lesson, 'score'=>$score, 'passed'=>'a.passed', 'completed'=>'a.completed' );
        $dir = 'asc' === $args['direction'] ? 'ASC' : 'DESC';
        return array( $from . ' WHERE ' . implode( ' AND ', $where ), ' ORDER BY ' . $sorts[$args['sort']] . " $dir, a.id $dir" );
    }

    /** Recheck authorization even when invoked outside REST (CLI/tests/internal callers). */
    public static function get_page( array $input ) {
        if ( ! self::can_view() ) { return new WP_Error( 'fkhs_report_forbidden', __( 'You cannot view this report.', 'h5p-sensei-bridge' ), array('status'=>403) ); }
        $args = self::normalize( $input );
        if ( is_wp_error( $args ) ) { return $args; }
        global $wpdb;
        $suppressed = $wpdb->suppress_errors( true );
        try {
            list( $from, $order ) = self::query_parts( $args );
            $total = (int) $wpdb->get_var( 'SELECT COUNT(*)' . $from );
            self::check_read();
            $page = min( $args['page'], max( 1, (int)ceil( $total / $args['per_page'] ) ) );
            $rows = $wpdb->get_results( 'SELECT a.id,a.user_id,a.lesson_id,a.lesson_title,a.content_id,a.raw_score,a.max_score,a.passed,a.completed,a.created_at,u.user_login,p.post_title AS live_title' . $from . $order . $wpdb->prepare( ' LIMIT %d OFFSET %d', $args['per_page'], ($page-1)*$args['per_page'] ), ARRAY_A );
            self::check_read();
            $metadata = array();
            $ids = array_values( array_unique( array_filter( array_map( 'intval', array_column( $rows, 'content_id' ) ) ) ) );
            if ( $ids && class_exists( 'H5P_Plugin' ) ) {
                $placeholders = implode( ',', array_fill( 0, count($ids), '%d' ) );
                $contents = $wpdb->get_results( $wpdb->prepare( "SELECT c.id,c.title,c.user_id,l.name,l.major_version,l.minor_version FROM {$wpdb->prefix}h5p_contents c LEFT JOIN {$wpdb->prefix}h5p_libraries l ON l.id=c.library_id WHERE c.id IN ($placeholders)", $ids ), ARRAY_A );
                self::check_read();
                foreach ( $contents as $content ) { $metadata[(int)$content['id']] = $content; }
            }
            // Batch-prime lesson metadata and edit-link objects for this page only.
            $lessons = array_values( array_unique( array_map( 'intval', array_column( $rows, 'lesson_id' ) ) ) );
            if ( $lessons ) { _prime_post_caches( $lessons, false, true ); self::check_read(); }
            $html = FKHS_Reporter::render_rows( $rows, $metadata );
            self::check_read();
            return array( 'html'=>$html, 'total'=>$total, 'page'=>$page, 'per_page'=>$args['per_page'] );
        } catch ( Throwable $error ) {
            return new WP_Error( 'fkhs_report_unavailable', __( 'Could not load attempts. Retry, or sign in again if your session expired.', 'h5p-sensei-bridge' ), array('status'=>503) );
        } finally {
            $wpdb->suppress_errors( $suppressed );
        }
    }

    private static function check_read(): void {
        global $wpdb;
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Report storage unavailable.' ); }
    }
}
