<?php
/** Only mounted in the disposable Docker fixture, never shipped in the plugin. */
add_filter( 'pre_wp_mail', '__return_true' );

// Version evidence from both CLI and Apache. This MU file is test-only and the
// Compose site has no published host ports. Never register this on a normal site.
function fkhs_fixture_runtime() {
    global $wpdb;
    $h5p = get_file_data(WP_PLUGIN_DIR.'/h5p/h5p.php', array('Version'=>'Version'));
    return array(
        'wordpress'=>get_bloginfo('version'), 'php'=>PHP_VERSION,
        'sensei'=>Sensei()->version, 'h5p'=>$h5p['Version'],
        'database'=>$wpdb->db_version(), 'sapi'=>PHP_SAPI,
    );
}
add_action('rest_api_init', static function () {
    if (!defined('FKHS_INTEGRATION_TESTS') || true !== FKHS_INTEGRATION_TESTS) return;
    register_rest_route('fkhs-fixture/v1', '/runtime', array(
        'methods'=>'GET', 'permission_callback'=>'__return_true',
        'callback'=>'fkhs_fixture_runtime',
    ));
});

// Persist observed hooks across real HTTP requests, only for dedicated fixtures.
foreach ( array( 'sensei_user_lesson_end', 'sensei_user_lesson_reset', 'sensei_user_quiz_submitted' ) as $hook ) {
    add_action( $hook, static function ( $user_id, $object_id ) use ( $hook ) {
        if ( ! defined( 'FKHS_INTEGRATION_TESTS' ) || ! FKHS_INTEGRATION_TESTS || ! get_user_meta( $user_id, 'fkhs_http_fixture', true ) ) return;
        $events = get_option( 'fkhs_http_events', array() );
        $events[] = array( $hook, (int) $object_id );
        update_option( 'fkhs_http_events', $events, false );
    }, 0, 2 );
}
