<?php
/** Disposable-only lifecycle tests; all mail is intercepted by the fixture MU plugin. */
if (!defined('FKHS_INTEGRATION_TESTS') || true !== FKHS_INTEGRATION_TESTS || !defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Disposable integration environment required.'); }
global $wpdb, $privacy_checks;
$privacy_checks=0;
function privacy_check($ok,$label){global $privacy_checks;if(!$ok)throw new RuntimeException($label);++$privacy_checks;}
function privacy_count($user){global $wpdb;return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.FKHS_TABLE.' WHERE user_id=%d',$user));}
function privacy_post($payload){$r=new WP_REST_Request('POST','/fkhs/v1/h5p-xapi');$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($payload));return rest_get_server()->dispatch($r);}
wp_set_current_user(1);
$table=$wpdb->prefix.FKHS_TABLE;
$a=wp_insert_user(array('user_login'=>'privacy-a','user_email'=>'privacy-a@example.test','role'=>'subscriber'));
$b=wp_insert_user(array('user_login'=>'privacy-b','user_email'=>'privacy-b@example.test','role'=>'subscriber'));
$teacher=wp_insert_user(array('user_login'=>'privacy-teacher','user_email'=>'privacy-teacher@example.test','role'=>'teacher'));
$lesson=wp_insert_post(array('post_type'=>'lesson','post_status'=>'publish','post_title'=>'Privacy <script>test</script>'));
$course=wp_insert_post(array('post_type'=>'course','post_status'=>'publish','post_title'=>'Privacy course'));
update_post_meta($lesson,'_lesson_course',$course);
$wpdb->insert($wpdb->prefix.'h5p_contents',array('title'=>'Privacy content','parameters'=>'{}','filtered'=>'{}','slug'=>'privacy-content','library_id'=>0,'user_id'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
$cid=(int)$wpdb->insert_id;
wp_update_post(array('ID'=>$lesson,'post_content'=>'[h5p id="'.$cid.'"]'));
update_post_meta($lesson,'_fkhs_threshold_map',array($cid=>70));
Sensei_Course_Manual_Enrolment_Provider::instance()->enrol_learner($a,$course);
$raw='{"actor":{"mbox":"mailto:privacy-a@example.test"},"answer":"<script>alert(1)</script> & blåbær"}';
$ids=array();
for($i=0;$i<201;$i++) {
    if(1!==$wpdb->insert($table,array('user_id'=>$a,'lesson_id'=>$lesson,'lesson_title'=>'Privacy <script>test</script>','content_id'=>$cid,'raw_score'=>8,'max_score'=>10,'passed'=>1,'completed'=>1,'statement'=>$raw,'created_at'=>$i===200?'2026-11-03 12:00:00':'2020-01-01 12:00:00')))throw new RuntimeException($wpdb->last_error);
    $ids[]=(int)$wpdb->insert_id;
}
$wpdb->insert($table,array('user_id'=>$b,'lesson_id'=>$lesson,'content_id'=>$cid,'statement'=>'OTHER-USER','created_at'=>'2026-11-03 12:00:00'));
privacy_check(privacy_count($a)===201,'Populated lifecycle fixture');
$original=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d ORDER BY id",$a),ARRAY_A);
$exports=apply_filters('wp_privacy_personal_data_exporters',array());$erasers=apply_filters('wp_privacy_personal_data_erasers',array());
privacy_check(isset($exports['h5p-sensei-bridge'],$erasers['h5p-sensei-bridge']),'Native privacy hooks registered');
foreach(array(0,$a,$teacher) as $viewer) {
    wp_set_current_user($viewer);
    privacy_check(is_wp_error(FKHS_Privacy::export('privacy-a@example.test')),'Unauthorized export');
    privacy_check(is_wp_error(FKHS_Privacy::erase('privacy-a@example.test')),'Unauthorized erasure');
    privacy_check(is_wp_error(FKHS_Privacy::cleanup_statements(array('before'=>'2025-01-01'))),'Unauthorized cleanup');
}
wp_set_current_user(1);
foreach(array(0,-1,1.5,'2junk',array(1),1000001) as $page) privacy_check(is_wp_error(FKHS_Privacy::export('privacy-a@example.test',$page)),'Invalid page rejected');
privacy_check(is_wp_error(FKHS_Privacy::export('not-an-email')),'Invalid email rejected');
privacy_check(FKHS_Privacy::export('absent@example.test')===array('data'=>array(),'done'=>true),'Unknown email has no unrelated data');
$exported=array();$all_items=array();
for($page=1;$page<=12;$page++) {
    $result=FKHS_Privacy::export('privacy-a@example.test',$page);
    privacy_check(!is_wp_error($result) && count($result['data'])<=20,'Bounded export page');
    foreach($result['data'] as $item){$exported[]=$item['item_id'];$all_items[$item['item_id']]=$item['data'];}
    if($result['done'])break;
}
privacy_check($page===11 && count($exported)===201 && count(array_unique($exported))===201,'Export covers every row without duplicate IDs');
privacy_check($exported===array_map(static fn($id)=>'fkhs-attempt-'.$id,$ids),'Stable ID order across export pages');
$fields=array_column($all_items[$exported[0]],'value','name');
privacy_check(html_entity_decode($fields['Raw xAPI statement'],ENT_QUOTES,'UTF-8')===$raw,'Raw data exported without silently dropping answers');
privacy_check(!str_contains($fields['Raw xAPI statement'],'<script>'),'Raw markup escaped for WordPress export HTML');
privacy_check(!str_contains(wp_json_encode($all_items),'OTHER-USER'),'No cross-user export');
// Exercise WordPress aggregation and the actual ZIP, without sending any email.
require_once ABSPATH.'wp-admin/includes/privacy-tools.php';
privacy_check(class_exists('ZipArchive'),'ZIP export runtime available');
$zip_request=wp_create_user_request('privacy-a@example.test','export_personal_data');
$only_bridge=static fn($registered)=>array('h5p-sensei-bridge'=>$registered['h5p-sensei-bridge']);
add_filter('wp_privacy_personal_data_exporters',$only_bridge,999);
for($page=1;$page<=12;$page++) {
    $batch=FKHS_Privacy::export('privacy-a@example.test',$page);
    $merged=wp_privacy_process_personal_data_export_page($batch,1,'privacy-a@example.test',$page,$zip_request,false,'h5p-sensei-bridge');
    privacy_check(!is_wp_error($merged),'WordPress aggregates export page');
    if($batch['done'])break;
}
remove_filter('wp_privacy_personal_data_exporters',$only_bridge,999);
$zip_path=wp_privacy_exports_dir().get_post_meta($zip_request,'_export_file_name',true);
$zip=new ZipArchive();privacy_check($zip->open($zip_path)===true,'Native export ZIP generated');
$html=$zip->getFromName('index.html');$json=json_decode($zip->getFromName('export.json'),true);$zip->close();
privacy_check(!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'),'Native HTML export safely preserves literal markup');
$json_groups=is_array($json)?reset($json):array();
privacy_check(count($json_groups['h5p-sensei-bridge']['items']??array())===201,'Native JSON ZIP contains all 201 attempts');
wp_delete_file($zip_path);
$retained=FKHS_Privacy::erase('privacy-a@example.test');
privacy_check($retained['items_retained'] && !$retained['items_removed'] && $retained['done'] && $retained['messages'],'Default erasure explicitly reports retention');
privacy_check(privacy_count($a)===201,'Default erasure leaves every attempt');
// Oversized legacy raw statements fail visibly, without loading/truncating them.
$wpdb->update($table,array('statement'=>str_repeat('x',FKHS_Privacy::MAX_RAW_EXPORT_BYTES+1)),array('id'=>$ids[0]));
$oversized=FKHS_Privacy::export('privacy-a@example.test');privacy_check(is_wp_error($oversized) && $oversized->get_error_code()==='fkhs_privacy_oversized','Oversized payload not silently truncated');
$wpdb->update($table,array('statement'=>$raw),array('id'=>$ids[0]));
// Preview is read-only; apply uses the same lower cursor, not the preview next cursor.
$preview=FKHS_Privacy::cleanup_statements(array('before'=>'2025-01-01','batch_size'=>50));
privacy_check($preview['dry_run'] && $preview['matched']===50 && $preview['changed']===0 && !$preview['done'],'Bounded dry run');
privacy_check($wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d ORDER BY id",$a),ARRAY_A)===$original,'Dry run changes no data');
foreach(array(array('before'=>'2026-02-30'),array('before'=>'2025-01-01','apply'=>true),array('before'=>'2025-01-01','batch_size'=>201),array('before'=>'2025-01-01','after_id'=>-1),array('before'=>'2025-01-01','through_id'=>1,'after_id'=>2)) as $bad) privacy_check(is_wp_error(FKHS_Privacy::cleanup_statements($bad)),'Unsafe cleanup parameters rejected');
// Insert an old-dated event after the reviewed high-water ID: it must remain untouched.
$wpdb->insert($table,array('user_id'=>$b,'lesson_id'=>$lesson,'content_id'=>$cid,'statement'=>'AFTER-SNAPSHOT','created_at'=>'2020-01-01 12:00:00'));$late=(int)$wpdb->insert_id;
$before_items=FKHS_Progress::get_items($a,$lesson);
$cursor=0;$changed=0;
for($run=0;$run<20;$run++) {
    $result=FKHS_Privacy::cleanup_statements(array('before'=>'2025-01-01','through_id'=>$preview['through_id'],'after_id'=>$cursor,'batch_size'=>50,'apply'=>true));
    privacy_check(!is_wp_error($result) && $result['changed']<=50,'Bounded cleanup apply');
    $changed+=$result['changed'];$cursor=$result['next_after_id'];if($result['done'])break;
}
privacy_check($changed>=200,'Eligible old raw statements cleared');
$after=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d ORDER BY id",$a),ARRAY_A);
foreach($after as $index=>$row){$copy=$row;$copy['statement']=$original[$index]['statement'];if($copy!==$original[$index])throw new RuntimeException('Cleanup rewrote canonical progress');}
privacy_check(count($after)===201 && $after[0]['statement']===null && $after[200]['statement']===$raw,'Cleanup preserves rows, newer raw data and every non-statement field');
privacy_check($wpdb->get_var($wpdb->prepare("SELECT statement FROM {$table} WHERE id=%d",$late))==='AFTER-SNAPSHOT','High-water ID excludes later inserts');
privacy_check(FKHS_Progress::get_items($a,$lesson)===$before_items,'Cleanup leaves progress exactly unchanged');
$replay=FKHS_Privacy::cleanup_statements(array('before'=>'2025-01-01','through_id'=>$preview['through_id'],'apply'=>true));privacy_check($replay['changed']===0 && $replay['done'],'Cleanup retries are idempotent');
// New-write retention is independent of hashing/acknowledgement/progress.
wp_set_current_user($a);
$payload=array('lesson_id'=>$lesson,'contentId'=>$cid,'event_id'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd','statement'=>array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed'),'result'=>array('score'=>array('raw'=>8,'max'=>10),'completion'=>true)));
add_filter('fkhs_store_raw_statement','__return_false');$first=privacy_post($payload);remove_filter('fkhs_store_raw_statement','__return_false');
privacy_check($first->get_status()===200 && $first->get_data()['stored'],'Opt-out still acknowledges stored attempt');
$id=$first->get_data()['attempt_id'];$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$id),ARRAY_A);
privacy_check($row['statement']===null && strlen($row['payload_hash'])===64 && $row['passed']==='1','Raw opt-out retains score and hash');
privacy_check(privacy_post($payload)->get_data()['duplicate']===true,'Retry deduplicates after raw opt-out');
$conflict=$payload;$conflict['statement']['result']['score']['raw']=9;privacy_check(privacy_post($conflict)->get_status()===409,'Conflicting retry still rejected');
$payload['event_id']='eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';$stored=privacy_post($payload);$stored_id=$stored->get_data()['attempt_id'];
privacy_check($wpdb->get_var($wpdb->prepare("SELECT statement FROM {$table} WHERE id=%d",$stored_id))!==null,'Default new-write retention unchanged');
wp_set_current_user(1);$wpdb->update($table,array('created_at'=>'2020-01-01 12:00:00'),array('id'=>$stored_id));
$review=FKHS_Privacy::cleanup_statements(array('before'=>'2025-01-01'));
FKHS_Privacy::cleanup_statements(array('before'=>'2025-01-01','through_id'=>$review['through_id'],'apply'=>true));
wp_set_current_user($a);privacy_check(privacy_post($payload)->get_data()['duplicate']===true,'Retry deduplicates after raw cleanup');wp_set_current_user(1);
// SQL errors must not be represented as a completed export/erasure/cleanup.
$fail_read=static fn($sql)=>stripos($sql,'SELECT')===0 && str_contains($sql,FKHS_TABLE)?'SELECT * FROM fkhs_missing_privacy_table':$sql;
add_filter('query',$fail_read);
foreach(array(FKHS_Privacy::export('privacy-a@example.test'),FKHS_Privacy::erase('privacy-a@example.test'),FKHS_Privacy::cleanup_statements(array('before'=>'2025-01-01'))) as $error)privacy_check(is_wp_error($error),'Failed read is visible');
remove_filter('query',$fail_read);
clean_user_cache($a);
$fail_user=static fn($sql)=>stripos($sql,'SELECT')===0 && str_contains($sql,$wpdb->users)?'SELECT * FROM fkhs_missing_user_table':$sql;
add_filter('query',$fail_user);
privacy_check(is_wp_error(FKHS_Privacy::export('privacy-a@example.test')),'Failed subject lookup is not an empty successful export');
remove_filter('query',$fail_user);

