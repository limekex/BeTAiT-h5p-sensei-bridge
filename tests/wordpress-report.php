<?php
/** Real REST/SQL/permissions checks. Never run against production or local shared staging. */
if ( ! defined('FKHS_INTEGRATION_TESTS') || true !== FKHS_INTEGRATION_TESTS || ! defined('WP_CLI') || ! WP_CLI ) { throw new RuntimeException('Disposable integration environment required.'); }
global $wpdb, $report_checks;
$report_checks = 0;
function report_check($ok,$label) { global $report_checks; if (!$ok) throw new RuntimeException($label); ++$report_checks; }
function report_request($params=array()) {
    $request = new WP_REST_Request('GET','/fkhs/v1/report'); $request->set_query_params($params);
    return rest_get_server()->dispatch($request);
}
function report_ids($response) {
    preg_match_all('/data-attempt-id="(\d+)"/', $response->get_data()['html'] ?? '', $matches); return array_map('intval',$matches[1]);
}
$teacher=wp_insert_user(array('user_login'=>'report-teacher','role'=>'teacher'));
$other=wp_insert_user(array('user_login'=>'report-other','role'=>'teacher'));
$student=wp_insert_user(array('user_login'=>'report-student','role'=>'subscriber'));
$empty=wp_insert_user(array('user_login'=>'report-empty','role'=>'teacher'));
$course=wp_insert_post(array('post_type'=>'course','post_status'=>'publish','post_title'=>'Report course','post_author'=>$teacher));
$foreign=wp_insert_post(array('post_type'=>'course','post_status'=>'publish','post_title'=>'Other report course','post_author'=>$other));
$lesson=wp_insert_post(array('post_type'=>'lesson','post_status'=>'publish','post_title'=>'Report current lesson','post_author'=>$teacher));
$foreign_lesson=wp_insert_post(array('post_type'=>'lesson','post_status'=>'publish','post_title'=>'Foreign report lesson','post_author'=>$other));
update_post_meta($lesson,'_lesson_course',$course);update_post_meta($foreign_lesson,'_lesson_course',$foreign);
$wpdb->insert($wpdb->prefix.'h5p_contents',array('title'=>'Report <script>alert(1)</script>','parameters'=>'{}','filtered'=>'{}','slug'=>'report-test','library_id'=>0,'user_id'=>$teacher,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
$cid=(int)$wpdb->insert_id;
update_post_meta($lesson,'_fkhs_threshold_map',array($cid=>0));
$table=$wpdb->prefix.FKHS_TABLE;
$ids=array();
for($i=0;$i<251;$i++) {
    report_check($wpdb->insert($table,array('user_id'=>$student,'lesson_id'=>$lesson,'lesson_title'=>$i===0?'Ancient <img src=x onerror=alert(1)>':'Report snapshot','content_id'=>$cid,'raw_score'=>$i===0?null:8,'max_score'=>$i===0?null:10,'passed'=>$i===0?0:1,'completed'=>1,'statement'=>'PRIVATE-XAPI-SENTINEL','created_at'=>$i===0?'2020-01-01 00:00:00':'2026-11-03 12:00:00'))===1,'Insert report fixture');
    $ids[]=(int)$wpdb->insert_id;
}
$wpdb->insert($table,array('user_id'=>$student,'lesson_id'=>$foreign_lesson,'content_id'=>$cid,'created_at'=>'2026-11-03 12:00:00'));
$foreign_id=(int)$wpdb->insert_id;
foreach(array(0=>$student,1=>0) as $unused=>$viewer) {
    wp_set_current_user($viewer);$r=report_request();report_check(in_array($r->get_status(),array(401,403),true),'Student/guest denied');
    report_check(!isset($r->get_data()['total']) && !isset($r->get_data()['html']),'No counts or rows on denial');
}
wp_set_current_user($teacher);
$r=report_request(array('per_page'=>200));report_check($r->get_status()===200, 'Teacher read: '.wp_json_encode($r->get_data()));
report_check($r->get_data()['total']===251 && count(report_ids($r))===200,'Full scoped total and bounded page');
report_check(report_ids($r)[0]===$ids[250],'Ties ordered by ID');
report_check(!in_array($foreign_id,report_ids($r),true),'Foreign lesson excluded');
report_check(!str_contains($r->get_data()['html'],'PRIVATE-XAPI-SENTINEL'),'No raw payload');
report_check(str_contains($r->get_headers()['Cache-Control'],'no-store'),'No-store');
$second=report_request(array('per_page'=>200,'page'=>2));report_check(count(report_ids($second))===51 && in_array($ids[0],report_ids($second),true),'Page two reaches beyond old 200 cap');
report_check(!array_intersect(report_ids($r),report_ids($second)),'No overlaps for equal dates');
$r=report_request(array('lesson'=>'Ancient'));report_check(report_ids($r)===array($ids[0]),'Search finds older than 200');
report_check(str_contains($r->get_data()['html'],'&lt;img') && !str_contains($r->get_data()['html'],'<img'),'Lesson snapshot escaped');
report_check(!str_contains($r->get_data()['html'],'<script>'),'H5P title escaped');
report_check(str_contains($r->get_data()['html'],'data-criterion="0"'),'Effective zero criterion retained');
report_check(report_request(array('lesson'=>"%' OR 1=1 --"))->get_data()['total']===0,'LIKE escaped and SQL parameterized');
report_check(report_request(array('lesson'=>'%'))->get_data()['total']===0,'Percent is literal');
report_check(report_request(array('user'=>'report-student'))->get_data()['total']===251,'User filter');
report_check(report_request(array('dateFrom'=>'2020-01-01','dateTo'=>'2020-01-01'))->get_data()['total']===1,'Inclusive stored dates');
report_check(report_request(array('scoreCmp'=>'le','scoreVal'=>79))->get_data()['total']===0,'Unscored attempt excluded from percentage comparison');
report_check(report_request(array('scoreCmp'=>'ge','scoreVal'=>80))->get_data()['total']===250,'Percentage comparison includes boundary');
report_check(report_request(array('passed'=>'no'))->get_data()['total']===1,'Stored pass filter');
report_check(report_request(array('completed'=>'no'))->get_data()['total']===0,'Stored completion filter');
report_check(report_request(array('content_id'=>$cid))->get_data()['total']===251,'Content filter retains teacher scope');
report_check(report_request(array('page'=>999))->get_data()['page']===6,'Out-of-range page clamps');
foreach(array(array('per_page'=>201),array('page'=>0),array('user'=>array('x')),array('sort'=>'criterion'),array('sort'=>'date; DROP TABLE'),array('direction'=>'ASC;'),array('dateFrom'=>'2026-02-30'),array('dateFrom'=>'2026-11-04','dateTo'=>'2026-11-03'),array('scoreVal'=>'nan'),array('scoreVal'=>101),array('scoreCmp'=>'ge'),array('passed'=>'false'),array('critCmp'=>'ge','critVal'=>70)) as $bad) {
    report_check(report_request($bad)->get_status()===400,'Invalid input rejected: '.wp_json_encode($bad));
}
foreach(array('date','user','lesson','score','passed','completed') as $sort) {
    foreach(array('asc','desc') as $direction) report_check(report_request(array('sort'=>$sort,'direction'=>$direction))->get_status()===200,'Allowlisted sort');
}
wp_set_current_user($empty);report_check(report_request()->get_data()['total']===0,'Teacher without courses has zero counts');
wp_set_current_user($other);report_check(report_ids(report_request())===array($foreign_id),'Other teacher sees own course only');
wp_set_current_user(1);report_check(report_request(array('content_id'=>$cid))->get_data()['total']===252,'Admin sees both courses');
// Membership follows course ownership, including reassignment, on every request.
wp_update_post(array('ID'=>$course,'post_author'=>$other));wp_set_current_user($teacher);
report_check(report_request()->get_data()['total']===0,'Course reassignment revokes report reads');
wp_update_post(array('ID'=>$course,'post_author'=>$teacher));
wp_trash_post($course);report_check(report_request()->get_data()['total']===0,'Trashed course excluded');wp_untrash_post($course);
// Batch query count must not grow with page length (prewarm shared WP caches).
wp_set_current_user(1);FKHS_Report_Query::get_page(array('content_id'=>$cid));
$before=$wpdb->num_queries;FKHS_Report_Query::get_page(array('content_id'=>$cid,'per_page'=>10));$small=$wpdb->num_queries-$before;
$before=$wpdb->num_queries;FKHS_Report_Query::get_page(array('content_id'=>$cid,'per_page'=>200));$large=$wpdb->num_queries-$before;
report_check($large <= $small+1 && $large <= 6,'Metadata reads bounded: '.$small.'/'.$large);
$fail=static function($sql) use($table){return stripos($sql,'SELECT')===0 && str_contains($sql,$table)?'SELECT * FROM fkhs_missing_report_table':$sql;};
add_filter('query',$fail);$r=report_request();remove_filter('query',$fail);
report_check($r->get_status()===503 && !isset($r->get_data()['total']),'Storage failure does not fabricate empty report');
// A throwing custom threshold filter must not leak partially rendered output.
$cache=new ReflectionProperty('FKHS_Thresholds','cache');
if(PHP_VERSION_ID<80100)$cache->setAccessible(true);
$cache->setValue(null,array());
$throw=static function(){throw new RuntimeException('PRIVATE-FILTER-DETAIL');};
add_filter('fkhs_threshold_for_item',$throw);
$level=ob_get_level();$r=report_request(array('content_id'=>$cid));
remove_filter('fkhs_threshold_for_item',$throw);
report_check($r->get_status()===503 && ob_get_level()===$level,'Custom filter failure closes output buffer');
report_check(!str_contains(wp_json_encode($r->get_data()),'PRIVATE-FILTER-DETAIL'),'Failure details remain private');
// Metadata batching preserves activity identity/title and the empty deleted-content fallback.
wp_update_post(array('ID'=>$lesson,'post_content'=>'[h5p id="'.$cid.'"] [h5p id="999999"]'));
$metadata_queries=0;
$count_metadata=static function($sql) use(&$metadata_queries){if(str_contains($sql,'SELECT c.id, c.title, l.name'))++$metadata_queries;return $sql;};
add_filter('query',$count_metadata);$items=FKHS_Progress::get_items($student,$lesson);remove_filter('query',$count_metadata);
report_check($metadata_queries===1 && count($items)===2,'One metadata query for multiple activities');
report_check($items[0]['title']==='Report <script>alert(1)</script>' && $items[1]['title']==='','Batched metadata is matched by content ID');
// Deleted H5P registry entries remain auditable by ID; no attempts are removed.
$wpdb->delete($wpdb->prefix.'h5p_contents',array('id'=>$cid));
$r=report_request(array('content_id'=>$cid));
report_check($r->get_status()===200 && $r->get_data()['total']===252 && str_contains($r->get_data()['html'],'#'.$cid),'Missing H5P metadata preserves report rows');
// Cookie/nonce checks through the actual REST serving path.
$serve=static function($nonce) use($teacher) {
    wp_set_current_user($teacher);$GLOBALS['wp_rest_auth_cookie']=true;$_SERVER['REQUEST_METHOD']='GET';$_GET=$_REQUEST=array();
    if($nonce===null)unset($_SERVER['HTTP_X_WP_NONCE']);else $_SERVER['HTTP_X_WP_NONCE']=$nonce;
    ob_start();rest_get_server()->serve_request('/fkhs/v1/report');$body=ob_get_clean();return json_decode($body,true);
};
wp_set_current_user($teacher);$nonce=wp_create_nonce('wp_rest');
report_check(isset($serve($nonce)['total']),'Authenticated cookie nonce');
report_check(($serve('bad')['code']??'')==='rest_cookie_invalid_nonce','Invalid nonce rejected');
report_check(!isset($serve(null)['total']),'Missing nonce has no report data');
unset($_SERVER['HTTP_X_WP_NONCE']);$_GET=$_REQUEST=array();wp_set_current_user(1);
WP_CLI::success("$report_checks report assertions passed; warm query counts $small/$large for 10/200 rows.");
