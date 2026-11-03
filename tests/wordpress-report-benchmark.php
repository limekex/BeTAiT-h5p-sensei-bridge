<?php
/** Repeatable synthetic benchmark, guarded to the disposable Docker environment. */
if (!defined('FKHS_INTEGRATION_TESTS') || true !== FKHS_INTEGRATION_TESTS || !defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Disposable integration environment required.'); }
global $wpdb;
$table=$wpdb->prefix.FKHS_TABLE;
$teacher=wp_insert_user(array('user_login'=>'benchmark-teacher','role'=>'teacher'));
$student=wp_insert_user(array('user_login'=>'benchmark-student','role'=>'subscriber'));
$course=wp_insert_post(array('post_type'=>'course','post_status'=>'publish','post_title'=>'Benchmark course','post_author'=>$teacher));
$lesson=wp_insert_post(array('post_type'=>'lesson','post_status'=>'publish','post_title'=>'Benchmark lesson','post_author'=>$teacher));
update_post_meta($lesson,'_lesson_course',$course);
$contents=array();
for($i=0;$i<50;$i++) {
    $wpdb->insert($wpdb->prefix.'h5p_contents',array('title'=>'Benchmark '.$i,'parameters'=>'{}','filtered'=>'{}','slug'=>'benchmark-'.$i,'library_id'=>0,'user_id'=>$teacher,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
    $contents[]=(int)$wpdb->insert_id;
}
$cache=new ReflectionProperty('FKHS_Thresholds','cache');
if (PHP_VERSION_ID < 80100) $cache->setAccessible(true);
$measure=static function($callback) use($wpdb,$cache) {
    $times=$counts=array();
    for($i=0;$i<15;$i++) {
        wp_cache_flush();$cache->setValue(null,array());
        $before=$wpdb->num_queries;$start=hrtime(true);$result=$callback();
        if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
        $times[]=(hrtime(true)-$start)/1e6;$counts[]=$wpdb->num_queries-$before;
    }
    sort($times);
    return array('p50_ms'=>round($times[7],2),'p95_ms'=>round($times[14],2),'queries_min'=>min($counts),'queries_max'=>max($counts),'samples'=>15);
};
$captured=array();
$capture=static function($sql) use(&$captured,$table) {
    if(str_starts_with($sql,'SELECT a.id,') && str_contains($sql,$table)) $captured['report']=$sql;
    if(str_contains($sql,'SELECT raw_score, max_score, passed, completed, created_at') && str_contains($sql,$table)) $captured['latest']=$sql;
    return $sql;
};
add_filter('query',$capture);
try {
    foreach(array(1000,10000,100000) as $attempts) {
        foreach(array(1,10,50) as $activities) {
            // Only this script's synthetic lesson is reset; other integration fixtures stay intact.
            $wpdb->delete($table,array('lesson_id'=>$lesson));
            $active=array_slice($contents,0,$activities);
            wp_update_post(array('ID'=>$lesson,'post_content'=>implode(' ',array_map(static fn($id)=>'[h5p id="'.$id.'"]',$active))));
            for($offset=0;$offset<$attempts;$offset+=1000) {
                $values=array();
                for($i=$offset;$i<min($attempts,$offset+1000);$i++) {
                    $cid=$active[$i%$activities];$score=$i%11;
                    // All attempts belong to one learner: intentionally heavy history per activity.
                    $values[]=$wpdb->prepare('(%d,%d,%d,%d,10,%d,1,%s)', $student,$lesson,$cid,$score,$score>=7?1:0,gmdate('Y-m-d H:i:s',1577836800+$i));
                }
                if(false===$wpdb->query("INSERT INTO {$table} (user_id,lesson_id,content_id,raw_score,max_score,passed,completed,created_at) VALUES ".implode(',',$values)))throw new RuntimeException($wpdb->last_error);
            }
            wp_set_current_user(1);
            $admin=$measure(static fn()=>FKHS_Report_Query::get_page(array('per_page'=>50)));
            wp_set_current_user($teacher);
            $scoped=$measure(static fn()=>FKHS_Report_Query::get_page(array('per_page'=>50)));
            $progress=$measure(static fn()=>FKHS_Progress::get_items($student,$lesson));
            WP_CLI::line(wp_json_encode(array('attempts'=>$attempts,'activities'=>$activities,'admin'=>$admin,'teacher'=>$scoped,'progress'=>$progress)));
            if($attempts===100000 && $activities===50) {
                foreach($captured as $name=>$sql) {
                    WP_CLI::line('EXPLAIN '.$name.' '.wp_json_encode($wpdb->get_results('EXPLAIN '.$sql,ARRAY_A)));
                }
                // Compare candidate indexes only inside this disposable database.
                $indexes=$wpdb->get_col("SHOW INDEX FROM {$table}",2);
                if(!in_array('lesson_created',$indexes,true)) {
                    $wpdb->query("ALTER TABLE {$table} ADD KEY lesson_created (lesson_id, created_at, id)");
                    WP_CLI::line('CANDIDATE lesson_created '.wp_json_encode($measure(static fn()=>FKHS_Report_Query::get_page(array('per_page'=>50)))));
                    WP_CLI::line('EXPLAIN candidate-report '.wp_json_encode($wpdb->get_results('EXPLAIN '.$captured['report'],ARRAY_A)));
                }
                if(!in_array('progress_latest',$indexes,true)) {
                    $wpdb->query("ALTER TABLE {$table} ADD KEY progress_latest (user_id, lesson_id, content_id, created_at, id)");
                    WP_CLI::line('CANDIDATE progress_latest '.wp_json_encode($measure(static fn()=>FKHS_Progress::get_items($student,$lesson))));
                    WP_CLI::line('EXPLAIN candidate-latest '.wp_json_encode($wpdb->get_results('EXPLAIN '.$captured['latest'],ARRAY_A)));
                }
            }
        }
    }
} finally {
    remove_filter('query',$capture);
    $wpdb->delete($table,array('lesson_id'=>$lesson));
    wp_set_current_user(1);
}
WP_CLI::success('Report/progress benchmark complete; synthetic attempt rows removed.');
