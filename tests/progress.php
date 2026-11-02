<?php
/** Shared-policy regressions; no WordPress runtime. */
require __DIR__ . '/bootstrap.php';
function attempt( $id, $lesson, $cid, $raw, $max, $passed = 0, $completed = 0, $user = 7 ) {
    global $wpdb;
    $wpdb->rows[] = array('id'=>$id, 'lesson_id'=>$lesson, 'content_id'=>$cid, 'user_id'=>$user, 'raw_score'=>$raw, 'max_score'=>$max, 'passed'=>$passed, 'completed'=>$completed, 'created_at'=>'2026-11-01 12:00:00');
}
function gate( $lesson, $expected, $required ) {
    $response = FKHS_REST::status(new WP_REST_Request($lesson));
    check($response->data['passed'] === $expected, 'REST gate: '.$lesson);
    check($response->data['required'] === $required, 'REST required: '.$lesson);
    check(FKHS_Sensei::user_has_passed_h5p(7, $lesson) === $expected, 'Sensei agrees: '.$lesson);
}
// Positive threshold: required before first attempt, best score survives later failure.
$contents[10] = '[h5p id="1"]'; $meta[10]['_fkhs_threshold_map'] = array(1=>80);
gate(10, false, true);
attempt(1,10,1,9,10,1); attempt(2,10,1,2,10); gate(10,true,true);
// Optional zero threshold, then an imported incomplete attempt, then sticky completion.
$contents[11] = '[h5p id="2"]'; $meta[11]['_fkhs_threshold_map'] = array(2=>0);
gate(11,true,false); attempt(3,11,2,null,null); gate(11,false,true);
attempt(4,11,2,null,null,1,1); attempt(5,11,2,null,null); gate(11,true,true);
// Multiple activities, user and lesson isolation, no-activity lesson.
$contents[12] = '[h5p id="1"] [h5p id="2"]'; $meta[12]['_fkhs_threshold_map'] = array(1=>70,2=>100);
attempt(6,12,1,7,10,1); attempt(7,12,2,10,10,1,0,8); gate(12,false,true);
attempt(8,12,2,10,10,1); gate(12,true,true); gate(13,true,false);
// Filters execute exactly once, including fallback and explicit zero.
$calls = 0; $filters['fkhs_threshold_for_lesson'] = function($v) use (&$calls) { ++$calls; return $v+5; };
$meta[14]['_fkhs_threshold_map'] = array(1=>75);
check(FKHS_Thresholds::get_effective_threshold(14,1,7) === 80.0, 'Item filter chain');
check(FKHS_Thresholds::get_effective_threshold(14,1,7) === 80.0 && $calls === 1, 'Request cache');
$meta[15]['_fkhs_pass_threshold'] = 60;
check(FKHS_Thresholds::get_effective_threshold(15,1,7) === 65.0 && $calls === 2, 'Legacy filter once');
$filters = array();
$meta[16]['_fkhs_pass_threshold'] = 0;
check(FKHS_Thresholds::get_effective_threshold(16,1,7) === 0.0, 'Legacy zero survives');
// Saving the new map must preserve rollback metadata and attempts.
$meta[20] = array('_fkhs_pass_threshold'=>65, '_fkhs_require_h5p_pass'=>1);
$_POST = array('fkhs_lesson_meta_nonce'=>'test', 'fkhs_threshold_map'=>array(1=>'0',2=>'90'));
$before = $wpdb->rows; FKHS_Metabox::save(20);
check($meta[20]['_fkhs_pass_threshold'] === 65 && $meta[20]['_fkhs_require_h5p_pass'] === 1, 'Legacy settings retained');
check($meta[20]['_fkhs_threshold_map'] === array(1=>0.0,2=>90.0), 'Map saved');
check($wpdb->rows === $before, 'Attempts unchanged');
// Old require flag is absent, yet required positive-score content invokes the guard.
$contents[21] = '[h5p id="1"]'; $meta[21]['_fkhs_threshold_map'] = array(1=>80);
FKHS_Sensei::guard_completion(8,21); // not current viewer, so no redirect/exit
check(count($sensei->lesson_progress_repository->saved) === 1 && empty(Sensei_Utils::$removed), 'Guard reopens progress without destructive reset or legacy flag');
FKHS_Sensei::guard_completion(99,21);
check(count($sensei->lesson_progress_repository->saved) === 1, 'Admin exemption retained');
echo "PASS: $checks assertions (test doubles; not WordPress integration).\n";
