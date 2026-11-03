<?php
/** Personal-data lifecycle. Retain history unless an operator explicitly opts in. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FKHS_Privacy {
    const EXPORT_BATCH = 20;
    const ERASE_BATCH = 100;
    const MAX_RAW_EXPORT_BYTES = 262144;

    public static function init(): void {
        add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
        add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'fkhs cleanup-statements', array( 'FKHS_Lifecycle_CLI', 'cleanup' ) );
        }
    }

    public static function exporters( array $exporters ): array {
        $exporters['h5p-sensei-bridge'] = array(
            'exporter_friendly_name' => __( 'H5P → Sensei attempts', 'h5p-sensei-bridge' ),
            'callback' => array( __CLASS__, 'export' ),
        );
        return $exporters;
    }

    public static function erasers( array $erasers ): array {
        $erasers['h5p-sensei-bridge'] = array(
            'eraser_friendly_name' => __( 'H5P → Sensei attempts', 'h5p-sensei-bridge' ),
            'callback' => array( __CLASS__, 'erase' ),
        );
        return $erasers;
    }

    public static function policy_text(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) { return; }
        wp_add_privacy_policy_content( 'H5P → Sensei', '<p>' . esc_html__( 'The H5P → Sensei Bridge stores user, lesson and activity identifiers, a lesson title snapshot, scores, completion/pass flags, event identifiers, a payload hash and the stored site time. By default it also retains the submitted xAPI statement, which may include names, email addresses, answers and contextual data. Administrators can configure future raw-statement storage and explicitly clear older raw statements without removing progress. Attempts are retained by default, including after deactivation, uninstall or account deletion. WordPress personal-data tools can export attempts for an existing account; erasure requires explicit site policy approval and may change H5P completion requirements. Set your retention period and coordinate erasure with Sensei, backups and any external stores.', 'h5p-sensei-bridge' ) . '</p>' );
    }

    /** Hashing/deduplication still uses the original payload, independent of this choice. */
    public static function statement_to_store( string $json, int $user, int $lesson, int $content ): ?string {
        return false === apply_filters( 'fkhs_store_raw_statement', true, $user, $lesson, $content ) ? null : $json;
    }

    private static function subject( $email, $page, string $capability ) {
        if ( ! current_user_can( $capability ) || ( 'erase_others_personal_data' === $capability && ! current_user_can( 'delete_users' ) ) ) {
            return new WP_Error( 'fkhs_privacy_forbidden', __( 'You cannot manage these personal data.', 'h5p-sensei-bridge' ) );
        }
        if ( ! is_string( $email ) || ! is_email( $email ) || ! is_scalar( $page ) || ! preg_match( '/^[1-9][0-9]*$/D', (string)$page ) || (float)$page > 1000000 ) {
            return new WP_Error( 'fkhs_privacy_input', __( 'Invalid personal-data request.', 'h5p-sensei-bridge' ) );
        }
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        $queries = $wpdb->num_queries;
        try {
            $user = get_user_by( 'email', $email );
            if ( $wpdb->num_queries !== $queries && $wpdb->last_error ) { return self::storage_error(); }
            return $user ? (int)$user->ID : 0;
        } catch ( Throwable $error ) {
            return self::storage_error();
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    private static function storage_error(): WP_Error {
        return new WP_Error( 'fkhs_privacy_storage', __( 'Personal-data storage is unavailable. Retry the request; completion has not been confirmed.', 'h5p-sensei-bridge' ) );
    }

    /** WordPress owns request verification, nonces and the generated export files. */
    public static function export( $email, $page = 1 ) {
        $user = self::subject( $email, $page, 'export_others_personal_data' );
        if ( is_wp_error( $user ) ) { return $user; }
        if ( ! $user ) { return array( 'data'=>array(), 'done'=>true ); }
        global $wpdb;
        $table = $wpdb->prefix . FKHS_TABLE;
        $previous = $wpdb->suppress_errors( true );
        try {
            // Guard legacy oversized payloads in SQL, before allocating them in PHP.
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id,user_id,lesson_id,lesson_title,content_id,raw_score,max_score,passed,completed,event_id,payload_hash,created_at,
                 OCTET_LENGTH(statement) AS statement_bytes,
                 CASE WHEN OCTET_LENGTH(statement)<=%d THEN statement ELSE NULL END AS statement
                 FROM {$table} WHERE user_id=%d ORDER BY id ASC LIMIT %d OFFSET %d",
                self::MAX_RAW_EXPORT_BYTES, $user, self::EXPORT_BATCH, ((int)$page-1)*self::EXPORT_BATCH
            ), ARRAY_A );
            if ( $wpdb->last_error ) { return self::storage_error(); }
            $data = array();
            $labels = array(
                'id'=>__( 'Attempt ID', 'h5p-sensei-bridge' ),
                'user_id'=>__( 'User ID', 'h5p-sensei-bridge' ),
                'lesson_id'=>__( 'Lesson ID', 'h5p-sensei-bridge' ),
                'lesson_title'=>__( 'Stored lesson title', 'h5p-sensei-bridge' ),
                'content_id'=>__( 'H5P content ID', 'h5p-sensei-bridge' ),
                'raw_score'=>__( 'Raw score', 'h5p-sensei-bridge' ),
                'max_score'=>__( 'Maximum score', 'h5p-sensei-bridge' ),
                'passed'=>__( 'Stored passed flag', 'h5p-sensei-bridge' ),
                'completed'=>__( 'Stored completed flag', 'h5p-sensei-bridge' ),
                'event_id'=>__( 'Event ID', 'h5p-sensei-bridge' ),
                'payload_hash'=>__( 'Payload hash', 'h5p-sensei-bridge' ),
                'created_at'=>__( 'Stored site time', 'h5p-sensei-bridge' ),
                'statement'=>__( 'Raw xAPI statement', 'h5p-sensei-bridge' ),
            );
            foreach ( $rows as $row ) {
                if ( (int)$row['statement_bytes'] > self::MAX_RAW_EXPORT_BYTES ) {
                    return new WP_Error( 'fkhs_privacy_oversized', __( 'An xAPI statement exceeds the automatic export limit. An administrator must arrange a complete manual export; no truncated export has been reported as complete.', 'h5p-sensei-bridge' ) );
                }
                $fields = array();
                foreach ( $labels as $key=>$label ) {
                    // Export values are HTML-capable in WordPress: escape raw answers/markup.
                    $fields[] = array( 'name'=>$label, 'value'=>null === $row[$key] ? __( 'Not stored', 'h5p-sensei-bridge' ) : esc_html( (string)$row[$key] ) );
                }
                $data[] = array( 'group_id'=>'h5p-sensei-bridge', 'group_label'=>__( 'H5P → Sensei attempts', 'h5p-sensei-bridge' ), 'item_id'=>'fkhs-attempt-'.$row['id'], 'data'=>$fields );
            }
            return array( 'data'=>$data, 'done'=>count($rows)<self::EXPORT_BATCH );
        } catch ( Throwable $error ) {
            return self::storage_error();
        } finally {
            $wpdb->suppress_errors( $previous );
        }
    }

    /** Always delete the first remaining batch: using OFFSET would skip rows after deletion. */
    public static function erase( $email, $page = 1 ) {
        $user = self::subject( $email, $page, 'erase_others_personal_data' );
        if ( is_wp_error( $user ) ) { return $user; }
        $response = array( 'items_removed'=>false, 'items_retained'=>false, 'messages'=>array(), 'done'=>true );
        if ( ! $user ) { return $response; }
        global $wpdb;
        $table = $wpdb->prefix . FKHS_TABLE;
        $previous = $wpdb->suppress_errors( true );
        try {
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id=%d ORDER BY id ASC LIMIT %d", $user, self::ERASE_BATCH ) );
            if ( $wpdb->last_error ) { return self::storage_error(); }
            if ( ! $ids ) { return $response; }
            if ( true !== apply_filters( 'fkhs_allow_personal_data_erasure', false, $user ) ) {
                $response['items_retained'] = true;
                $response['messages'][] = __( 'H5P attempts were retained because the site has not enabled their erasure. Removing them can change H5P completion requirements. Coordinate this separately with Sensei progress and backups.', 'h5p-sensei-bridge' );
                return $response;
            }
            $placeholders = implode( ',', array_fill(0,count($ids),'%d') );
            $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id=%d AND id IN ($placeholders)", array_merge(array($user),array_map('intval',$ids)) ) );
            if ( false === $deleted || $wpdb->last_error ) { return self::storage_error(); }
            $response['items_removed'] = $deleted > 0;
            $response['done'] = count($ids)<self::ERASE_BATCH;
            return $response;
        } catch ( Throwable $error ) {
            return self::storage_error();
        } finally {
            $wpdb->suppress_errors( $previous );
        }
    }

    /** Bounded raw-payload cleanup. No attempt, score, flag, hash or event ID is deleted. */
    public static function cleanup_statements( array $args ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'fkhs_cleanup_forbidden', __( 'Administrator access is required for raw-data cleanup.', 'h5p-sensei-bridge' ) ); }
        $before = $args['before'] ?? '';
        $after = $args['after_id'] ?? 0;
        $through = $args['through_id'] ?? null;
        $batch = $args['batch_size'] ?? 100;
        $apply = $args['apply'] ?? false;
        if ( ! is_string($before) || ! preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$before,$date) || ! checkdate((int)$date[2],(int)$date[3],(int)$date[1]) || ! is_bool($apply) ) { return self::cleanup_input_error(); }
        foreach ( array('after'=>$after,'through'=>$through,'batch'=>$batch) as $name=>$value ) {
            if ( 'through' === $name && null === $value && ! $apply ) { continue; }
            if ( ! is_scalar($value) || ! preg_match('/^\d+$/D',(string)$value) || (float)$value>PHP_INT_MAX ) { return self::cleanup_input_error(); }
        }
        if ( (int)$batch<1 || (int)$batch>200 || (null !== $through && (int)$through<(int)$after) ) { return self::cleanup_input_error(); }
        global $wpdb;
        $table = $wpdb->prefix . FKHS_TABLE;
        $previous = $wpdb->suppress_errors(true);
        try {
            if ( null === $through ) {
                $through = (int)$wpdb->get_var("SELECT MAX(id) FROM {$table}");
                if ( $wpdb->last_error ) { return self::storage_error(); }
            }
            if ( (int)$through < (int)$after ) { return self::cleanup_input_error(); }
            $where = $wpdb->prepare('statement IS NOT NULL AND created_at < %s AND id > %d AND id <= %d', $before.' 00:00:00', $after, $through);
            $ids = $wpdb->get_col("SELECT id FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT ".((int)$batch+1));
            if ( $wpdb->last_error ) { return self::storage_error(); }
            $done = count($ids)<=(int)$batch;
            $ids = array_slice(array_map('intval',$ids),0,(int)$batch);
            $changed = 0;
            if ( $apply && $ids ) {
                $placeholders = implode(',',array_fill(0,count($ids),'%d'));
                $changed = $wpdb->query($wpdb->prepare("UPDATE {$table} SET statement=NULL WHERE {$where} AND id IN ($placeholders)",$ids));
                if ( false === $changed || $wpdb->last_error ) { return self::storage_error(); }
            }
            return array('dry_run'=>!$apply,'before'=>$before,'through_id'=>(int)$through,'after_id'=>(int)$after,'matched'=>count($ids),'changed'=>$changed,'next_after_id'=>$ids ? max($ids) : (int)$after,'done'=>$done);
        } catch ( Throwable $error ) {
            return self::storage_error();
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    private static function cleanup_input_error(): WP_Error {
        return new WP_Error( 'fkhs_cleanup_input', __( 'Use a valid cutoff date, nonnegative cursor IDs and a batch size of 1–200. Applying cleanup requires the reviewed through-ID.', 'h5p-sensei-bridge' ) );
    }
}
