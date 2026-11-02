<?php
/** Real HTTP forms and REST against the disposable, version-pinned Sensei site. */
if ( ! defined( 'FKHS_INTEGRATION_TESTS' ) || true !== FKHS_INTEGRATION_TESTS || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    throw new RuntimeException( 'Disposable integration environment required.' );
}
global $checks, $cookie;
$block_mode = 'blocks' === ($args[0] ?? 'classic');
$checks = 0;
function hsb5_check( $ok, $label ) {
    global $checks;
    if ( ! $ok ) throw new RuntimeException( $label );
    ++$checks;
}
function hsb5_http( $url, $body = null, $headers = array(), $method = null ) {
    global $cookie;
    $response = wp_remote_request( $url, array(
        'method' => $method ?: (null === $body ? 'GET' : 'POST'),
        'body' => $body, 'redirection' => 0, 'timeout' => 30,
        'headers' => array_merge(array('Cookie' => LOGGED_IN_COOKIE.'='.rawurlencode($cookie)), $headers),
    ));
    hsb5_check(!is_wp_error($response), 'HTTP request: '.(is_wp_error($response) ? $response->get_error_message() : $url));
    return $response;
}
function hsb5_snapshot( $user, $lesson, $quiz ) {
    global $wpdb;
    // Read persisted data directly: a long-running CLI process can have stale object caches.
    $comments = $wpdb->get_results($wpdb->prepare(
        "SELECT comment_ID, comment_post_ID, comment_type, comment_approved FROM {$wpdb->comments} WHERE user_id=%d ORDER BY comment_ID", $user
    ), ARRAY_A);
    $meta = $wpdb->get_results($wpdb->prepare(
        "SELECT m.* FROM {$wpdb->commentmeta} m JOIN {$wpdb->comments} c ON c.comment_ID=m.comment_id WHERE c.user_id=%d ORDER BY m.meta_id", $user
    ), ARRAY_A);
    return array($comments, $meta);
}
function hsb5_events() {
    wp_cache_delete('fkhs_http_events', 'options');
    return get_option('fkhs_http_events', array());
}
function hsb5_questions_asked( $html ) {
    $tags = new WP_HTML_Tag_Processor($html);
    $ids = array();
    while ($tags->next_tag(array('tag_name'=>'INPUT'))) {
        if ('questions_asked[]' === $tags->get_attribute('name')) $ids[] = (int)$tags->get_attribute('value');
    }
    return $ids;
}
function hsb5_pass( $user, $lesson, $cid ) {
    global $wpdb;
    hsb5_check(1 === $wpdb->insert($wpdb->prefix.FKHS_TABLE, array(
        'user_id'=>$user, 'lesson_id'=>$lesson, 'content_id'=>$cid,
        'raw_score'=>1, 'max_score'=>1, 'completed'=>1, 'created_at'=>current_time('mysql'),
    )), 'Store passing H5P fixture');
}

