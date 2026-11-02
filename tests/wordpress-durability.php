<?php
if (!defined('FKHS_INTEGRATION_TESTS') || FKHS_INTEGRATION_TESTS !== true || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Disposable integration environment required.');
}
global $wpdb, $durability_checks;
$durability_checks = 0;
function durability_check($ok, $message) {
    global $durability_checks;
    if (!$ok) throw new RuntimeException($message);
    ++$durability_checks;
}
function durability_post($payload) {
    $req = new WP_REST_Request('POST', '/fkhs/v1/h5p-xapi');
    $req->set_header('Content-Type', 'application/json'); $req->set_body(wp_json_encode($payload));
    return rest_get_server()->dispatch($req);
}
wp_set_current_user(1);
$table = $wpdb->prefix.FKHS_TABLE;
// Recreate the previous schema around the rows left by the access fixture.
$wpdb->query("ALTER TABLE {$table} DROP INDEX event_identity, DROP COLUMN event_id, DROP COLUMN payload_hash");
$legacy = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id", ARRAY_A);
durability_check(count($legacy) > 0, 'Upgrade fixture contains existing attempts');
update_option('fkhs_db_ver', '0.2.0');
$fail_ddl = static function ($query) use ($table) {
    return stripos($query, 'ALTER TABLE '.$table) === 0 ? 'SELECT * FROM fkhs_deliberately_missing_table' : $query;
};
add_filter('query', $fail_ddl);
$suppressed = $wpdb->suppress_errors(true);
durability_check(FKHS_Plugin::maybe_upgrade() === false, 'Failed DDL is reported');
$wpdb->suppress_errors($suppressed);
remove_filter('query', $fail_ddl);
durability_check(get_option('fkhs_db_ver') === '0.2.0', 'Failed DDL does not advance schema marker');
durability_check(FKHS_Plugin::maybe_upgrade(), 'Populated schema upgraded');
durability_check(get_option('fkhs_db_ver') === '0.3.0', 'Successful upgrade marks schema');
$after = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id", ARRAY_A);
foreach ($after as &$row) {
    durability_check($row['event_id'] === null && $row['payload_hash'] === null, 'Legacy rows retain nullable identity');
    unset($row['event_id'], $row['payload_hash']);
}
unset($row);
durability_check($after === $legacy, 'Upgrade preserves every legacy value');
durability_check(FKHS_Plugin::maybe_upgrade(), 'Repeated upgrade is safe');

$lesson = wp_insert_post(array('post_type'=>'lesson','post_status'=>'publish','post_title'=>'Durability fixture'));
$wpdb->insert($wpdb->prefix.'h5p_contents', array('title'=>'Durability H5P','parameters'=>'{}','filtered'=>'{}','slug'=>'durability-h5p','library_id'=>0,'user_id'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
$cid = (int) $wpdb->insert_id;
wp_update_post(array('ID'=>$lesson,'post_content'=>'[h5p id="'.$cid.'"]'));
$payload = array('lesson_id'=>$lesson,'contentId'=>$cid,'event_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'statement'=>array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed'),'result'=>array('score'=>array('raw'=>8,'max'=>10),'completion'=>true)));
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
$first = durability_post($payload);
durability_check($first->get_status() === 200 && $first->get_data()['stored'] === true, 'Insert acknowledged');
$id = $first->get_data()['attempt_id'];
$original_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
for ($i=0; $i<3; $i++) {
    $retry = durability_post($payload);
    durability_check($retry->get_data()['duplicate'] === true && $retry->get_data()['attempt_id'] === $id, 'Retry returns original receipt');
}
durability_check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === $before+1, 'Retries insert only one row');
durability_check($wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A) === $original_row, 'Retries do not rewrite score/time/history');
$conflict = $payload; $conflict['statement']['result']['score']['raw'] = 10;
durability_check(durability_post($conflict)->get_status() === 409, 'Conflicting reuse rejected');
$fail_insert = static function ($query) use ($table) {
    return stripos($query,'INSERT INTO') === 0 && strpos($query,$table) !== false ? 'INSERT INTO fkhs_deliberately_missing_table (id) VALUES (1)' : $query;
};
$payload['event_id'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
add_filter('query',$fail_insert);
$failed = durability_post($payload);
remove_filter('query',$fail_insert);
durability_check($failed->get_status() === 500 && $failed->get_data()['reason'] === 'storage_failed', 'Actual failed SQL insert is not acknowledged');
durability_check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === $before+1, 'Failed insert stores nothing');
durability_check(durability_post($payload)->get_data()['stored'] === true, 'Retry after DB recovery succeeds');
$fail_read = static function ($query) use ($table) {
    return stripos($query,'SELECT') === 0 && strpos($query,$table) !== false ? 'SELECT * FROM fkhs_deliberately_missing_table' : $query;
};
add_filter('query',$fail_read);
durability_check(durability_post($payload)->get_status() === 503, 'Failed deduplication read does not allow another insert');
$request = new WP_REST_Request('GET','/fkhs/v1/h5p-status'); $request->set_query_params(array('lesson_id'=>$lesson));
$failed_status = rest_get_server()->dispatch($request);
durability_check($failed_status->get_status() === 503 && !isset($failed_status->get_data()['passed']), 'Failed progress read does not fabricate a gate result');
durability_check(FKHS_Sensei::user_has_passed_h5p(1,$lesson) === false, 'Server policy fails closed on SQL read failure');
remove_filter('query',$fail_read);
// Old plugin inserts can omit the new nullable columns after file rollback.
$legacy_insert = $original_row; unset($legacy_insert['id'],$legacy_insert['event_id'],$legacy_insert['payload_hash']);
durability_check($wpdb->insert($table,$legacy_insert) === 1, 'Legacy insert format remains compatible');
$payload['event_id'] = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
update_option('fkhs_concurrent_fixture', $payload, false);
WP_CLI::success("$durability_checks migration/storage assertions passed.");
