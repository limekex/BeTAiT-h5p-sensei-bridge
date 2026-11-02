<?php
/** Real programmatic completion and grading against the disposable Sensei site. */
if (!defined('FKHS_INTEGRATION_TESTS') || !FKHS_INTEGRATION_TESTS || !defined('WP_CLI') || !WP_CLI) throw new RuntimeException('Disposable fixture required.');
$GLOBALS['completion_fixture_finished'] = false;
register_shutdown_function(static function() {
    if (!$GLOBALS['completion_fixture_finished']) { fwrite(STDERR, "Completion fixture terminated before its final assertions.\n"); exit(1); }
});
// Exercise a non-browser caller; Sensei's own course redirect is disabled in REST context.
if (!defined('REST_REQUEST')) define('REST_REQUEST', true);
global $checks, $work_before, $mail_decisions, $reset_events;
$checks = 0; $work_before = array(); $mail_decisions = array(); $reset_events = 0;
$mode = $args[0] ?? '';
function completion_check($ok, $label) {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
}
function completion_work($user, $lesson) {
    $quiz = (int)Sensei()->lesson->lesson_quizzes($lesson);
    $submission = $quiz ? Sensei()->quiz_submission_repository->get($quiz,$user) : null;
    return !$submission ? null : serialize(array(
        $submission->get_id(), $submission->get_final_grade(),
        array_map(static fn($answer) => array($answer->get_question_id(),$answer->get_value()), Sensei()->quiz_answer_repository->get_all($submission->get_id())),
        array_map(static fn($grade) => array($grade->get_question_id(),$grade->get_points(),$grade->get_feedback()), Sensei()->quiz_grade_repository->get_all($submission->get_id())),
    ));
}
// Capture already-saved answers/grades before the bridge's priority-1 correction.
add_action('sensei_user_lesson_end', static function($user,$lesson) {
    global $work_before;
    $work_before[$user.':'.$lesson] = completion_work($user,$lesson);
}, 0, 2);
add_action('sensei_user_lesson_reset', static function() { global $reset_events; ++$reset_events; });
// Exercise the legacy notifier even when modern Sensei replaces it by default.
add_action('sensei_user_lesson_end', static function($user,$lesson) {
    Sensei()->emails->emails['teacher-completed-lesson']->trigger($user,$lesson);
}, 10, 2);
add_filter('sensei_send_emails', static function($send,$to,$subject,$message,$type) {
    global $mail_decisions, $sensei_email_data;
    if ('legacy-email' === $type && 'teacher-completed-lesson' === ($sensei_email_data['template'] ?? '')) $mail_decisions[] = $send;
    return $send;
}, 99, 5);