wp_set_current_user(1);
$user = wp_insert_user(array('user_login'=>'sensei-http-student'.($block_mode ? '-blocks' : ''), 'user_pass'=>wp_generate_password(), 'role'=>'subscriber'));
hsb5_check(!is_wp_error($user), 'Create HTTP student');
update_user_meta($user, 'fkhs_http_fixture', 1);
$course = wp_insert_post(array('post_type'=>'course', 'post_status'=>'publish', 'post_title'=>'HTTP gate course'));
$lessons = array();
// Leave a final lesson unfinished so Sensei's course-complete redirect does not exit before the quiz-submitted hook.
foreach (array('quiz', 'plain', 'optional', 'no-h5p', 'remaining') as $kind) {
    $lesson = wp_insert_post(array('post_type'=>'lesson', 'post_status'=>'publish', 'post_title'=>'HTTP '.$kind));
    update_post_meta($lesson, '_lesson_course', $course);
    $lessons[$kind] = $lesson;
}
$lesson = $lessons['quiz'];
$quiz = wp_insert_post(array('post_type'=>'quiz', 'post_status'=>'publish', 'post_title'=>'HTTP quiz', 'post_parent'=>$lesson));
update_post_meta($lesson, '_lesson_quiz', $quiz);
update_post_meta($quiz, '_quiz_lesson', $lesson);
update_post_meta($quiz, '_quiz_passmark', 100);
update_post_meta($quiz, '_quiz_grade_type', 'auto');
update_post_meta($quiz, '_pass_required', 'on');
update_post_meta($quiz, '_quiz_reset', 'on');
$question = Sensei()->lesson->lesson_save_question(array('question'=>'HTTP fixture?', 'question_type'=>'boolean', 'question_right_answer_boolean'=>'true', 'question_grade'=>1, 'quiz_id'=>$quiz, 'question_media'=>'', 'question_count'=>0));
Sensei()->quiz->set_questions($quiz, array((int)$question));
global $wpdb;
hsb5_check(1 === $wpdb->insert($wpdb->prefix.'h5p_contents', array(
    'title'=>'HTTP H5P fixture', 'parameters'=>'{}', 'filtered'=>'{}', 'slug'=>'http-fixture',
    'library_id'=>0, 'user_id'=>$user, 'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql'),
)), 'Create registry fixture');
$cid = (int)$wpdb->insert_id;
foreach ($lessons as $kind=>$id) {
    if (in_array($kind, array('no-h5p', 'remaining'), true)) continue;
    wp_update_post(array('ID'=>$id, 'post_content'=>'[h5p id="'.$cid.'"]'));
    update_post_meta($id, '_fkhs_threshold_map', array($cid=>'optional' === $kind ? 0 : 70));
}
if ($block_mode) {
    require __DIR__.'/fixtures/block-quiz.php';
    $fixture = fkhs_fixture_block_quiz($lesson,$cid);
    $quiz = $fixture['quiz']; $question = $fixture['questions'][0];
    hsb5_check(has_block('sensei-lms/quiz',$lesson), 'Serialized quiz block persisted');
    hsb5_check(serialize_blocks(parse_blocks($fixture['content'])) === get_post_field('post_content',$lesson), 'Block serialization round trip');
    hsb5_check(Sensei()->quiz->has_sensei_blocks($quiz), 'Sensei recognizes block-based lesson');
    hsb5_check(FKHS_Sensei::get_h5p_ids_for_lesson($lesson) === array($cid), 'H5P shortcode discovery survives block serialization');
    hsb5_check(has_block('sensei-lms/question-description',$question), 'Question description retains editable blocks');
}
Sensei_Course_Manual_Enrolment_Provider::instance()->enrol_learner($user, $course);
wp_set_current_user($user);
$token = WP_Session_Tokens::get_instance($user)->create(time()+HOUR_IN_SECONDS);
$cookie = wp_generate_auth_cookie($user, time()+HOUR_IN_SECONDS, 'logged_in', $token);
$_COOKIE[LOGGED_IN_COOKIE] = $cookie;
$completion = array('quiz_action'=>'lesson-complete', 'woothemes_sensei_complete_lesson_noonce'=>wp_create_nonce('woothemes_sensei_complete_lesson_noonce'));
$quiz_body = array('quiz_complete'=>'Complete Quiz', 'questions_asked'=>array($question), 'sensei_question'=>array($question=>'true'), 'woothemes_sensei_complete_quiz_nonce'=>wp_create_nonce('woothemes_sensei_complete_quiz_nonce'));
// Simulate saved work before requirements change: blocked requests must preserve it.
Sensei_Utils::user_start_lesson($user, $lesson);
// Native paginated forms retain every question ID, including unanswered pages.
$saved_answers = $block_mode ? array_fill_keys($fixture['questions'],'') : array();
$saved_answers[$question] = 'false';
Sensei_Quiz::save_user_answers($saved_answers, array(), $lesson, $user);
Sensei_Utils::user_start_lesson($user, $lessons['plain']);
update_option('fkhs_http_events', array(), false);
$before = hsb5_snapshot($user, $lesson, $quiz);
hsb5_check(!empty($before[0]) && !empty($before[1]), 'Existing native progress and answer metadata present');
foreach (array(
    'submit'=>$quiz_body,
    'save'=>array('quiz_save'=>'Save', 'questions_asked'=>array($question), 'sensei_question'=>array($question=>'true'), 'woothemes_sensei_save_quiz_nonce'=>wp_create_nonce('woothemes_sensei_save_quiz_nonce')),
    'page'=>array('quiz_target_page'=>'2', 'questions_asked'=>array($question), 'sensei_question'=>array($question=>'true'), 'sensei_quiz_page_change_nonce'=>wp_create_nonce('sensei_quiz_page_change_nonce')),
    'reset'=>array('quiz_reset'=>'Reset', 'woothemes_sensei_reset_quiz_nonce'=>wp_create_nonce('woothemes_sensei_reset_quiz_nonce')),
    'GET'=>null,
) as $kind=>$body) {
    $response = hsb5_http(get_permalink($quiz), $body);
    hsb5_check(302 === wp_remote_retrieve_response_code($response) && false !== strpos(wp_remote_retrieve_header($response,'location'), 'fkhs_quiz_blocked=1'), 'Quiz '.$kind.' blocked before listener: '.wp_json_encode(array('status'=>wp_remote_retrieve_response_code($response),'location'=>wp_remote_retrieve_header($response,'location'),'body'=>substr(wp_remote_retrieve_body($response),0,300))));
    hsb5_check($before === hsb5_snapshot($user, $lesson, $quiz), 'Quiz '.$kind.' preserves persisted answers/progress');
    hsb5_check(array() === hsb5_events(), 'Quiz '.$kind.' fires no completion/reset/submission hooks');
}
foreach (array($lesson, $lessons['plain'], $lessons['plain']) as $index=>$id) {
    $native_body = $completion;
    if (2 === $index) {
        $native_body['quiz_action'] = ' <b>lesson-complete</b> ';
        $native_body['woothemes_sensei_complete_lesson_noonce'] = ' '.$native_body['woothemes_sensei_complete_lesson_noonce'].' ';
    }
    $response = hsb5_http(get_permalink($id), $native_body);
    hsb5_check(302 === wp_remote_retrieve_response_code($response) && false !== strpos(wp_remote_retrieve_header($response,'location'), 'fkhs_not_passed=1'), 'Native completion blocked');
    hsb5_check($before === hsb5_snapshot($user, $lesson, $quiz), 'Native completion preserves existing work');
    hsb5_check(array() === hsb5_events(), 'Native completion emits no completion/reset hook');
}
// Invalid nonce must not reach Sensei completion.
$bad = $completion; $bad['woothemes_sensei_complete_lesson_noonce']='invalid';
hsb5_http(get_permalink($lessons['plain']), $bad);
hsb5_check($before === hsb5_snapshot($user,$lesson,$quiz), 'Invalid nonce causes no changes');

