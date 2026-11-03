<?php
/** Real textdomain loading and localized script payloads, without changing site options. */
if (!defined('FKHS_INTEGRATION_TESTS') || true !== FKHS_INTEGRATION_TESTS || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Disposable integration environment required.');
}
wp_set_current_user(1);
$lesson = wp_insert_post(array('post_type'=>'lesson','post_status'=>'publish','post_title'=>'Locale fixture'));
global $wp_query, $post, $wp_scripts;
$wp_query = new WP_Query(array('post_type'=>'lesson','p'=>$lesson));
$post = get_post($lesson);
$checks = 0;
foreach (array('en_US','nb_NO') as $locale) {
    $locale_filter = static function () use ($locale) { return $locale; };
    add_filter('locale',$locale_filter,99);
    add_filter('determine_locale',$locale_filter,99);
    unload_textdomain('h5p-sensei-bridge',true);
    $wp_scripts = new WP_Scripts();
    FKHS_Plugin::enqueue_front();
    FKHS_Reporter::enqueue_admin('toplevel_page_fkhs-report');
    foreach (array('fkhs-h5p-bridge','fkhs-admin-report') as $handle) {
        $script = $wp_scripts->registered[$handle] ?? null;
        if (!$script || $script->textdomain !== 'h5p-sensei-bridge' || $script->translations_path !== FKHS_DIR.'languages' || !in_array('wp-i18n',$script->deps,true)) {
            throw new RuntimeException('Wrong translation handle/dependencies/path: '.$handle);
        }
        ++$checks;
        $data = $wp_scripts->get_data($handle,'data');
        $prefix = $handle === 'fkhs-h5p-bridge' ? 'var fkH5P = ' : 'var fkhsAdmin = ';
        $payload = json_decode(substr($data,strlen($prefix),-1),true);
        $expected = $locale === 'nb_NO' ? 'Lukk' : 'Close';
        if (($payload['i18n']['close'] ?? null) !== $expected) throw new RuntimeException('Textdomain did not translate '.$handle.' for '.$locale);
        ++$checks;
        foreach ($payload['i18n'] as $value) {
            if (!is_string($value) || $value === '') throw new RuntimeException('Missing localized control label');
        }
        ++$checks;
    }
    $privacy=FKHS_Privacy::exporters(array());
    $expected=$locale==='nb_NO'?'H5P → Sensei-forsøk':'H5P → Sensei attempts';
    if ($privacy['h5p-sensei-bridge']['exporter_friendly_name']!==$expected) throw new RuntimeException('Privacy exporter locale mismatch');
    ++$checks;
    remove_filter('locale',$locale_filter,99);
    remove_filter('determine_locale',$locale_filter,99);
}
// A real packaged install uses fixed mtimes. Changing bytes with the SAME mtime
// must invalidate both script URLs, including two builds of one dev version.
foreach (array('fkhs-h5p-bridge'=>'h5p-sensei-bridge.js','fkhs-admin-report'=>'h5p-sensei-admin-report.js') as $handle=>$filename) {
    $path=FKHS_DIR.'assets/js/'.$filename;
    $bytes=file_get_contents($path);$mtime=filemtime($path);
    $version=$wp_scripts->registered[$handle]->ver;
    try {
        file_put_contents($path,$bytes."\n// Disposable same-mtime cache test.\n");touch($path,$mtime);
        $wp_scripts=new WP_Scripts();FKHS_Plugin::enqueue_front();FKHS_Reporter::enqueue_admin('toplevel_page_fkhs-report');
        clearstatcache(true,$path);
        if($wp_scripts->registered[$handle]->ver===$version || filemtime($path)!==$mtime)throw new RuntimeException('Stale script version after same-mtime update: '.$handle);
        ++$checks;
    } finally { file_put_contents($path,$bytes);touch($path,$mtime); }
    $wp_scripts=new WP_Scripts();FKHS_Plugin::enqueue_front();FKHS_Reporter::enqueue_admin('toplevel_page_fkhs-report');
    if($wp_scripts->registered[$handle]->ver!==$version)throw new RuntimeException('Restored script version mismatch: '.$handle);
    ++$checks;
}
WP_CLI::success($checks.' real WordPress translation/enqueue assertions passed.');
