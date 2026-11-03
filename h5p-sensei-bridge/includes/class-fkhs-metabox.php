<?php
/** Lesson-wide threshold overview; Gutenberg shares native editor meta state. */
if (!defined('ABSPATH')) { exit; }
class FKHS_Metabox {
    public static function init() {
        add_action('add_meta_boxes',array(__CLASS__,'add'));
        add_action('save_post_lesson',array(__CLASS__,'save'));
        add_action('admin_notices',array(__CLASS__,'notice'));
    }
    public static function add() {
        add_meta_box('fkhs-lesson-settings',__('H5P → Sensei Bridge','h5p-sensei-bridge'),array(__CLASS__,'render'),'lesson','side','default');
    }
    public static function render(WP_Post $post) {
        wp_nonce_field('fkhs_lesson_meta','fkhs_lesson_meta_nonce');
        $screen=get_current_screen();
        if($screen && $screen->is_block_editor()){
            echo '<input type="hidden" name="fkhs_editor_managed" value="1" /><div id="fkhs-editor-overview">'.esc_html__('Loading activity thresholds…','h5p-sensei-bridge').'</div>';
            return;
        }
        $map=get_post_meta($post->ID,FKHS_Editor::META,true);$map=is_array($map)?$map:array();
        echo '<input type="hidden" name="fkhs_threshold_snapshot" value="'.esc_attr(FKHS_Editor::map_token($map)).'" />';
        echo '<p>'.esc_html__('Leave blank to inherit. A threshold of 0 is optional before an attempt, then requires an actual completion signal.','h5p-sensei-bridge').'</p>';
        $ids=FKHS_Sensei::get_h5p_ids_for_lesson($post->ID);
        if(!$ids){echo '<p>'.esc_html__('No H5P items detected in this lesson yet.','h5p-sensei-bridge').'</p>';return;}
        // Explicit marker allows clearing all visible overrides; omitted form data never deletes a map.
        echo '<input type="hidden" name="fkhs_threshold_form" value="1" />';
        $details=self::activity_details($ids);
        foreach($ids as $id){
            $input='fkhs-threshold-'.$id;
            if(isset($details[$id])){
                $item=$details[$id];
                echo '<p><strong>'.esc_html($item['title']).'</strong><br /><small>'.esc_html($item['type']).'</small></p>';
            }
            echo '<p><label for="'.esc_attr($input).'">'.esc_html(sprintf(__('H5P #%d — threshold (%%)','h5p-sensei-bridge'),$id)).'</label><br />';
            echo '<input id="'.esc_attr($input).'" type="number" min="0" max="100" step="any" name="fkhs_threshold_map['.esc_attr($id).']" value="'.esc_attr($map[$id]??'').'" placeholder="'.esc_attr(FKHS_Thresholds::inherited($post->ID,$id)).'" /></p>';
        }
        echo '<p>'.esc_html__('Inherit uses the legacy activity value, then the lesson value, then 70.','h5p-sensei-bridge').'</p>';
    }
    private static function activity_details(array $ids): array {
        if(!current_user_can('view_h5p_contents') || !class_exists('H5P_Plugin')){return array();}
        global $wpdb;
        $previous=$wpdb->suppress_errors(true);
        try {
            $scope=current_user_can('view_others_h5p_contents')?'':$wpdb->prepare(' AND c.user_id=%d',get_current_user_id());
            $placeholders=implode(',',array_fill(0,count($ids),'%d'));
            $rows=$wpdb->get_results($wpdb->prepare("SELECT c.id,c.title,l.name,l.major_version,l.minor_version FROM {$wpdb->prefix}h5p_contents c LEFT JOIN {$wpdb->prefix}h5p_libraries l ON c.library_id=l.id WHERE c.id IN ($placeholders)".$scope,...$ids),ARRAY_A);
            if($wpdb->last_error){return array();}
            $result=array();
            foreach($rows as $row){$result[(int)$row['id']]=array('title'=>$row['title'],'type'=>$row['name']?sprintf('%s %d.%d',$row['name'],$row['major_version'],$row['minor_version']):'');}
            return $result;
        }catch(Throwable $error){return array();}finally{$wpdb->suppress_errors($previous);}
    }
    public static function save($post_id) {
        if(!isset($_POST['fkhs_lesson_meta_nonce']) || !wp_verify_nonce($_POST['fkhs_lesson_meta_nonce'],'fkhs_lesson_meta')){return;}
        if((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || (defined('REST_REQUEST') && REST_REQUEST) || !empty($_POST['fkhs_editor_managed']) || !current_user_can('edit_post',$post_id)){return;}
        if(!isset($_POST['fkhs_threshold_form'],$_POST['fkhs_threshold_map']) || !is_array($_POST['fkhs_threshold_map'])){return;}
        $previous=get_post_meta($post_id,FKHS_Editor::META,true);$previous=is_array($previous)?$previous:array();
        if(!isset($_POST['fkhs_threshold_snapshot']) || !hash_equals(FKHS_Editor::map_token($previous),(string)$_POST['fkhs_threshold_snapshot'])){return self::reject();}
        $map=$previous;
        foreach(wp_unslash($_POST['fkhs_threshold_map']) as $id=>$value){
            if($value===''){unset($map[$id]);}else{$map[$id]=$value;}
        }
        $valid=FKHS_Editor::validate_map($map,(string)get_post_field('post_content',$post_id),$previous);
        if(is_wp_error($valid)){return self::reject();}
        update_post_meta($post_id,FKHS_Editor::META,$valid);
    }
    private static function reject() {
        add_filter('redirect_post_location',static function($url){return add_query_arg('fkhs_threshold_error','1',$url);});
    }
    public static function notice() {
        if(!empty($_GET['fkhs_threshold_error'])){
            echo '<div class="notice notice-error"><p>'.esc_html__('The lesson was saved, but its thresholds were not changed. Reload and check activity access, numeric values and concurrent edits.','h5p-sensei-bridge').'</p></div>';
        }
    }
}