// These are editor/admin APIs, not student submission APIs. Exercise real cookie+REST nonce permission checks.
$routes = rest_get_server()->get_routes();
$quiz_route = null; $reset_route = null;
foreach (array_keys($routes) as $route) {
    if (false !== strpos($route, '/lesson-quiz/')) $quiz_route = preg_replace('/\(\?P<lesson_id>[^)]+\)/', (string)$lesson, $route);
    if (false !== strpos($route, '/course-progress/batch')) $reset_route = $route;
}
hsb5_check(null !== $quiz_route && null !== $reset_route, 'Supported Sensei REST routes registered');
$headers = array('X-WP-Nonce'=>wp_create_nonce('wp_rest'), 'Content-Type'=>'application/json');
$response = hsb5_http(add_query_arg('lesson_id', $lesson, rest_url('fkhs/v1/h5p-status')), null, $headers);
hsb5_check(200 === wp_remote_retrieve_response_code($response) && false === (json_decode(wp_remote_retrieve_body($response), true)['passed'] ?? null), 'Cookie and REST nonce authenticate the enrolled student');
foreach (array(
    array('GET',$quiz_route,null),
    array('POST',$quiz_route,wp_json_encode(array('options'=>(object)array(),'questions'=>array()))),
    array('DELETE',$reset_route,wp_json_encode(array('student_ids'=>array($user),'course_ids'=>array($course)))),
    array('POST','/wp/v2/lessons/'.$lesson,wp_json_encode(array('status'=>'draft'))),
) as [$method,$route,$body]) {
    $response = hsb5_http(rest_url(ltrim($route,'/')), $body, $headers, $method);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    hsb5_check(403 === wp_remote_retrieve_response_code($response), $method.' '.$route.' denies student: '.wp_json_encode($data));
    hsb5_check($before === hsb5_snapshot($user,$lesson,$quiz), 'REST rejection preserves progress');
}
// Positive controls prove correct authentication, nonce and payload, not merely rejection of malformed requests.
foreach (array('optional','no-h5p','plain') as $kind) {
    $id = $lessons[$kind];
    if ('plain' === $kind) hsb5_pass($user,$id,$cid);
    $response = hsb5_http(get_permalink($id), $completion);
    hsb5_check(wp_remote_retrieve_response_code($response) < 400 && false === strpos(wp_remote_retrieve_header($response,'location'), 'fkhs_not_passed'), $kind.' native completion allowed');
    $events = hsb5_events();
    hsb5_check(in_array(array('sensei_user_lesson_end',$id),$events,true), $kind.' Sensei completion hook observed');
}
hsb5_pass($user,$lesson,$cid);
if ($block_mode) {
    $response = hsb5_http(get_permalink($quiz));
    $html = wp_remote_retrieve_body($response);
    hsb5_check(200 === wp_remote_retrieve_response_code($response), 'Block quiz renders after H5P pass');
    hsb5_check(false !== strpos($html,'quiz-blocks') && false !== strpos($html,'sensei-quiz-form'), 'Block quiz renderer/form active');
    hsb5_check(false !== strpos($html,'Dette er første testspørsmål.') && false === strpos($html,'Dette er andre testspørsmål.'), 'First page contains only first question');
    $asked = hsb5_questions_asked($html);
    // Some templates repeat hidden fields for their second navigation group.
    hsb5_check($fixture['questions'] === array_values(array_unique($asked)), 'Native page form retains both question IDs: '.wp_json_encode(array('expected'=>$fixture['questions'],'actual'=>$asked)));
    $page = array('quiz_target_page'=>add_query_arg('quiz-page',2,get_permalink($quiz)), 'questions_asked'=>$asked, 'sensei_question'=>array($question=>'true'), 'sensei_quiz_page_change_nonce'=>wp_create_nonce('sensei_quiz_page_change_nonce'));
    $response = hsb5_http(get_permalink($quiz),$page);
    $next = wp_remote_retrieve_header($response,'location');
    hsb5_check(302 === wp_remote_retrieve_response_code($response) && false !== strpos($next,'quiz-page=2'), 'Native page change accepts and saves first answer');
    $response = hsb5_http($next);
    $html = wp_remote_retrieve_body($response);
    hsb5_check(200 === wp_remote_retrieve_response_code($response) && false !== strpos($html,'Dette er andre testspørsmål.'), 'Second question rendered: '.wp_json_encode(array('url'=>$next,'status'=>wp_remote_retrieve_response_code($response),'location'=>wp_remote_retrieve_header($response,'location'),'body'=>substr(wp_strip_all_tags($html),0,1200))));
    $question = $fixture['questions'][1];
    $quiz_body['questions_asked'] = hsb5_questions_asked($html);
    hsb5_check($fixture['questions'] === array_values(array_unique($quiz_body['questions_asked'])), 'Final page form retains both question IDs');
    $quiz_body['sensei_question'] = array($question=>'true');
}
$response = hsb5_http(get_permalink($quiz), $quiz_body);
hsb5_check(302 === wp_remote_retrieve_response_code($response) && false === strpos(wp_remote_retrieve_header($response,'location'),'fkhs_quiz_blocked'), 'Passing H5P allows quiz submission');
$events = hsb5_events();
hsb5_check(in_array(array('sensei_user_quiz_submitted',$quiz),$events,true), 'Real quiz submission hook observed: '.wp_json_encode(array('location'=>wp_remote_retrieve_header($response,'location'),'events'=>$events,'has_questions'=>Sensei_Lesson::lesson_quiz_has_questions($lesson),'quiz'=>Sensei()->lesson->lesson_quizzes($lesson),'expected'=>$quiz)));
hsb5_check(in_array(array('sensei_user_lesson_end',$lesson),$events,true), 'Passing quiz completes lesson');
// Fresh SQL verifies native status as well as hooks.
$status = $wpdb->get_var($wpdb->prepare("SELECT comment_approved FROM {$wpdb->comments} WHERE user_id=%d AND comment_post_ID=%d AND comment_type='sensei_lesson_status'",$user,$lesson));
hsb5_check('passed' === $status || 'complete' === $status, 'Persisted completed lesson: '.$status);
if ($block_mode) {
    wp_cache_flush();
    hsb5_check(100.0 === Sensei_Quiz::get_user_quiz_grade($lesson,$user), 'Final grade combines saved first-page and submitted second-page answers');
    foreach ($fixture['questions'] as $id) hsb5_check('true' === Sensei()->quiz->get_user_question_answer($lesson,$id,$user), 'Both block question answers persisted');
}
WP_CLI::success('Sensei HTTP/REST '.($block_mode ? 'blocks' : 'classic').' integration: '.$checks.' assertions passed.');
