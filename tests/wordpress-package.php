<?php
/** Destructive fixture setup is allowed only in the disposable integration database. */
if (!defined('FKHS_INTEGRATION_TESTS') || true !== FKHS_INTEGRATION_TESTS || !defined('WP_CLI') || !WP_CLI || home_url() !== 'http://wordpress') {
    throw new RuntimeException('Disposable package fixture required.');
}
global $wpdb;
$table=$wpdb->prefix.'fkhs_attempts';
$checks=0;
$check=static function($ok,$message) use (&$checks) { if(!$ok)throw new RuntimeException($message); ++$checks; };
$rows=static fn()=>$wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A);
$post=static function($payload){$r=new WP_REST_Request('POST','/fkhs/v1/h5p-xapi');$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($payload));return rest_get_server()->dispatch($r);};
wp_set_current_user(1);
$phase=$args[0]??'';
$fixture=get_option('fkhs_package_fixture',array());
if('fresh'===$phase) {
    $header=get_plugin_data(FKHS_FILE);
    $check(version_compare(FKHS_VER,'1.1.0','>') && $header['Version']===FKHS_VER && get_option('fkhs_db_ver')==='0.3.0','Fresh ZIP activates with current plugin/schema versions');
    update_option('fkhs_package_version',FKHS_VER,false);
    $check(is_file(FKHS_DIR.'LICENSE') && is_file(FKHS_DIR.'languages/h5p-sensei-bridge-nb_NO.mo'),'ZIP contains license and compiled translation');
    $check($rows()===array(),'Fresh database contains no attempts');
    $check(count($wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name='event_identity'"))===4,'Fresh schema contains identity index');
    // Reset ONLY this proven-empty disposable table so historical activation builds 0.2.0 itself.
    $check(false!==$wpdb->query("DROP TABLE {$table}"),'Empty fixture schema reset');
    delete_option('fkhs_db_ver');
} elseif('legacy'===$phase) {
    $check(FKHS_VER==='0.1.0' && FKHS_DB_VER==='0.2.0','Immutable develop package actually running');
    $check(!in_array('event_id',$wpdb->get_col("SHOW COLUMNS FROM {$table}"),true),'Historical activation creates old schema');
    $lesson=wp_insert_post(array('post_type'=>'lesson','post_status'=>'publish','post_title'=>'Package migration fixture'));
    $wpdb->insert($wpdb->prefix.'h5p_contents',array('title'=>'Package H5P','parameters'=>'{}','filtered'=>'{}','slug'=>'package-fixture','library_id'=>0,'user_id'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
    $cid=(int)$wpdb->insert_id;
    wp_update_post(array('ID'=>$lesson,'post_content'=>'[h5p id="'.$cid.'"]'));
    update_post_meta($lesson,'_fkhs_threshold_map',array($cid=>70));
    update_post_meta($lesson,'_fkhs_pass_threshold',60);
    $payload=array('lesson_id'=>$lesson,'contentId'=>$cid,'statement'=>array('verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed'),'result'=>array('score'=>array('raw'=>8,'max'=>10),'completion'=>true)));
    $check($post($payload)->get_status()===200 && count($rows())===1,'Old package writes a real REST attempt');
    update_option('fkhs_package_fixture',array('lesson'=>$lesson,'cid'=>$cid,'payload'=>$payload,'legacy'=>$rows(),'meta'=>get_post_meta($lesson)),false);
} elseif('upgrade'===$phase) {
    $check(FKHS_VER===get_option('fkhs_package_version') && get_option('fkhs_db_ver')==='0.3.0','Replacement ZIP runs current version and upgrades schema');
    $actual=$rows();
    $check(count($actual)===1 && $actual[0]['event_id']===null && $actual[0]['payload_hash']===null,'Historical attempt gets nullable identity');
    unset($actual[0]['event_id'],$actual[0]['payload_hash']);
    $check($actual===$fixture['legacy'],'Every historical value preserved by real package upgrade');
    $check(get_post_meta($fixture['lesson'])===$fixture['meta'],'Threshold metadata preserved by upgrade');
    $payload=$fixture['payload'];$payload['event_id']='12345678-1234-4234-8234-123456789abc';
    $result=$post($payload);
    $check($result->get_status()===200 && $result->get_data()['stored'],'Upgraded package accepts identified attempt');
    $fixture['payload']=$payload;$fixture['current']=$rows();
    update_option('fkhs_package_fixture',$fixture,false);
} elseif('rollback'===$phase) {
    $check(FKHS_VER==='0.1.0' && get_option('fkhs_db_ver')==='0.2.0','Old files reactivate and rewrite their historical schema marker');
    $check($rows()===$fixture['current'],'Historical activation retains all current columns/rows');
    $check(get_post_meta($fixture['lesson'])===$fixture['meta'],'Rollback retains thresholds');
    $result=$post($fixture['payload']);
    $check($result->get_status()===200 && count($rows())===3,'Actual historical REST writer works on additive schema');
    $actual=$rows();$last=end($actual);
    $check($last['event_id']===null && $last['payload_hash']===null,'Old writer omits new receipts despite UUID: rollback loses dedup behavior');
    $fixture['rollback']=$actual;update_option('fkhs_package_fixture',$fixture,false);
} elseif('reupgrade'===$phase) {
    $check(FKHS_VER===get_option('fkhs_package_version') && get_option('fkhs_db_ver')==='0.3.0','Candidate reinstated after file rollback');
    $check($rows()===$fixture['rollback'],'Reupgrade retains original, identified and rollback attempts');
    $result=$post($fixture['payload']);
    $check($result->get_status()===200 && $result->get_data()['duplicate'] && count($rows())===3,'Original receipt remains usable after rollback and reupgrade');
} elseif('mutate'===$phase) {
    $check(1===$wpdb->update($table,array('raw_score'=>0),array('id'=>$fixture['current'][0]['id'])),'Synthetic mutation proves backup restore');
} elseif('restore'===$phase) {
    $check($rows()===$fixture['current'],'Database backup restores exact pre-rollback rows including receipts');
    $check(get_option('fkhs_db_ver')==='0.3.0' && get_post_meta($fixture['lesson'])===$fixture['meta'],'Backup restores schema marker and metadata');
    $result=$post($fixture['payload']);
    $check($result->get_status()===200 && $result->get_data()['duplicate'],'Restored receipt still deduplicates');
    $check(false!==$wpdb->delete($table,array('lesson_id'=>$fixture['lesson'])),'Remove package fixture attempts only');
    wp_delete_post($fixture['lesson'],true);
    $wpdb->delete($wpdb->prefix.'h5p_contents',array('id'=>$fixture['cid']));
    delete_option('fkhs_package_fixture');
    delete_option('fkhs_package_version');
    $check($rows()===array(),'Package fixture leaves other integration tests an empty attempt history');
} else { throw new RuntimeException('Unknown package test phase'); }
WP_CLI::success("$checks package $phase assertions passed.");
