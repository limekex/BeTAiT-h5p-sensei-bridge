<?php
/** Lesson authoring; WordPress owns saves, autosaves and revision restoration. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FKHS_Editor {
    const META = '_fkhs_threshold_map';

    public static function init(): void {
        add_action('init',array(__CLASS__,'register'),30);
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('enqueue_block_editor_assets',array(__CLASS__,'enqueue'));
        add_filter('rest_pre_insert_lesson',array(__CLASS__,'validate_save'),10,2);
        add_filter('rest_request_before_callbacks',array(__CLASS__,'validate_autosave'),10,3);
        add_filter('rest_request_after_callbacks',array(__CLASS__,'verify_autosave'),10,3);
    }

    public static function register(): void {
        if (!post_type_exists('lesson')) { return; }
        add_post_type_support('lesson','custom-fields');
        register_post_meta('lesson',self::META,array(
            'single'=>true,'type'=>'object','default'=>(object)array(),'revisions_enabled'=>true,
            'auth_callback'=>static function($allowed,$key,$id){return current_user_can('edit_post',$id);},
            'show_in_rest'=>array(
                'schema'=>array('type'=>'object','context'=>array('edit'),'additionalProperties'=>array('type'=>'number','minimum'=>0,'maximum'=>100)),
                'prepare_callback'=>static function($value){return (object)(is_array($value)?$value:array());},
            ),
        ));
        register_block_type('fkhs/activity',array('api_version'=>3,'attributes'=>array('contentId'=>array('type'=>'integer','default'=>0)), 'supports'=>array('html'=>false,'reusable'=>false)));
    }

    public static function enqueue(): void {
        $screen=get_current_screen();
        if (!$screen || $screen->post_type!=='lesson') { return; }
        $path=FKHS_DIR.'assets/js/h5p-sensei-editor.js';
        wp_enqueue_script('fkhs-editor',FKHS_URL.'assets/js/h5p-sensei-editor.js',array('wp-blocks','wp-block-editor','wp-element','wp-components','wp-data','wp-editor','wp-api-fetch','wp-i18n','wp-dom-ready','shortcode'),FKHS_VER.'-'.substr(hash_file('sha256',$path),0,12),true);
        wp_set_script_translations('fkhs-editor','h5p-sensei-bridge',FKHS_DIR.'languages');
        // Fallback keeps the editor localized without requiring a JS build toolchain.
        $labels=array(
            'Activity'=>__( 'H5P activity', 'h5p-sensei-bridge' ),
            'Search'=>__( 'Search activities by title or ID', 'h5p-sensei-bridge' ),
            'Select'=>__( 'Select activity', 'h5p-sensei-bridge' ),
            'Replace'=>__( 'Replace activity', 'h5p-sensei-bridge' ),
            'Next'=>__( 'Next page', 'h5p-sensei-bridge' ),
            'Previous'=>__( 'Previous page', 'h5p-sensei-bridge' ),
            'Loading'=>__( 'Loading activities…', 'h5p-sensei-bridge' ),
            'Empty'=>__( 'No activities found.', 'h5p-sensei-bridge' ),
            'Retry'=>__( 'Retry', 'h5p-sensei-bridge' ),
            'Error'=>__( 'Could not load activities. Check access and try again.', 'h5p-sensei-bridge' ),
            'Inherit'=>__( 'Inherit threshold', 'h5p-sensei-bridge' ),
            'Threshold'=>__( 'Threshold (%)', 'h5p-sensei-bridge' ),
            'Overview'=>__( 'Lesson activity thresholds', 'h5p-sensei-bridge' ),
            'None'=>__( 'Insert an activity or an H5P shortcode to set its threshold.', 'h5p-sensei-bridge' ),
            'Shared'=>__( 'Repeated uses of an activity share one threshold in this lesson.', 'h5p-sensei-bridge' ),
            'Zero'=>__( 'A threshold of 0 is optional before an attempt, then requires an actual completion signal.', 'h5p-sensei-bridge' ),
            'Inherited'=>__( 'Inherited value', 'h5p-sensei-bridge' ),
            'Effective'=>__( 'Effective preview for your account', 'h5p-sensei-bridge' ),
            'Filters'=>__( 'Runtime filters may give learners a different effective threshold.', 'h5p-sensei-bridge' ),
            'Unavailable'=>__( 'Activity unavailable or you do not have access. Existing content is preserved.', 'h5p-sensei-bridge' ),
            'Invalid'=>__( 'Enter a number from 0 to 100.', 'h5p-sensei-bridge' ),
            'OnlyLesson'=>__( 'This block is available only in Sensei lessons.', 'h5p-sensei-bridge' ),
            'Legacy'=>__( 'Inherit uses the legacy activity value, then the lesson value, then 70.', 'h5p-sensei-bridge' ),
            'Selected'=>__( 'Selected activity', 'h5p-sensei-bridge' ),
        );
        wp_localize_script('fkhs-editor','fkhsEditor',array('labels'=>$labels));
    }

    public static function routes(): void {
        register_rest_route('fkhs/v1','/activities',array('methods'=>'GET','permission_callback'=>array(__CLASS__,'permission'),'callback'=>array(__CLASS__,'activities'),'args'=>array(
            'lesson_id'=>array('type'=>'integer','minimum'=>1,'required'=>true),
            'search'=>array('type'=>'string','maxLength'=>100,'default'=>''),
            'page'=>array('type'=>'integer','minimum'=>1,'maximum'=>1000,'default'=>1),
            'content_id'=>array('type'=>'integer','minimum'=>1),
            'threshold'=>array('type'=>'number','minimum'=>0,'maximum'=>100),
        )));
    }

    public static function permission($request) {
        $lesson=(int)$request['lesson_id'];
        if (get_post_type($lesson)!=='lesson' || !current_user_can('edit_post',$lesson) || !current_user_can('view_h5p_contents')) {
            return new WP_Error('fkhs_editor_forbidden',__('You cannot select H5P activities for this lesson.','h5p-sensei-bridge'),array('status'=>403));
        }
        if (!class_exists('H5P_Plugin') || !shortcode_exists('h5p')) {
            return new WP_Error('fkhs_editor_unavailable',__('H5P is unavailable. Activate it before selecting activities.','h5p-sensei-bridge'),array('status'=>503));
        }
        return true;
    }

    /** Mirrors H5P's own insert picker: view_h5p_contents + owner scope unless view_others. */
    public static function activity_allowed(int $id): bool {
        if (!current_user_can('view_h5p_contents') || !class_exists('H5P_Plugin') || !shortcode_exists('h5p')) { return false; }
        global $wpdb;
        $previous=$wpdb->suppress_errors(true);
        try {
            $scope=current_user_can('view_others_h5p_contents')?'':$wpdb->prepare(' AND user_id=%d',get_current_user_id());
            return (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}h5p_contents WHERE id=%d".$scope,$id))===$id && !$wpdb->last_error;
        }catch(Throwable $error){return false;}finally{$wpdb->suppress_errors($previous);}
    }

    public static function activities($request) {
        global $wpdb;
        $previous=$wpdb->suppress_errors(true);
        try {
            $where=current_user_can('view_others_h5p_contents')?'1=1':$wpdb->prepare('c.user_id=%d',get_current_user_id());
            $id=(int)$request['content_id'];$search=(string)$request['search'];$page=(int)$request['page'];
            if($id){$where.=$wpdb->prepare(' AND c.id=%d',$id);}
            elseif($search!==''){$where.=$wpdb->prepare(' AND (c.title LIKE %s OR c.id=%d)','%'.$wpdb->esc_like($search).'%',ctype_digit($search)?(int)$search:0);}
            $rows=$wpdb->get_results($wpdb->prepare("SELECT c.id,c.title,l.name,l.major_version,l.minor_version FROM {$wpdb->prefix}h5p_contents c LEFT JOIN {$wpdb->prefix}h5p_libraries l ON c.library_id=l.id WHERE {$where} ORDER BY c.title ASC,c.id ASC LIMIT 21 OFFSET %d",($page-1)*20),ARRAY_A);
            if($wpdb->last_error){throw new RuntimeException('Registry read failed');}
            $more=count($rows)>20;$items=array();
            foreach(array_slice($rows,0,20) as $row){
                $item=array('id'=>(int)$row['id'],'title'=>$row['title'],'type'=>$row['name']?sprintf('%s %d.%d',$row['name'],$row['major_version'],$row['minor_version']):'');
                if($id){$item['inherited']=FKHS_Thresholds::inherited((int)$request['lesson_id'],$id);$item['effective']=FKHS_Thresholds::preview((int)$request['lesson_id'],$id,get_current_user_id(),$request->has_param('threshold')?(float)$request['threshold']:null);}
                $items[]=$item;
            }
            $response=new WP_REST_Response(array('items'=>$items,'has_more'=>$more,'page'=>$page));
            $response->header('Cache-Control','private, no-store');return $response;
        }catch(Throwable $error){return new WP_Error('fkhs_editor_storage',__('Activity lookup failed. Try again.','h5p-sensei-bridge'),array('status'=>503));}
        finally{$wpdb->suppress_errors($previous);}
    }

    public static function map_token($map): string {
        $map=is_array($map)?$map:array();ksort($map,SORT_NUMERIC);
        return hash('sha256',wp_json_encode($map));
    }

    /** Validate edits, while retaining unchanged overrides for removed/legacy content. */
    public static function validate_map($map,string $content,$previous) {
        if(!is_array($map) && !is_object($map)){return self::invalid();}
        $map=(array)$map;$previous=is_array($previous)?$previous:array();
        if(count($map)>500){return self::invalid();}
        $ids=FKHS_Sensei::get_h5p_ids_from_content($content);$clean=array();
        foreach($map as $id=>$value){
            if(!preg_match('/^[1-9][0-9]*$/D',(string)$id) || false===filter_var($id,FILTER_VALIDATE_INT) || !is_numeric($value) || !is_finite((float)$value) || $value<0 || $value>100){return self::invalid();}
            if(!array_key_exists($id,$previous) || (float)$previous[$id] !== (float)$value){
                if(!in_array((int)$id,$ids,true) || !self::activity_allowed((int)$id)){return self::invalid();}
            }
            $clean[(int)$id]=(float)$value;
        }
        return $clean;
    }

    private static function invalid(): WP_Error {
        return new WP_Error('fkhs_editor_invalid',__('Thresholds must be 0–100 and belong to available activities in this lesson. Reload if the lesson changed.','h5p-sensei-bridge'),array('status'=>400));
    }

    /** Core autosaves do not consistently propagate prepare_item WP_Error results. */
    public static function validate_autosave($response,$handler,$request) {
        if($response!==null || $request->get_method()!=='POST' || !preg_match('#^/wp/v2/lessons/[1-9][0-9]*/autosaves$#D',$request->get_route()) || !current_user_can('edit_post',(int)$request['id'])){return $response;}
        $prepared=new stdClass();
        if(is_string($request['content'])){$prepared->post_content=$request['content'];}
        $valid=self::validate_save($prepared,$request);
        return is_wp_error($valid)?$valid:$response;
    }

    /** Core's direct draft/revision meta writes do not propagate storage errors. */
    public static function verify_autosave($response,$handler,$request) {
        if(is_wp_error($response) || $request->get_method()!=='POST' || !preg_match('#^/wp/v2/lessons/[1-9][0-9]*/autosaves$#D',$request->get_route())){return $response;}
        $meta=$request->get_param('meta');
        if(!is_array($meta) || !array_key_exists(self::META,$meta)){return $response;}
        $result=rest_ensure_response($response);
        if($result->get_status()>=400){return $response;}
        $data=$result->get_data();
        $stored=isset($data['id'])?get_post_meta((int)$data['id'],self::META,true):null;
        if(!isset($data['id']) || (array)$stored!=(array)$meta[self::META]){
            return new WP_Error('fkhs_editor_autosave',__('Could not save activity thresholds. Try saving the lesson again.','h5p-sensei-bridge'),array('status'=>500));
        }
        return $response;
    }

    public static function validate_save($prepared,$request) {
        if(is_wp_error($prepared)){return $prepared;}
        $id=(int)$request['id'];
        $content=isset($prepared->post_content)?$prepared->post_content:(string)get_post_field('post_content',$id);
        $previous=get_post_meta($id,self::META,true);
        $meta=$request->get_param('meta');
        if(is_array($meta) && array_key_exists(self::META,$meta)){
            $valid=self::validate_map($meta[self::META],$content,$previous);
            if(is_wp_error($valid)){return $valid;}
            // Core writes an unlocked author's draft directly via wp_update_post,
            // bypassing REST meta persistence. Keep its content and map together.
            if(preg_match('#/autosaves$#D',$request->get_route())){$prepared->meta_input=array(self::META=>$valid);}
        }
        $old_ids=$id?FKHS_Sensei::get_h5p_ids_for_lesson($id):array();
        $valid=self::validate_blocks(parse_blocks($content),$old_ids);
        return is_wp_error($valid)?$valid:$prepared;
    }

    private static function validate_blocks(array $blocks,array $old_ids,int $depth=0) {
        if($depth>50){return self::invalid();}
        foreach($blocks as $block){
            if($block['blockName']==='fkhs/activity'){
                $id=$block['attrs']['contentId']??0;
                if($id===0 && trim($block['innerHTML'])===''){continue;}
                if(!is_int($id) || $id<1 || trim($block['innerHTML'])!=='[h5p id="'.$id.'"]'){return self::invalid();}
                if(!in_array($id,$old_ids,true) && !self::activity_allowed($id)){return self::invalid();}
            }
            if($block['blockName']==='core/block' && !empty($block['attrs']['ref'])){
                if(self::reference_has_activity((int)$block['attrs']['ref'])){return new WP_Error('fkhs_editor_pattern',__('Detach the synced pattern before using H5P activity blocks in a lesson.','h5p-sensei-bridge'),array('status'=>400));}
            }
            $valid=self::validate_blocks($block['innerBlocks']??array(),$old_ids,$depth+1);
            if(is_wp_error($valid)){return $valid;}
        }
        return true;
    }

    /** References hide IDs from stored lesson content, including nested patterns. */
    private static function reference_has_activity(int $id,array &$seen=array(),int $depth=0): bool {
        if(isset($seen[$id])){return false;}
        if($depth>50){return true;}
        $seen[$id]=true;
        $content=(string)get_post_field('post_content',$id);
        if(strpos($content,'wp:fkhs/activity')!==false){return true;}
        $pending=parse_blocks($content);
        while($pending){
            $block=array_pop($pending);
            if($block['blockName']==='core/block' && !empty($block['attrs']['ref']) && self::reference_has_activity((int)$block['attrs']['ref'],$seen,$depth+1)){return true;}
            foreach($block['innerBlocks']??array() as $child){$pending[]=$child;}
        }
        return false;
    }
}
