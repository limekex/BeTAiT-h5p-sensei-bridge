<?php
require __DIR__ . '/bootstrap.php';
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException($message, 0, $severity, $file, $line); } );
$contents[10] = '[h5p id="1"]';
$contents[11] = '[h5p id="1"]';
$meta[11]['_fkhs_threshold_map'] = array(1=>0);
$payload = array('lesson_id'=>10, 'contentId'=>1, 'event_id'=>'11111111-1111-4111-8111-111111111111',
    'statement'=>array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed'), 'result'=>array('completion'=>true, 'score'=>array('raw'=>8,'max'=>10))));
function send_event( $data ) { return FKHS_REST::handle(new WP_REST_Request(null, $data)); }
$wpdb->fail_insert = true;
$response = send_event($payload);
check($response->status === 500 && $response->data['ok'] === false, 'Failed insert is not acknowledged');
check(count($wpdb->writes) === 0, 'Failed insert stored nothing');
$wpdb->fail_insert = false;
$response = send_event($payload);
check($response->data['stored'] === true && $response->data['attempt_id'] === 1 && $response->data['duplicate'] === false, 'Successful insert acknowledged');
$response = send_event($payload);
check($response->data['duplicate'] === true && $response->data['attempt_id'] === 1 && count($wpdb->writes) === 1, 'Same event retried once');
$reordered = $payload; $reordered['statement'] = array_reverse($payload['statement'], true);
check(send_event($reordered)->data['duplicate'] === true, 'JSON key order does not change identity');
$bad = $payload; $bad['statement']['result']['score']['raw'] = 9;
check(send_event($bad)->status === 409 && count($wpdb->writes) === 1, 'Reused ID with changed payload rejected');
$filters['fkhs_should_log_attempt'] = static function () { return false; };
check(send_event($payload)->data['stored'] === true, 'Saved event remains acknowledged after policy change');
$new = $payload; $new['event_id'] = '22222222-2222-4222-8222-222222222222';
$response = send_event($new);
check($response->data['stored'] === false && $response->data['reason'] === 'filtered', 'Filter skip distinguished from saved');
$filters = array();
check(send_event($new)->data['stored'] === true && count($wpdb->writes) === 2, 'Distinct identical event still stored');
$viewer = 8; $enrollment[8][100] = true;
check(send_event($payload)->data['stored'] === true && count($wpdb->writes) === 3, 'Event identity scoped to authenticated user');
$viewer = 7;
foreach (array(null, '', 'not-a-uuid', array(), true) as $id) {
    $bad = $payload; $bad['event_id'] = $id; check(send_event($bad)->status === 400, 'Malformed event identity');
}
$legacy = $payload; unset($legacy['event_id']);
check(send_event($legacy)->data['stored'] === true, 'Legacy client without event ID still accepted');
$bad = $legacy; $bad['statement']['object']['definition']['extensions']['http://h5p.org/x-api/h5p-local-content-id'] = 2;
check(send_event($bad)->status === 400, 'Conflicting content identity rejected');
$nested = $payload; $nested['event_id'] = '33333333-3333-4333-8333-333333333333';
$nested['statement']['object']['definition']['extensions']['http://h5p.org/x-api/h5p-subContentId'] = 'child';
$before = count($wpdb->writes); $response = send_event($nested);
check($response->data['reason'] === 'nested_activity' && count($wpdb->writes) === $before, 'Nested score/completion cannot pass the root');
$nested['statement']['object'] = array('id'=>'https://example.test/activity?subContentId=child');
check(send_event($nested)->data['reason'] === 'nested_activity', 'Nested URL marker recognized');

$infer = new ReflectionMethod(FKHS_REST::class, 'infer_completion_from_stmt');
// PHP 8.0 requires explicit access; on 8.1+ this is unnecessary (deprecated in 8.5).
if (PHP_VERSION_ID < 80100) $infer->setAccessible(true);
foreach (array(0, 0.5, 0.999, 1, 50, 100) as $value) {
    $stmt = array('result'=>array('extensions'=>array('https://h5p.org/x-api/progress'=>$value)));
    check($infer->invoke(null, $stmt) === null, 'Unknown scale is not guessed: '.$value);
    $filters['fkhs_completion_progress_scale'] = static function () { return 'percent'; };
    check($infer->invoke(null, $stmt) === ($value === 100), 'Percent scale '.$value);
    $filters['fkhs_completion_progress_scale'] = static function () { return 'ratio'; };
    check($infer->invoke(null, $stmt) === ($value > 1 ? null : $value === 1), 'Ratio scale '.$value);
    $filters = array();
}
check($infer->invoke(null, array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/answered'))) === null, 'Answered alone is not completion');
check($infer->invoke(null, array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed'))) === true, 'Completed verb accepted');
check($infer->invoke(null, array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed'),'result'=>array('completion'=>false))) === false, 'Explicit false wins');
foreach (array('result','context','object','top') as $location) {
    $stmt = array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed'));
    $bag = array('https://h5p.org/x-api/completed'=>false);
    if ($location === 'object') $stmt['object']['definition']['extensions'] = $bag;
    elseif ($location === 'top') $stmt['extensions'] = $bag;
    else $stmt[$location]['extensions'] = $bag;
    check($infer->invoke(null, $stmt) === false, 'Extension false wins: '.$location);
}
$zero = $legacy; $zero['lesson_id'] = 11;
$zero['statement'] = array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/progressed'),'result'=>array('extensions'=>array('https://h5p.org/x-api/progress'=>50)));
$response = send_event($zero);
check($response->data['stored'] === false && $response->data['reason'] === 'completion_pending', 'Partial progress not stored as complete');
echo "PASS: $checks durability/completion assertions (test doubles).\n";
