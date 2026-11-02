<?php
/** Test content using the block schema shipped with the pinned Sensei version. */
function fkhs_fixture_block($name, $attrs = array(), $children = array(), $html = '') {
    if (!WP_Block_Type_Registry::get_instance()->is_registered($name)) throw new RuntimeException('Missing fixture block: '.$name);
    return array('blockName'=>$name,'attrs'=>$attrs,'innerBlocks'=>$children,'innerHTML'=>$html,'innerContent'=>$children ? array_fill(0,count($children),null) : ($html === '' ? array() : array($html)));
}
function fkhs_fixture_block_quiz($lesson, $cid) {
    $questions = array(); $descriptions = array();
    foreach (array('Dette er første testspørsmål.','Dette er andre testspørsmål.') as $index=>$title) {
        $descriptions[] = array(
            fkhs_fixture_block('sensei-lms/question-description',array(),array(fkhs_fixture_block('core/paragraph',array(),array(),'<p>Kontroll '.($index+1).' av blokkbasert quiz.</p>'))),
            fkhs_fixture_block('sensei-lms/question-answers'),
        );
        $questions[] = array('title'=>$title,'type'=>'boolean','answer'=>array('correct'=>true),'options'=>array('grade'=>1),'description'=>serialize_blocks($descriptions[$index]));
    }
    $request = new WP_REST_Request('POST','/sensei-internal/v1/lesson-quiz/'.$lesson);
    $request->set_header('Content-Type','application/json');
    $request->set_body(wp_json_encode(array('options'=>array('pass_required'=>true,'quiz_passmark'=>100,'auto_grade'=>true,'allow_retakes'=>true,'pagination'=>array('pagination_number'=>1,'show_progress_bar'=>true)),'questions'=>$questions)));
    $response = rest_get_server()->dispatch($request);
    if (200 !== $response->get_status()) throw new RuntimeException('Quiz editor API: '.wp_json_encode($response->get_data()));
    $data = $response->get_data();
    $quiz = (int)Sensei()->lesson->lesson_quizzes($lesson);
    $ids = array_map('intval',array_column($data['questions'],'id'));
    if (!$quiz || count($ids)!==2) throw new RuntimeException('Quiz IDs missing from editor response.');
    $children = array();
    foreach ($data['questions'] as $index=>$q) {
        $children[] = fkhs_fixture_block('sensei-lms/quiz-question',array('id'=>(int)$q['id'],'title'=>$q['title'],'type'=>'boolean','answer'=>array('correct'=>true),'options'=>array('grade'=>1)), $descriptions[$index]);
    }
    $content = serialize_blocks(array(
        fkhs_fixture_block('core/shortcode',array(),array(),'[h5p id="'.$cid.'"]'),
        fkhs_fixture_block('sensei-lms/quiz',array('id'=>$quiz,'options'=>array('passRequired'=>true,'quizPassmark'=>100,'autoGrade'=>true,'allowRetakes'=>true,'pagination'=>array('pagination_number'=>1,'show_progress_bar'=>true))),$children),
        fkhs_fixture_block('sensei-lms/lesson-actions'),
    ));
    $saved = wp_update_post(wp_slash(array('ID'=>$lesson,'post_content'=>$content)),true);
    if (is_wp_error($saved)) throw new RuntimeException($saved->get_error_message());
    return array('lesson'=>$lesson,'quiz'=>$quiz,'questions'=>$ids,'content'=>$content);
}
