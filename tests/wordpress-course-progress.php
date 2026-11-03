<?php
/** Real course authorization, batch policy equivalence and conditional block rendering. */
if (!defined('FKHS_INTEGRATION_TESTS') || !FKHS_INTEGRATION_TESTS || !defined('WP_CLI') || !WP_CLI) throw new RuntimeException('Disposable course fixture required.');
$checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);++$checks;};
$request=static function($course,$extra=array()){$r=new WP_REST_Request('GET','/fkhs/v1/course-progress');$r->set_query_params(array_merge(array('course_id'=>$course),$extra));return rest_get_server()->dispatch($r);};
wp_set_current_user(1);
$teacher=wp_insert_user(array('user_login'=>'course-progress-teacher','role'=>'teacher'));
$foreign=wp_insert_user(array('user_login'=>'course-progress-foreign','role'=>'teacher'));
$a=wp_insert_user(array('user_login'=>'course-progress-a','role'=>'subscriber'));
$b=wp_insert_user(array('user_login'=>'course-progress-b','role'=>'subscriber'));
$outsider=wp_insert_user(array('user_login'=>'course-progress-outsider','role'=>'subscriber'));
$course=wp_insert_post(array('post_type'=>'course','post_status'=>'publish','post_title'=>'Course progress fixture','post_author'=>$teacher));
$lessons=array();
foreach(array('First','Second','Empty','Draft') as $title){$id=wp_insert_post(array('post_type'=>'lesson','post_status'=>$title==='Draft'?'draft':'publish','post_title'=>$title,'post_author'=>$teacher,'post_content'=>$title==='Empty'?'':'[h5p id="90001"] [h5p id="90002"] [h5p id="90001"]'));update_post_meta($id,'_lesson_course',$course);$lessons[]=$id;}
[$first,$second,$empty,$draft]=$lessons;
update_post_meta($first,FKHS_Editor::META,array(90001=>70,90002=>0));update_post_meta($second,FKHS_Editor::META,array(90001=>95,90002=>0));
update_post_meta($second,'_order_'.$course,1);update_post_meta($first,'_order_'.$course,2);
foreach(array($a,$b) as $student)Sensei_Course_Manual_Enrolment_Provider::instance()->enrol_learner($student,$course);
global $wpdb;$table=$wpdb->prefix.FKHS_TABLE;
$insert=static function($user,$lesson,$cid,$raw=null,$max=null,$passed=0,$completed=0)use($wpdb,$table){$ok=$wpdb->insert($table,array('user_id'=>$user,'lesson_id'=>$lesson,'content_id'=>$cid,'raw_score'=>$raw,'max_score'=>$max,'passed'=>$passed,'completed'=>$completed,'created_at'=>'2026-01-01 12:00:00'));if(!$ok)throw new RuntimeException('Attempt seed failed');};
$insert($a,$first,90001,8,10);$insert($a,$first,90001,16,20);$insert($a,$first,90001,1,10);
$insert($a,$second,90001,9,10);$insert($a,$second,90002,null,null,0,1);$insert($a,$second,90002);
$insert($b,$first,90001,10,10);$insert($a,$draft,90001,10,10);
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$snapshots=FKHS_Progress::get_lesson_snapshots($a,$lessons);
foreach($lessons as $lesson)$check($snapshots[$lesson]===FKHS_Progress::get_items($a,$lesson,false),'Batch equals canonical full snapshot, including empty/dedup/sticky/ties: '.$lesson);
$check($snapshots[$first][0]['best']['raw']===16.0 && $snapshots[$first][0]['latest']['raw']===1.0,'Latest id and best score tie-break preserved');
$queries=0;$counter=static function($sql)use(&$queries,$table){if(stripos($sql,'SELECT')===0 && str_contains($sql,$table))++$queries;return $sql;};
add_filter('query',$counter);FKHS_Progress::get_lesson_snapshots($a,$lessons);remove_filter('query',$counter);$check($queries===2,'All course attempts read in two bounded queries');
wp_set_current_user($a);$response=$request($course);$data=$response->get_data();
$check($response->get_status()===200 && $data['required_count']===3 && $data['met_count']===2 && $data['optional_count']===1 && $data['activity_count']===4,'Aggregate uses per-lesson thresholds and completion-only requirement');
$check(array_column($data['lessons'],'lesson_id')===array($second,$first),'Published nonempty lessons follow Sensei order');
$check(rest_validate_value_from_schema($data,FKHS_Course_Progress::schema())===true,'Course response matches public schema');
$options=new WP_REST_Request('OPTIONS','/fkhs/v1/course-progress');$check(rest_get_server()->dispatch($options)->get_data()['schema']===FKHS_Course_Progress::schema(),'Course schema exposed by OPTIONS');
$check(!$data['limited'] && str_contains($response->get_headers()['Cache-Control'],'no-store'),'Private no-store response');
$check(!isset($data['user_id']) && !isset($data['lessons'][0]['items']),'Only learner summary, no attempt payloads or other identities');
$check($request($course,array('user_id'=>$b))->get_data()===$data,'Requested user cannot override current learner');
$reorder=static fn($items)=>array_reverse($items);add_filter('sensei_course_get_lessons',$reorder);$check(array_column($request($course)->get_data()['lessons'],'lesson_id')===array($first,$second),'Sensei final order filter respected');remove_filter('sensei_course_get_lessons',$reorder);
wp_set_current_user($b);$check($request($course)->get_data()['met_count']===1,'Other learner gets own results');
foreach(array($outsider,$foreign) as $user){wp_set_current_user($user);$denied=$request($course);$check($denied->get_status()===403 && $denied->get_data()===$request(99999999)->get_data(),'Outsider/foreign teacher cannot distinguish missing or inaccessible course');}
foreach(array(1,$teacher) as $user){wp_set_current_user($user);$check($request($course)->get_status()===200,'Admin/course owner may preview own progress');}
wp_set_current_user(0);$check($request($course)->get_status()===401,'Anonymous denied');wp_set_current_user($a);
foreach(array(0,'1junk',array($course)) as $invalid)$check($request($invalid)->get_status()===400,'Invalid course ID rejected');
$deny=static function($allowed,$lesson_id)use($second){return (int)$lesson_id===$second?false:$allowed;};
add_filter('sensei_can_user_view_lesson',$deny,99,2);$limited=$request($course)->get_data();remove_filter('sensei_can_user_view_lesson',$deny,99);
$check($limited['limited'] && array_column($limited['lessons'],'lesson_id')===array($first) && $limited['met_count']===1,'Locked lesson omitted without reporting it complete');
foreach(array('private','draft','trash') as $status){wp_update_post(array('ID'=>$second,'post_status'=>$status));$check(array_column($request($course)->get_data()['lessons'],'lesson_id')===array($first),'Nonpublished lesson omitted: '.$status);}
wp_update_post(array('ID'=>$second,'post_status'=>'publish','post_password'=>'fixture'));$password=$request($course)->get_data();$check($password['limited'] && count($password['lessons'])===1,'Password-protected lesson inaccessible');wp_update_post(array('ID'=>$second,'post_password'=>''));
foreach(array('private','draft','trash') as $status){wp_update_post(array('ID'=>$course,'post_status'=>$status));$check($request($course)->get_status()===403,'Nonpublished course inaccessible: '.$status);}
wp_update_post(array('ID'=>$course,'post_status'=>'publish','post_password'=>'fixture'));$check($request($course)->get_status()===403,'Password-protected course inaccessible');wp_update_post(array('ID'=>$course,'post_password'=>''));
$fail=static function($sql)use($table){return stripos($sql,'SELECT')===0 && str_contains($sql,$table)?'SELECT * FROM fkhs_missing_course_table':$sql;};
add_filter('query',$fail);$failure=$request($course);remove_filter('query',$fail);$check($failure->get_status()===503 && !isset($failure->get_data()['lessons']),'Storage error does not fabricate partial progress');
$cache=new ReflectionProperty('FKHS_Thresholds','cache');$cache->setValue(null,array());$throw=static function(){throw new RuntimeException('PRIVATE-FILTER-DETAIL');};
add_filter('fkhs_threshold_for_item',$throw);$failure=$request($course);remove_filter('fkhs_threshold_for_item',$throw);$cache->setValue(null,array());
$check($failure->get_status()===503 && !str_contains(wp_json_encode($failure->get_data()),'PRIVATE-FILTER-DETAIL'),'Filter error fails closed without private details');
$filter=static fn($value)=>80.5;add_filter('fkhs_threshold_for_item',$filter);$snapshots=FKHS_Progress::get_lesson_snapshots($a,array($first,$second));foreach(array($first,$second) as $lesson)$check($snapshots[$lesson]===FKHS_Progress::get_items($a,$lesson,false),'Fractional custom threshold agrees with canonical lesson');remove_filter('fkhs_threshold_for_item',$filter);$cache->setValue(null,array());
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$before,'All reads leave attempt history unchanged');
// Native WordPress Application Password and actual HTTP cache/auth boundaries.
$created=WP_Application_Passwords::create_new_application_password($a,array('name'=>'Disposable HSB012'));
try{$http=wp_remote_get(add_query_arg(array('rest_route'=>'/fkhs/v1/course-progress','course_id'=>$course,'user_id'=>$b),home_url('/')),array('timeout'=>30,'headers'=>array('Authorization'=>'Basic '.base64_encode(get_userdata($a)->user_login.':'.$created[0]))));$body=json_decode(wp_remote_retrieve_body($http),true);$check(wp_remote_retrieve_response_code($http)===200 && $body['met_count']===2 && str_contains(wp_remote_retrieve_header($http,'cache-control'),'no-store'),'Real HTTP authenticates own course and bypasses caches');}finally{WP_Application_Passwords::delete_application_password($a,$created[1]['uuid']);}
// Conditional template/block rendering uses the queried course, never a foreign loop.
$markup='<!-- wp:fkhs/course-progress -->[fkhs_course_progress]<!-- /wp:fkhs/course-progress -->';
$check(WP_Block_Type_Registry::get_instance()->is_registered('fkhs/course-progress') && shortcode_exists('fkhs_course_progress'),'Course placement registered');
$GLOBALS['wp_query']=new WP_Query(array('post_type'=>'course','p'=>$course));$GLOBALS['post']=get_post($course);
$output=array();foreach(array(0,$a,$b) as $viewer){wp_set_current_user($viewer);$output[]=do_blocks($markup);}
$check(count(array_unique($output))===1 && substr_count($output[0],'data-fkhs-course-progress')===1 && !str_contains($output[0],'[fkhs_course_progress]'),'Template dynamic block has identical nonpersonal HTML for every viewer');
$check(str_contains(do_blocks('<!-- wp:fkhs/course-progress {"align":"wide","style":{"spacing":{"padding":{"top":"24px"}}}} /-->'),'padding-top:24px'),'Course block honors template layout');
$check(do_blocks('<!-- wp:fkhs/progress /-->')==='','Lesson progress stays hidden on course');
$GLOBALS['post']=get_post($first);$check(do_blocks($markup)==='','Foreign Query Loop does not expose current course progress');
$GLOBALS['wp_query']=new WP_Query(array('post_type'=>'lesson','p'=>$first));$check(do_shortcode('[fkhs_course_progress]')==='','Course view hidden outside course');
$empty_course=wp_insert_post(array('post_type'=>'course','post_status'=>'publish','post_title'=>'Empty course'));$GLOBALS['wp_query']=new WP_Query(array('post_type'=>'course','p'=>$empty_course));$GLOBALS['post']=get_post($empty_course);$check(do_blocks($markup)==='' && do_shortcode('[fkhs_course_progress]')==='','Course without activities has no markup');
wp_set_current_user(1);$check($request($empty_course)->get_data()['lessons']===array(),'Empty course has no invented completion percentage');
// Locale and enqueue isolation on actual course routes.
$GLOBALS['wp_query']=new WP_Query(array('post_type'=>'course','p'=>$course));$GLOBALS['post']=get_post($course);
foreach(array('en_US','nb_NO') as $locale){
 $locale_filter=static fn()=>$locale;add_filter('locale',$locale_filter,99);add_filter('determine_locale',$locale_filter,99);unload_textdomain('h5p-sensei-bridge',true);
 $GLOBALS['wp_scripts']=new WP_Scripts();FKHS_Course_Progress::enqueue();FKHS_Plugin::enqueue_front();FKHS_Progress_View::enqueue_editor();
 $raw=wp_scripts()->get_data('fkhs-course-progress','data');$config=json_decode(substr($raw,strlen('var fkhsCourseProgress = '),-1),true);
 $check($config['labels']['title']===($locale==='nb_NO'?'Din H5P-progresjon i kurset':'Your H5P progress in this course'),'Course frontend translated: '.$locale);
 $raw=wp_scripts()->get_data('fkhs-progress-editor','data');$labels=json_decode(substr($raw,strlen('var fkhsProgressBlock = '),-1),true);
 $check($labels['courseTitle']===($locale==='nb_NO'?'H5P-kursprogresjon':'H5P course progress'),'Course editor translated: '.$locale);
 $check(!wp_script_is('fkhs-h5p-bridge','enqueued'),'Course page does not bind lesson event tracking');
 remove_filter('locale',$locale_filter,99);remove_filter('determine_locale',$locale_filter,99);
}
$inject=static function($items)use($empty){$items[]=get_post($empty);return $items;};add_filter('sensei_course_get_lessons',$inject);$check($request($course)->get_status()===503,'Duplicate lesson injected by custom filter fails closed');remove_filter('sensei_course_get_lessons',$inject);
// Exact capacity and over-limit behavior: current pairs, not historical attempts.
$large=wp_insert_post(array('post_type'=>'lesson','post_status'=>'publish','post_title'=>'Capacity'));update_post_meta($large,'_lesson_course',$empty_course);
$codes=array_map(static fn($id)=>'[h5p id="'.$id.'"]',range(91000,91499));wp_update_post(array('ID'=>$large,'post_content'=>implode(' ',$codes)));
$start=microtime(true);$maximum=$request($empty_course);$elapsed=microtime(true)-$start;
$check($maximum->get_status()===200 && $maximum->get_data()['activity_count']===500,'500 current pairs are supported');
// Significant history remains in SQL; only one snapshot row per current pair returns.
for($batch=0;$batch<20;++$batch){$values=array();for($n=0;$n<500;++$n)$values[]=$wpdb->prepare('(%d,%d,%d,8,10,0,0)',1,$large,91000+$n);$check(false!==$wpdb->query("INSERT INTO $table (user_id,lesson_id,content_id,raw_score,max_score,passed,completed) VALUES ".implode(',',$values)),'Seed bounded performance history batch');}
$snapshot_sql='';$capture=static function($sql)use(&$snapshot_sql){if(str_starts_with($sql,'SELECT s.lesson_id'))$snapshot_sql=$sql;return $sql;};add_filter('query',$capture);
$start=microtime(true);$history=$request($empty_course);$history_elapsed=microtime(true)-$start;remove_filter('query',$capture);
WP_CLI::log('Course batch EXPLAIN: '.wp_json_encode(array_values(array_filter($wpdb->get_results('EXPLAIN '.$snapshot_sql,ARRAY_A),static fn($plan)=>null!==$plan['table']))));
WP_CLI::log('Course snapshot elapsed: '.round($history_elapsed*1000).' ms.');
$check($history->get_status()===200 && $history->get_data()['met_count']===500,'10,000 attempts aggregate to 500 current requirements');
$check($history_elapsed<5,'500-pair history snapshot stays below a generous 5-second local/CI budget');
WP_CLI::log('Course benchmark: 500 pairs / 10,000 attempts: '.round($history_elapsed*1000).' ms.');
$check(10000===$wpdb->delete($table,array('user_id'=>1,'lesson_id'=>$large)),'Synthetic course benchmark history removed');
wp_update_post(array('ID'=>$large,'post_content'=>implode(' ',$codes).' [h5p id="91500"]'));$check($request($empty_course)->get_status()===422,'501 pairs fail visibly instead of truncating');
try{FKHS_Progress::get_lesson_snapshots($a,array_fill(0,201,$first));$check(false,'Expected lesson cap');}catch(LengthException $e){$check(true,'Batch caps lesson input');}
$over=static function($posts,$query)use($course,$first){return ($query->get('post_type')==='lesson' && (int)($query->get('meta_query')[0]['value'] ?? 0)===$course)?array_fill(0,201,get_post($first)):$posts;};
add_filter('posts_results',$over,99,2);$check($request($course)->get_status()===422,'201 lessons fail visibly instead of truncating');remove_filter('posts_results',$over,99);
WP_CLI::success("$checks course progress assertions passed; 500-pair read ".round($elapsed*1000)." ms.");
