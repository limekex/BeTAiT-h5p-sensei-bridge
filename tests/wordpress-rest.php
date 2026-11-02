<?php
/** Run only in the disposable environment created by tests/integration/run.sh. */
if ( ! defined( 'FKHS_INTEGRATION_TESTS' ) || true !== FKHS_INTEGRATION_TESTS || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    throw new RuntimeException( 'Disposable integration environment required.' );
}
global $wpdb, $checks;
$checks = 0;
function fkhs_check( $ok, $message ) {
    global $checks;
    if ( ! $ok ) { throw new RuntimeException( $message ); }
    ++$checks;
}
function fkhs_request( $method, $lesson, $payload = null ) {
    $request = new WP_REST_Request($method, '/fkhs/v1/' . ($method === 'GET' ? 'h5p-status' : 'h5p-xapi'));
    if ($method === 'GET') $request->set_query_params(array('lesson_id'=>$lesson));
    else {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($payload));
    }
    return rest_get_server()->dispatch($request);
}
function fkhs_attempt_count() {
    global $wpdb;
    return (int) $wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.FKHS_TABLE);
}
function fkhs_deny_both( $lesson, $payload, $status, $label ) {
    $before = fkhs_attempt_count();
    foreach (array('GET', 'POST') as $method) {
        $response = fkhs_request($method, $lesson, $payload);
        fkhs_check($response->get_status() === $status, $label.' '.$method.': '.wp_json_encode($response->get_data()));
        fkhs_check(!isset($response->get_data()['items']), $label.' has no leaked items');
    }
    fkhs_check(fkhs_attempt_count() === $before, $label.' inserts no attempts');
}

fkhs_check(FKHS_Sensei::access_api_available(), 'Sensei API available');
fkhs_check(class_exists('H5P_Plugin') && shortcode_exists('h5p'), 'H5P active');
$student = wp_insert_user(array('user_login'=>'fixture-student', 'user_pass'=>'fixture-only', 'role'=>'subscriber'));
$outsider = wp_insert_user(array('user_login'=>'fixture-outsider', 'user_pass'=>'fixture-only', 'role'=>'subscriber'));
fkhs_check(!is_wp_error($student) && !is_wp_error($outsider), 'Users created');
$course = wp_insert_post(array('post_type'=>'course', 'post_status'=>'publish', 'post_title'=>'Fixture course'), true);
$lesson = wp_insert_post(array('post_type'=>'lesson', 'post_status'=>'publish', 'post_title'=>'Fixture lesson'), true);
$other = wp_insert_post(array('post_type'=>'lesson', 'post_status'=>'publish', 'post_title'=>'Other lesson'), true);
fkhs_check(!is_wp_error($course) && !is_wp_error($lesson) && !is_wp_error($other), 'Posts created');
update_post_meta($lesson, '_lesson_course', $course);
update_post_meta($other, '_lesson_course', $course);
// Real H5P registry row; no browser/library execution is claimed by this fixture.
$inserted = $wpdb->insert($wpdb->prefix.'h5p_contents', array(
    'title'=>'Fixture H5P', 'parameters'=>'{}', 'filtered'=>'{}', 'slug'=>'fixture-h5p',
    'library_id'=>0, 'user_id'=>$student, 'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql'),
));
fkhs_check($inserted === 1, 'H5P registry insert: '.$wpdb->last_error);
$cid = (int) $wpdb->insert_id;
wp_update_post(array('ID'=>$lesson, 'post_content'=>'[h5p id="'.$cid.'"]'));
update_post_meta($lesson, '_fkhs_threshold_map', array($cid=>70));
$payload = array('lesson_id'=>$lesson, 'contentId'=>$cid, 'statement'=>array(
    'verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed'),
    'result'=>array('score'=>array('raw'=>8,'max'=>10), 'completion'=>true, 'success'=>true),
));

wp_set_current_user(0); fkhs_deny_both($lesson, $payload, 401, 'Guest');
wp_set_current_user($outsider); fkhs_deny_both($lesson, $payload, 403, 'Unenrolled student');
update_post_meta($lesson, '_lesson_preview', 'preview');
fkhs_deny_both($lesson, $payload, 403, 'Unenrolled preview viewer');
delete_post_meta($lesson, '_lesson_preview');

$provider = Sensei_Course_Manual_Enrolment_Provider::instance();
$provider->enrol_learner($student, $course);
wp_set_current_user($student);
fkhs_check(Sensei_Course::is_user_enrolled($course, $student), 'Real Sensei enrollment');
$response = fkhs_request('GET', $lesson);
fkhs_check($response->get_status() === 200, 'Enrolled GET: '.wp_json_encode($response->get_data()));
fkhs_check($response->get_data()['passed'] === false, 'Required activity initially unmet');
$before = fkhs_attempt_count();
$response = fkhs_request('POST', $lesson, $payload);
fkhs_check($response->get_status() === 200 && fkhs_attempt_count() === $before + 1, 'Legitimate attempt stored: '.wp_json_encode($response->get_data()).' '.$wpdb->last_error);
$response = fkhs_request('GET', $lesson);
fkhs_check($response->get_data()['passed'] === true, 'Real SQL returns passing progress');
fkhs_check(FKHS_Sensei::user_has_passed_h5p($student, $lesson), 'Server guard shares passing result');
$provider->enrol_learner($outsider, $course);
wp_set_current_user($outsider);
$response = fkhs_request('GET', $lesson);
fkhs_check($response->get_status() === 200 && $response->get_data()['passed'] === false, 'Second enrolled learner cannot see first learner progress');
$provider->withdraw_learner($outsider, $course);
wp_set_current_user($student);
$nonce = wp_create_nonce('wp_rest');
// Exercise cookie/nonce authentication through serve_request, beyond internal dispatch.
$serve = static function ( $nonce_value ) use ( $student, $lesson ) {
    wp_set_current_user($student);
    $GLOBALS['wp_rest_auth_cookie'] = true;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = array('lesson_id'=>$lesson);
    $_REQUEST = $_GET;
    if ($nonce_value !== null) $_SERVER['HTTP_X_WP_NONCE'] = $nonce_value;
    else unset($_SERVER['HTTP_X_WP_NONCE']);
    ob_start();
    rest_get_server()->serve_request('/fkhs/v1/h5p-status');
    $body = ob_get_clean();
    return json_decode($body, true);
};
fkhs_check(($serve($nonce)['ok'] ?? false) === true, 'Valid cookie nonce');
fkhs_check(($serve('invalid')['code'] ?? '') === 'rest_cookie_invalid_nonce', 'Invalid cookie nonce rejected');
fkhs_check(($serve(null)['code'] ?? '') === 'rest_forbidden', 'Missing nonce clears cookie identity');
unset($_SERVER['HTTP_X_WP_NONCE']); $_GET = $_REQUEST = array();
wp_set_current_user($student);