$allow=static fn($allowed,$user)=>$user===$a;
add_filter('fkhs_allow_personal_data_erasure',$allow,10,2);
$fail_write=static fn($sql)=>preg_match('/^(DELETE FROM|UPDATE) .*fkhs_attempts/i',$sql)?'DELETE FROM fkhs_missing_privacy_table':$sql;
add_filter('query',$fail_write);
privacy_check(is_wp_error(FKHS_Privacy::erase('privacy-a@example.test')),'Failed deletion not acknowledged');
privacy_check(is_wp_error(FKHS_Privacy::cleanup_statements(array('before'=>'2027-01-01','through_id'=>$stored_id,'apply'=>true))),'Failed raw cleanup not acknowledged');
remove_filter('query',$fail_write);remove_filter('fkhs_allow_personal_data_erasure',$allow,10);
// Native WordPress Ajax: capability, nonce, exporter registration and retention message.
$token=WP_Session_Tokens::get_instance(1)->create(time()+HOUR_IN_SECONDS);
$cookie=wp_generate_auth_cookie(1,time()+HOUR_IN_SECONDS,'logged_in',$token);$_COOKIE[LOGGED_IN_COOKIE]=$cookie;
$export_request=$zip_request;
$erase_request=wp_create_user_request('privacy-a@example.test','remove_personal_data');
$ajax=static function($action,$request,$index_key,$index,$nonce)use($cookie){
    $response=wp_remote_post(admin_url('admin-ajax.php'),array('timeout'=>30,'headers'=>array('Cookie'=>LOGGED_IN_COOKIE.'='.rawurlencode($cookie)),'body'=>array('action'=>$action,'id'=>$request,$index_key=>$index,'page'=>1,'security'=>$nonce,'sendAsEmail'=>'false')));
    if(is_wp_error($response))throw new RuntimeException($response->get_error_message());
    return json_decode(wp_remote_retrieve_body($response),true);
};
$ei=array_search('h5p-sensei-bridge',array_keys($exports),true)+1;$ri=array_search('h5p-sensei-bridge',array_keys($erasers),true)+1;
$http=$ajax('wp-privacy-export-personal-data',$export_request,'exporter',$ei,wp_create_nonce('wp-privacy-export-personal-data-'.$export_request));
privacy_check(($http['success']??false)===true,'Native export Ajax accepts callback: '.wp_json_encode($http));
$http=$ajax('wp-privacy-erase-personal-data',$erase_request,'eraser',$ri,wp_create_nonce('wp-privacy-erase-personal-data-'.$erase_request));
privacy_check(($http['success']??false)===true && $http['data']['items_retained']===true,'Native eraser reports default retention: '.wp_json_encode($http));
$http=$ajax('wp-privacy-export-personal-data',$export_request,'exporter',$ei,'invalid');privacy_check(($http['success']??false)!==true,'Invalid native Ajax nonce denied');
// Populated reinstall and file rollback contract: no database downgrade, deletion or regrading.
$before_lifecycle=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A);
$plugin='h5p-sensei-bridge/h5p-sensei-bridge.php';
deactivate_plugins($plugin,true);privacy_check($wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A)===$before_lifecycle,'Deactivation preserves history');
uninstall_plugin($plugin);privacy_check($wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A)===$before_lifecycle,'Native uninstall entry point preserves populated table');
privacy_check(!is_wp_error(activate_plugin($plugin)),'Reactivation succeeds');
privacy_check($wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A)===$before_lifecycle,'Reactivation/upgrade preserves all populated values');
privacy_check(get_post_meta($lesson,'_fkhs_threshold_map',true)===array($cid=>70),'Lifecycle preserves lesson thresholds');
$legacy=$row;unset($legacy['id'],$legacy['event_id'],$legacy['payload_hash']);
privacy_check($wpdb->insert($table,$legacy)===1,'Legacy pre-identity insert format works after file rollback');
privacy_check(FKHS_Plugin::maybe_upgrade(),'Upgrade after legacy insert succeeds');
privacy_check(get_option('fkhs_db_ver')==='0.3.0','No destructive schema downgrade');
// Explicit personal-data erasure uses remaining-row batches (never OFFSET).
add_filter('fkhs_allow_personal_data_erasure',$allow,10,2);$other_before=privacy_count($b);
$first=FKHS_Privacy::erase('privacy-a@example.test',1);
privacy_check($first['items_removed'] && !$first['done'] && privacy_count($a)===104,'First erase removes only 100 rows');
$second=FKHS_Privacy::erase('privacy-a@example.test',2);privacy_check($second['items_removed'] && !$second['done'] && privacy_count($a)===4,'Second erase skips no rows');
$third=FKHS_Privacy::erase('privacy-a@example.test',3);privacy_check($third['items_removed'] && $third['done'] && privacy_count($a)===0,'Final erase removes complete scoped history');
privacy_check(privacy_count($b)===$other_before,'Other user history retained');
$again=FKHS_Privacy::erase('privacy-a@example.test',3);privacy_check($again['done'] && !$again['items_removed'],'Erasure retry is safe');
privacy_check(!FKHS_Progress::summarize(FKHS_Progress::get_items($a,$lesson))['passed'],'Explicit full erasure changes required H5P progress');
remove_filter('fkhs_allow_personal_data_erasure',$allow,10);
// Verify the registered CLI command in separate processes, including its exit statuses.
$cli_after=(int)$wpdb->get_var("SELECT MAX(id) FROM {$table}");
$cli_ids=array();
foreach(array('CLI-ONE','CLI-TWO') as $statement) {
    $wpdb->insert($table,array('user_id'=>$b,'lesson_id'=>$lesson,'content_id'=>$cid,'raw_score'=>8,'max_score'=>10,'passed'=>1,'completed'=>1,'statement'=>$statement,'created_at'=>'2020-01-01 12:00:00'));
    $cli_ids[]=(int)$wpdb->insert_id;
}
$cli_through=max($cli_ids);
$cli_snapshot=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A);
$command='fkhs cleanup-statements --before=2025-01-01 --after-id='.$cli_after.' --through-id='.$cli_through.' --batch-size=1';
$run_cli=static fn($cmd)=>WP_CLI::runcommand($cmd,array('return'=>'all','exit_error'=>false));
$preview_cli=$run_cli($command.' --user=1');
privacy_check($preview_cli->return_code===0,'CLI preview succeeds: '.$preview_cli->stderr);
$preview_data=json_decode($preview_cli->stdout,true);
privacy_check($preview_data['dry_run']===true && $preview_data['matched']===1 && !$preview_data['done'],'CLI defaults to bounded preview');
privacy_check($wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A)===$cli_snapshot,'CLI preview is read-only');
$no_apply=$run_cli($command.' --no-apply --user=1');$no_apply_data=json_decode($no_apply->stdout,true);
privacy_check($no_apply->return_code===0 && $no_apply_data['dry_run']===true,'Explicit --no-apply remains a preview');
$false_apply=$run_cli($command.' --apply=false --user=1');$false_apply_data=json_decode($false_apply->stdout,true);
privacy_check($false_apply->return_code!==0 || ($false_apply_data['dry_run']??false)===true,'Text false cannot opt into cleanup');

