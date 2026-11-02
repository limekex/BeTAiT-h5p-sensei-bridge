<?php
/** Access/payload regressions with test doubles. Run wordpress-rest.php for real dispatch/SQL. */
require __DIR__ . '/bootstrap.php';
set_error_handler( static function ( $severity, $message, $file, $line ) {
    throw new ErrorException( $message, 0, $severity, $file, $line );
} );
$contents[10] = '[h5p id="1"]';
$valid = array('lesson_id'=>10, 'contentId'=>1, 'statement'=>array(
    'verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed'),
    'result'=>array('score'=>array('raw'=>8, 'max'=>10), 'completion'=>true, 'success'=>true),
));
function submit( $payload ) { return FKHS_REST::handle(new WP_REST_Request(null, $payload)); }
function rejected( $payload, $status, $label ) {
    global $wpdb;
    $before = count($wpdb->writes);
    $response = submit($payload);
    check($response->status === $status && $response->data['ok'] === false, $label);
    check(count($wpdb->writes) === $before, $label.' does not insert');
}
function denied_both( $status, $label ) {
    global $valid, $wpdb;
    $reads = $wpdb->reads;
    rejected($valid, $status, $label.' POST');
    $response = FKHS_REST::status(new WP_REST_Request(10));
    check($response->status === $status, $label.' GET');
    check(!isset($response->data['items']), $label.' hides content/progress');
    check($wpdb->reads === $reads, $label.' does not read progress/H5P tables');
    check(isset($response->headers['Cache-Control']), $label.' no-store');
}

$viewer = 0; denied_both(401, 'Guest');
$viewer = 8; denied_both(403, 'Unenrolled user despite visible/preview lesson');
$viewer = 7;
$view_access[7][10] = false; denied_both(403, 'Sensei denies lesson access');
$view_access = array();
foreach (array('draft', 'private', 'future', 'trash', 'auto-draft') as $status) {
    $post_statuses[10] = $status; denied_both(403, 'Lesson '.$status);
}
$post_statuses = array();
$post_statuses[100] = 'draft'; denied_both(403, 'Unpublished course'); $post_statuses = array();
$passwords[10] = true; denied_both(403, 'Password-protected lesson'); $passwords = array();
$post_types[10] = 'page'; denied_both(403, 'Wrong post type'); $post_types = array();
$meta[10]['_lesson_course'] = 0; denied_both(403, 'Orphan lesson'); $meta = array();
$h5p_available = false; denied_both(503, 'H5P unavailable'); $h5p_available = true;
$old_sensei = $sensei; $sensei = null; denied_both(503, 'Sensei unavailable'); $sensei = $old_sensei;
$enrollment[7][100] = false; denied_both(403, 'Enrollment withdrawn'); $enrollment = array();

check(submit($valid)->status === 200 && count($wpdb->writes) === 1, 'Enrolled user can submit');
check($wpdb->writes[0]['user_id'] === 7 && $wpdb->writes[0]['passed'] === 1, 'Current user and server threshold used');
$forged = $valid; $forged['user_id'] = 99; $forged['threshold'] = 1; $forged['statement']['actor'] = array('mbox'=>'mailto:someone@example.test');
$forged['statement']['result']['score']['raw'] = 2;
submit($forged);
check($wpdb->writes[1]['user_id'] === 7 && $wpdb->writes[1]['passed'] === 0, 'Client cannot choose actor or pass threshold');
$viewer = 8; $edit_access[8][10] = true;
check(submit($valid)->status === 200, 'Lesson editor can preview without enrollment');
$post_statuses[10] = 'draft'; check(submit($valid)->status === 200, 'Editor can preview draft');
$post_statuses[10] = 'trash'; rejected($valid, 403, 'Editor cannot write trashed lesson');
$post_statuses = $edit_access = array();
$viewer = 99; check(submit($valid)->status === 200, 'Admin preview');
$viewer = 7;