$before = fkhs_attempt_count();
$wrong = $payload; $wrong['lesson_id'] = $other;
fkhs_check(fkhs_request('POST', $other, $wrong)->get_status() === 400, 'Activity outside lesson denied');
$wrong = $payload; $wrong['statement']['result'] = 'invalid';
fkhs_check(fkhs_request('POST', $lesson, $wrong)->get_status() === 400, 'Malformed nested result denied without fatal error');
$wrong = $payload; $wrong['statement']['result']['completion'] = 'false';
fkhs_check(fkhs_request('POST', $lesson, $wrong)->get_status() === 400, 'String boolean denied');
$wrong = $payload; unset($wrong['contentId']);
fkhs_check(fkhs_request('POST', $lesson, $wrong)->get_status() === 400, 'Content ID required by route');
fkhs_check(fkhs_attempt_count() === $before, 'Invalid payloads inserted no attempts');

foreach (array('[[h5p id="'.$cid.'"]]', '[h5p data-id="'.$cid.'"]', '[h5p id="'.$cid.'junk"]') as $content) {
    wp_update_post(array('ID'=>$other,'post_content'=>$content));
    fkhs_check(FKHS_Sensei::get_h5p_ids_for_lesson($other) === array(), 'Real parser excludes '.$content);
}
wp_update_post(array('ID'=>$other, 'post_content'=>"[h5p id = '$cid'] [h5p id=$cid]"));
fkhs_check(FKHS_Sensei::get_h5p_ids_for_lesson($other) === array($cid), 'Real parser supports spacing, quote styles and deduplication');
wp_update_post(array('ID'=>$lesson, 'post_status'=>'draft'));
fkhs_deny_both($lesson, $payload, 403, 'Draft inaccessible to student');
wp_update_post(array('ID'=>$lesson, 'post_status'=>'publish'));
$deny_view = static function () { return false; };
add_filter('sensei_can_user_view_lesson', $deny_view);
fkhs_deny_both($lesson, $payload, 403, 'Sensei access filter');
remove_filter('sensei_can_user_view_lesson', $deny_view);
$provider->withdraw_learner($student, $course);
fkhs_deny_both($lesson, $payload, 403, 'Withdrawn enrollment');
$teacher = wp_insert_user(array('user_login'=>'fixture-teacher', 'user_pass'=>'fixture-only', 'role'=>'teacher'));
fkhs_check(!is_wp_error($teacher), 'Teacher created');
$teacher_course = wp_insert_post(array('post_type'=>'course', 'post_status'=>'publish', 'post_title'=>'Teacher course', 'post_author'=>$teacher));
$teacher_lesson = wp_insert_post(array('post_type'=>'lesson', 'post_status'=>'publish', 'post_title'=>'Teacher lesson', 'post_author'=>$teacher, 'post_content'=>'[h5p id="'.$cid.'"]'));
update_post_meta($teacher_lesson, '_lesson_course', $teacher_course);
$teacher_payload = $payload; $teacher_payload['lesson_id'] = $teacher_lesson;
wp_set_current_user($teacher);
fkhs_check(user_can($teacher, 'edit_post', $teacher_lesson), 'Teacher can edit own lesson');
fkhs_check(fkhs_request('GET', $teacher_lesson)->get_status() === 200, 'Teacher preview of own lesson');
fkhs_check(fkhs_request('POST', $teacher_lesson, $teacher_payload)->get_status() === 200, 'Teacher attempt on own lesson');
fkhs_check(!user_can($teacher, 'edit_post', $other), 'Teacher cannot edit other lesson');
$wrong = $payload; $wrong['lesson_id'] = $other;
fkhs_deny_both($other, $wrong, 403, 'Teacher outside lesson scope');
wp_set_current_user(1);
fkhs_check(fkhs_request('GET', $lesson)->get_status() === 200, 'Administrator preview');
$wpdb->delete($wpdb->prefix.'h5p_contents', array('id'=>$cid));
fkhs_check(fkhs_request('POST', $lesson, $payload)->get_status() === 400, 'Deleted H5P registry row denied');
// Plugin classes remain loaded in this process; remove the registered shortcode to model unavailable runtime.
remove_shortcode('h5p');
fkhs_deny_both($lesson, $payload, 503, 'H5P runtime missing');
WP_CLI::log('WordPress '.get_bloginfo('version').' / PHP '.PHP_VERSION.' / Sensei '.Sensei()->version.' / H5P 1.17.10');
WP_CLI::success("$checks WordPress/Sensei/H5P REST and database assertions passed.");
