<?php
if (!defined('FKHS_INTEGRATION_TESTS') || FKHS_INTEGRATION_TESTS !== true || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Disposable integration environment required.');
}
global $wpdb;
wp_set_current_user(1);
$payload = get_option('fkhs_concurrent_fixture');
if (!$payload) throw new RuntimeException('Missing concurrent delivery fixture');
if (($args[0] ?? '') === 'verify') {
    $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.FKHS_TABLE.' WHERE user_id=1 AND lesson_id=%d AND content_id=%d AND event_id=%s', $payload['lesson_id'],$payload['contentId'],$payload['event_id']));
    if ((int)$count !== 1) throw new RuntimeException('Concurrent requests must leave exactly one event row');
    WP_CLI::success('Concurrent delivery: exactly one row.');
} else {
    // Force both workers past the initial deduplication lookup before insertion.
    $barrier = static function ($value) {
        global $wpdb;
        add_option('fkhs_race_ready_'.wp_generate_uuid4(), '1', '', false);
        $deadline = microtime(true) + 10;
        do {
            $ready = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'fkhs_race_ready_%'");
            if ($ready === 2) return $value;
            usleep(20000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Both workers must reach the insertion barrier');
    };
    add_filter('fkhs_should_log_attempt', $barrier);
    $req = new WP_REST_Request('POST','/fkhs/v1/h5p-xapi');
    $req->set_header('Content-Type','application/json'); $req->set_body(wp_json_encode($payload));
    $response = rest_get_server()->dispatch($req);
    if ($response->get_status() !== 200 || ($response->get_data()['stored'] ?? false) !== true) throw new RuntimeException(wp_json_encode($response->get_data()));
    WP_CLI::success('Concurrent worker acknowledged: '.wp_json_encode($response->get_data()));
}