privacy_check($run_cli($command.' --user='.$b)->return_code!==0,'CLI refuses student');
privacy_check($run_cli('fkhs cleanup-statements --before=2025-01-01 --apply --user=1')->return_code!==0,'CLI refuses apply without reviewed ID');
$applied_cli=$run_cli($command.' --apply --user=1');$applied=json_decode($applied_cli->stdout,true);
privacy_check($applied_cli->return_code===0 && $applied['changed']===1 && !$applied['done'],'CLI apply changes one raw statement');
$last_cli=$run_cli('fkhs cleanup-statements --before=2025-01-01 --after-id='.$applied['next_after_id'].' --through-id='.$cli_through.' --batch-size=1 --apply --user=1');$last=json_decode($last_cli->stdout,true);
privacy_check($last_cli->return_code===0 && $last['changed']===1 && $last['done'],'CLI resumes without skipping a row');
$cli_after_rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A);
foreach($cli_after_rows as &$candidate){if(in_array((int)$candidate['id'],$cli_ids,true))$candidate['statement']=(int)$candidate['id']===$cli_ids[0]?'CLI-ONE':'CLI-TWO';}unset($candidate);
privacy_check($cli_after_rows===$cli_snapshot,'CLI preserves all other users, attempts, hashes and fields');
WP_CLI::success("$privacy_checks privacy/lifecycle assertions passed.");