$expected = array('comments'=>'Comments_Based','dual-comments'=>'Comment_Reading_Aggregate','dual-tables'=>'Table_Reading_Aggregate');
completion_check(isset($expected[$mode]) && false !== strpos(get_class(Sensei()->lesson_progress_repository),$expected[$mode]), 'Expected real storage backend: '.$mode);
// The optional schema is feature-flagged separately from the storage settings.
if ('comments' !== $mode) {
    add_filter('sensei_feature_flag_tables_based_progress','__return_true');
    (new \Sensei\Internal\Installer\Schema(Sensei()->feature_flags))->create_tables();
    global $wpdb;
    foreach (array('progress','quiz_submissions','quiz_answers','quiz_grades') as $suffix) {
        $table = $wpdb->prefix.'sensei_lms_'.$suffix;
        completion_check($table === $wpdb->get_var("SHOW TABLES LIKE '$table'"),'Optional schema exists: '.$suffix);
    }
}
wp_set_current_user(1);
$user = wp_insert_user(array('user_login'=>'completion-'.$mode, 'user_pass'=>wp_generate_password(),'role'=>'subscriber'));
completion_check(!is_wp_error($user),'Create student');
$course = wp_insert_post(array('post_type'=>'course','post_status'=>'publish','post_title'=>'Completion '.$mode,'post_author'=>1));
$lesson = wp_insert_post(array('post_type'=>'lesson','post_status'=>'publish','post_title'=>'Completion quiz '.$mode,'post_author'=>1));
update_post_meta($lesson,'_lesson_course',$course);
$quiz = wp_insert_post(array('post_type'=>'quiz','post_status'=>'publish','post_title'=>'Completion quiz','post_parent'=>$lesson,'post_author'=>1));
update_post_meta($lesson,'_lesson_quiz',$quiz);
update_post_meta($quiz,'_quiz_lesson',$lesson);
update_post_meta($quiz,'_quiz_passmark',100);
update_post_meta($quiz,'_quiz_grade_type','auto');
update_post_meta($quiz,'_pass_required','on');
$question = Sensei()->lesson->lesson_save_question(array('question'=>'Preserve this answer?', 'question_type'=>'boolean','question_right_answer_boolean'=>'true','question_grade'=>1,'quiz_id'=>$quiz,'question_media'=>'','question_count'=>0));
Sensei()->quiz->set_questions($quiz,array((int)$question));
global $wpdb;
completion_check(1 === $wpdb->insert($wpdb->prefix.'h5p_contents',array('title'=>'Completion fixture','parameters'=>'{}','filtered'=>'{}','slug'=>'completion-'.$mode,'library_id'=>0,'user_id'=>$user,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'))),'H5P registry fixture');
$cid = (int)$wpdb->insert_id;
wp_update_post(array('ID'=>$lesson,'post_content'=>'[h5p id="'.$cid.'"]'));
update_post_meta($lesson,'_fkhs_threshold_map',array($cid=>70));
Sensei_Course_Manual_Enrolment_Provider::instance()->enrol_learner($user,$course);
wp_set_current_user($user);
Sensei_Utils::user_start_lesson($user,$lesson);
$started = new DateTimeImmutable('2025-01-02 03:04:05',new DateTimeZone('UTC'));
$lp = Sensei()->lesson_progress_repository->get($lesson,$user); $lp->start($started); Sensei()->lesson_progress_repository->save($lp);
$qp = Sensei()->quiz_progress_repository->get($quiz,$user) ?? Sensei()->quiz_progress_repository->create($quiz,$user); $qp->start($started); Sensei()->quiz_progress_repository->save($qp);
$lesson_progress_id = $lp->get_id(); $quiz_progress_id = $qp->get_id();
// Old guard exited for this current-user call. The job must continue normally.
completion_check(Sensei_Quiz::submit_answers_for_grading(array($question=>'true'),array(),$lesson,$user),'Programmatic grading returns normally');
completion_check(null !== completion_work($user,$lesson),'Quiz work exists after rejection');
completion_check($work_before[$user.':'.$lesson] === completion_work($user,$lesson),'Correction preserves submission identity, answer values, grades and feedback');
completion_check(100.0 === Sensei_Quiz::get_user_quiz_grade($lesson,$user),'Numeric grade remains 100');
completion_check(!Sensei_Utils::user_completed_lesson($lesson,$user),'Native completion query rejects quiz pass without H5P');
completion_check('complete' !== Sensei()->course_progress_repository->get($course,$user)->get_status(),'Course not completed by rejected programmatic grading');
foreach (array(array(Sensei()->lesson_progress_repository,$lesson,$lesson_progress_id),array(Sensei()->quiz_progress_repository,$quiz,$quiz_progress_id)) as [$repository,$id,$original_id]) {
    $progress = $repository->get($id,$user);
    completion_check($original_id === $progress->get_id(),'Progress row identity retained');
    completion_check('in-progress' === $progress->get_status(),'Progress reopened');
    completion_check($started->getTimestamp() === $progress->get_started_at()->getTimestamp(),'Original start date retained');
}
if ('comments' !== $mode) {
    $statuses = $wpdb->get_col($wpdb->prepare("SELECT status FROM {$wpdb->prefix}sensei_lms_progress WHERE user_id=%d AND post_id IN (%d,%d) AND type IN ('lesson','quiz')",$user,$lesson,$quiz));
    completion_check(2 === count($statuses) && array('in-progress') === array_values(array_unique($statuses)),'Both custom-table statuses corrected');
    $grade = $wpdb->get_var($wpdb->prepare("SELECT final_grade FROM {$wpdb->prefix}sensei_lms_quiz_submissions WHERE user_id=%d AND quiz_id=%d",$user,$quiz));
    completion_check(100.0 === (float)$grade,'Custom-table submission grade retained');
}
completion_check(0 === $reset_events,'No destructive reset event');
completion_check(!empty($mail_decisions) && !in_array(true,$mail_decisions,true),'Legacy completion email suppressed: '.wp_json_encode($mail_decisions));

// Background caller has no current viewer. Existing answers/grades still survive.
wp_set_current_user(0);
$before = completion_work($user,$lesson);
Sensei_Utils::sensei_start_lesson($lesson,$user,true);
completion_check($before === completion_work($user,$lesson),'Background completion preserves work');
completion_check(!Sensei_Utils::user_completed_lesson($lesson,$user),'Background completion is corrected');
// Missing supported API must fail visibly, never fall back to deletion.
$repository = Sensei()->quiz_progress_repository;
Sensei()->quiz_progress_repository = null;
$failed = false;
try { FKHS_Sensei::guard_completion($user,$lesson); } catch (RuntimeException $e) { $failed = true; }
Sensei()->quiz_progress_repository = $repository;
completion_check($failed && $before === completion_work($user,$lesson),'Unsupported API reports failure and preserves work');

// Inject real failed progress UPDATEs; answer/submission data must remain intact.
$lp = Sensei()->lesson_progress_repository->get($lesson,$user); $lp->complete(); Sensei()->lesson_progress_repository->save($lp);
$qp = Sensei()->quiz_progress_repository->get($quiz,$user); $qp->pass(); Sensei()->quiz_progress_repository->save($qp);
$break_update = static function($sql) use ($wpdb) {
    foreach (array($wpdb->comments, $wpdb->prefix.'sensei_lms_progress') as $table) {
        if (preg_match('/^UPDATE\\s+`?'.preg_quote($table,'/').'`?\\s/i',$sql)) return str_replace($table,$wpdb->prefix.'fkhs_missing_progress_table',$sql);
    }
    return $sql;
};
add_filter('query',$break_update);
$previous_errors = $wpdb->suppress_errors(true);
$failed = false;
try { FKHS_Sensei::guard_completion($user,$lesson); } catch (RuntimeException $e) { $failed = true; }
finally { remove_filter('query',$break_update); $wpdb->suppress_errors($previous_errors); }
completion_check($failed,'Failed SQL correction is reported');
completion_check($before === completion_work($user,$lesson),'Failed SQL correction preserves learner work');
FKHS_Sensei::guard_completion($user,$lesson);
completion_check(!Sensei_Utils::user_completed_lesson($lesson,$user),'Correction can be retried after storage recovers');

// Teacher/admin actor does not exempt an ordinary student's requirements.
wp_set_current_user(1);
Sensei_Utils::sensei_start_lesson($lesson,$user,true);
completion_check(!Sensei_Utils::user_completed_lesson($lesson,$user),'Privileged actor cannot accidentally exempt student');
// Supported manual-grading path preserves feedback while rejecting completion.
$_GET = array('quiz_id'=>$quiz,'user'=>$user);
$_POST = array('sensei_manual_grade'=>$quiz,'_wp_sensei_manual_grading_nonce'=>wp_create_nonce('sensei_manual_grading'),'all_questions_graded'=>'yes','quiz_grade_total'=>1,'question_'.$question.'_grade'=>1,'questions_feedback'=>array($question=>'Keep this feedback.'));
// The admin form always redirects after saving; intercept only that final redirect.
class CompletionFixtureGradingRedirect extends RuntimeException {}
$grading_redirect = static function($location) { throw new CompletionFixtureGradingRedirect($location); };
add_filter('wp_redirect',$grading_redirect,0);
$redirected = false;
try { Sensei()->grading->admin_process_grading_submission(); }
catch (CompletionFixtureGradingRedirect $e) { $redirected = false !== strpos($e->getMessage(),'message=graded'); }
finally { remove_filter('wp_redirect',$grading_redirect,0); $_GET = $_POST = array(); }
completion_check($redirected,'Manual grading reaches its own normal completion redirect');
completion_check($work_before[$user.':'.$lesson] === completion_work($user,$lesson),'Manual grading/feedback survives correction');
completion_check(!Sensei_Utils::user_completed_lesson($lesson,$user),'Manual grading cannot complete blocked lesson');
completion_check(false !== strpos(completion_work($user,$lesson),base64_encode('Keep this feedback.')),'Manual feedback persisted');

// No synthetic completion when H5P passes: the next native completion/grading owns it.
completion_check(1 === $wpdb->insert($wpdb->prefix.FKHS_TABLE,array('user_id'=>$user,'lesson_id'=>$lesson,'content_id'=>$cid,'raw_score'=>1,'max_score'=>1,'completed'=>1,'created_at'=>current_time('mysql'))),'Passing H5P fixture');
completion_check(!Sensei_Utils::user_completed_lesson($lesson,$user),'H5P pass alone does not restore native completion');
$mail_decisions = array();
wp_set_current_user($user);
Sensei_Quiz::submit_answers_for_grading(array($question=>'true'),array(),$lesson,$user);
completion_check(Sensei_Utils::user_completed_lesson($lesson,$user),'Native retry after H5P pass completes');
completion_check('complete' === Sensei()->course_progress_repository->get($course,$user)->get_status(),'Course completes after legitimate retry');
completion_check(in_array(true,$mail_decisions,true),'Completion mail permitted after legitimate retry');
// Privileged learners retain the pre-existing exemption, independent of current actor.
Sensei_Course_Manual_Enrolment_Provider::instance()->enrol_learner(1,$course);
wp_set_current_user(0);
Sensei_Utils::sensei_start_lesson($lesson,1,true);
completion_check(Sensei()->lesson_progress_repository->get($lesson,1)->is_complete(),'Administrator learner exemption retained');
completion_check(0 === $reset_events,'No reset hooks in any fallback path');
$GLOBALS['completion_fixture_finished'] = true;
WP_CLI::success('Programmatic completion '.$mode.': '.$checks.' assertions passed.');