foreach (array(null, false, 'text', 1, array(1, 2)) as $body) rejected($body, 400, 'Invalid body '.json_encode($body));
foreach (array('lesson_id', 'contentId') as $key) {
    foreach (array(null, 0, -1, true, 1.5, array(1), '1x', '1e2', '999999999999999999999999') as $value) {
        $bad = $valid; $bad[$key] = $value;
        rejected($bad, 400, $key.' '.json_encode($value));
    }
    $bad = $valid; unset($bad[$key]); rejected($bad, 400, 'Missing '.$key);
}
$bad = $valid; $bad['contentId'] = 2; rejected($bad, 400, 'Activity belongs to another lesson');
$wpdb->h5p_ids = array(2); rejected($valid, 400, 'Deleted H5P activity'); $wpdb->h5p_ids = array(1, 2);

$paths = array(array('result'), array('result','score'), array('verb'), array('context'), array('object'),
    array('object','definition'), array('result','extensions'), array('context','extensions'),
    array('object','definition','extensions'), array('extensions'));
foreach ($paths as $path) {
    foreach (array(null, true, 'invalid', 10, array(1, 2)) as $value) {
        $bad = $valid; $target =& $bad['statement'];
        foreach ($path as $key) $target =& $target[$key];
        $target = $value; unset($target);
        rejected($bad, 400, implode('.', $path).' '.json_encode($value));
    }
}
foreach (array('success', 'completion') as $key) {
    foreach (array('false', 'true', 0, 1, null, array()) as $value) {
        $bad = $valid; $bad['statement']['result'][$key] = $value; rejected($bad, 400, 'Non-boolean '.$key);
    }
}
foreach (array('raw', 'max', 'min', 'scaled') as $key) {
    foreach (array('8', true, null, array(), INF, NAN) as $value) {
        $bad = $valid; $bad['statement']['result']['score'][$key] = $value; rejected($bad, 400, 'Non-numeric '.$key);
    }
}
foreach (array(array('raw'=>11,'max'=>10), array('raw'=>1,'max'=>-1), array('min'=>5,'max'=>2), array('raw'=>1,'min'=>2), array('scaled'=>1.1)) as $score) {
    $bad = $valid; $bad['statement']['result']['score'] = $score; rejected($bad, 400, 'Score bounds');
}
foreach (array('result', 'context', 'object', 'top') as $where) {
    foreach (array('https://h5p.org/x-api/completed'=>'false', 'https://h5p.org/x-api/progress'=>'100', 'http://id.tincanapi.com/extension/progress'=>101) as $key=>$value) {
        $bad = $valid; $bag = array($key=>$value);
        if ($where === 'object') $bad['statement']['object']['definition']['extensions'] = $bag;
        elseif ($where === 'top') $bad['statement']['extensions'] = $bag;
        else $bad['statement'][$where]['extensions'] = $bag;
        rejected($bad, 400, 'Invalid known extension '.$where);
    }
}
foreach (array(array(), array('id'=>array()), array('id'=>''), array('id'=>null)) as $verb) {
    $bad = $valid; $bad['statement']['verb'] = $verb; rejected($bad, 400, 'Invalid verb');
}
$bad = $valid; $bad['statement']['object']['id'] = array(); rejected($bad, 400, 'Invalid object ID');
$good = $valid; $good['statement']['result'] = array('completion'=>false, 'success'=>false);
check(submit($good)->status === 200, 'Boolean false accepted');
$good['statement'] = array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/initialized'));
check(submit($good)->status === 200, 'Scoreless activity event accepted');
$meta[11]['_fkhs_threshold_map'] = array(2=>0); $contents[11] = '[h5p id="2"]';
$good = $valid; $good['lesson_id'] = 11; $good['contentId'] = 2;
$good['statement'] = array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed'), 'result'=>array('completion'=>true, 'success'=>true));
check(submit($good)->status === 200, 'Existing minimal synthetic completion accepted');
check(end($wpdb->writes)['completed'] === 1, 'Completion-only event stored');
$good['statement']['result']['completion'] = false;
$before = count($wpdb->writes); submit($good);
check(count($wpdb->writes) === $before, 'Incomplete zero-threshold event still skipped');
echo "PASS: $checks access/payload assertions (test doubles).\n";
