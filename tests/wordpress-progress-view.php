<?php
/** Real WordPress/Sensei authorization, rendering and Application Password contract. */
if (!defined('FKHS_INTEGRATION_TESTS') || !FKHS_INTEGRATION_TESTS || !defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Disposable progress fixture required.'); }
$checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);++$checks;};
$request=static function($method,$route,$body=array()){$r=new WP_REST_Request($method,$route);if($method==='GET')$r->set_query_params($body);else{$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}return rest_get_server()->dispatch($r);};
wp_set_current_user(1);
$students=array();foreach(array('a','b','outsider') as $name)$students[]=wp_insert_user(array('user_login'=>'progress-'.$name,'role'=>'subscriber'));
$course=wp_insert_post(array('post_type'=>'course','post_status'=>'publish','post_title'=>'Progress fixture'));
$lesson=wp_insert_post(array('post_type'=>'lesson','post_status'=>'publish','post_title'=>'Progress fixture lesson'));
update_post_meta($lesson,'_lesson_course',$course);
foreach(array_slice($students,0,2) as $student)Sensei_Course_Manual_Enrolment_Provider::instance()->enrol_learner($student,$course);
global $wpdb;
$ids=array();foreach(array('Scored','Optional','Completion') as $title){$wpdb->insert($wpdb->prefix.'h5p_contents',array('title'=>$title,'parameters'=>'{}','filtered'=>'{}','slug'=>'progress-'.strtolower($title),'library_id'=>0,'user_id'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));$ids[]=(int)$wpdb->insert_id;}
$markup='<!-- wp:fkhs/progress -->[fkhs_progress]<!-- /wp:fkhs/progress -->';
$content=$markup.implode('',array_map(static fn($id)=>'[h5p id="'.$id.'"]',$ids));
wp_update_post(array('ID'=>$lesson,'post_content'=>$content));
update_post_meta($lesson,FKHS_Editor::META,array($ids[0]=>70,$ids[1]=>0,$ids[2]=>0));
$check(WP_Block_Type_Registry::get_instance()->is_registered('fkhs/progress'),'Progress block registered');
$check(shortcode_exists('fkhs_progress'),'Progress shortcode registered');
$check(FKHS_Sensei::get_h5p_ids_for_lesson($lesson)===$ids,'Progress placement is not discovered as an activity');
$check(parse_blocks($markup)[0]['blockName']==='fkhs/progress' && do_blocks($markup)==='[fkhs_progress]','Block serialization preserves literal shortcode');
$query_before=$GLOBALS['wp_query'];$GLOBALS['wp_query']=new WP_Query(array('post_type'=>'lesson','p'=>$lesson));
$rendered=array();foreach(array(0,$students[0],$students[1]) as $viewer){wp_set_current_user($viewer);$rendered[]=do_shortcode(do_blocks($markup));}
$check(count(array_unique($rendered))===1 && strpos($rendered[0],'data-fkhs-progress')!==false,'Anonymous and learner HTML identical; no cached results/identity');
$check(strpos($rendered[0],'noscript')!==false,'No-JavaScript explanation');
FKHS_Progress_View::enqueue();$check(wp_style_is('fkhs-progress','enqueued'),'Lesson progress stylesheet enqueued');
$GLOBALS['wp_query']=new WP_Query(array('post_type'=>'page','p'=>-1));
$check(strpos(do_shortcode('[fkhs_progress]'),'data-fkhs-progress')===false,'Outside lesson has explanation without active placement');
$GLOBALS['wp_query']=$query_before;
$schema=FKHS_REST::status_schema();
wp_set_current_user($students[0]);
$initial=$request('GET','/fkhs/v1/h5p-status',array('lesson_id'=>$lesson))->get_data();
$check(true===rest_validate_value_from_schema($initial,$schema),'Initial response matches schema including null scores');
$check($initial['required'] && !$initial['passed'] && !$initial['items'][1]['counts_for_gate'],'Mixed initial requirements');
foreach(array(array($ids[0],array('score'=>array('raw'=>8,'max'=>10),'completion'=>true)),array($ids[2],array('completion'=>true))) as $event){
    $result=$request('POST','/fkhs/v1/h5p-xapi',array('lesson_id'=>$lesson,'contentId'=>$event[0],'event_id'=>wp_generate_uuid4(),'statement'=>array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed'),'result'=>$event[1])));
    $check($result->get_status()===200 && $result->get_data()['stored'],'Native attempt saved');
}
$own=$request('GET','/fkhs/v1/h5p-status',array('lesson_id'=>$lesson))->get_data();
$check(true===rest_validate_value_from_schema($own,$schema) && $own['passed'],'Scored and scoreless completion response validates');
$check($own['items'][0]['best']['pct']==80 && $own['items'][2]['latest']['completed'] && $own['items'][2]['best']['pct']===null,'Actual score and explicit scoreless completion retained');
$check(FKHS_Sensei::user_has_passed_h5p($students[0],$lesson),'Progress agrees with canonical Sensei guard');
$route_help=$request('OPTIONS','/fkhs/v1/h5p-status')->get_data();$check(isset($route_help['schema']) && $route_help['schema']===$schema,'Schema is exposed through OPTIONS on existing route');
// Actual HTTP requests exercise WordPress's native Application Password auth.
$http=static function($student,$password,$extra=array())use($lesson){return wp_remote_get(add_query_arg(array_merge(array('rest_route'=>'/fkhs/v1/h5p-status','lesson_id'=>$lesson),$extra),home_url('/')),array('timeout'=>30,'headers'=>array('Authorization'=>'Basic '.base64_encode(get_userdata($student)->user_login.':'.$password))));};
$credentials=array();
try {
    foreach($students as $student){$created=WP_Application_Passwords::create_new_application_password($student,array('name'=>'Disposable HSB011 test'));$check(!is_wp_error($created),'Native Application Password created');$credentials[$student]=$created;}
    foreach(array($students[0]=>true,$students[1]=>false) as $student=>$passed){
        $response=$http($student,$credentials[$student][0],array('user_id'=>$students[0]));
        $check(!is_wp_error($response) && wp_remote_retrieve_response_code($response)===200,'Application Password authenticates without cookie or nonce');
        $data=json_decode(wp_remote_retrieve_body($response),true);
        $check(true===rest_validate_value_from_schema($data,$schema) && $data['passed']===$passed,'Query user_id cannot change authenticated learner scope');
        $check(strpos(wp_remote_retrieve_header($response,'cache-control'),'no-store')!==false,'HTTP progress is never cacheable');
    }
    $denied=$http($students[2],$credentials[$students[2]][0]);
    $missing=$http($students[2],$credentials[$students[2]][0],array('lesson_id'=>99999999));
    $check(wp_remote_retrieve_response_code($denied)===403 && wp_remote_retrieve_body($denied)===wp_remote_retrieve_body($missing),'Inaccessible and missing lesson indistinguishable');
    $invalid=$http($students[0],$credentials[$students[0]][0],array('lesson_id'=>0));
    $check(wp_remote_retrieve_response_code($invalid)===400,'Invalid lesson rejected over HTTP');
    $wrong=$http($students[0],'invalid-fixture-password');$check(wp_remote_retrieve_response_code($wrong)===401,'Bad application password denied');
    WP_Application_Passwords::delete_application_password($students[0],$credentials[$students[0]][1]['uuid']);
    $check(wp_remote_retrieve_response_code($http($students[0],$credentials[$students[0]][0]))===401,'Revoked application password denied');
} finally {
    foreach($credentials as $student=>$created)WP_Application_Passwords::delete_application_password($student,$created[1]['uuid']);
}
wp_set_current_user(0);
$check($request('GET','/fkhs/v1/h5p-status',array('lesson_id'=>$lesson))->get_status()===401,'Anonymous read denied');
WP_CLI::success("$checks progress rendering/API assertions passed.");
