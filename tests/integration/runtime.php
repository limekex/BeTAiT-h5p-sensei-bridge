<?php
/** Fail before fixtures if either process runs a different dependency stack. */
if (!defined('FKHS_INTEGRATION_TESTS') || true !== FKHS_INTEGRATION_TESTS || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Disposable integration environment required.');
}
$expected_wp = $args[0];
$expected_php = $args[1];
$response = wp_remote_get(rest_url('fkhs-fixture/v1/runtime'), array('timeout'=>30, 'redirection'=>0));
if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
    throw new RuntimeException('HTTP runtime probe failed.');
}
$runtimes = array('cli'=>fkhs_fixture_runtime(), 'http'=>json_decode(wp_remote_retrieve_body($response), true));
foreach ($runtimes as $context=>$runtime) {
    WP_CLI::log($context.': '.wp_json_encode($runtime));
    if (!is_array($runtime)
        || $expected_wp !== ($runtime['wordpress'] ?? null)
        || $expected_php !== implode('.', array_slice(explode('.', $runtime['php'] ?? ''), 0, 2))
        || '4.26.4' !== ($runtime['sensei'] ?? null)
        || '1.17.10' !== ($runtime['h5p'] ?? null)
        || '10.11.18' !== ($runtime['database'] ?? null)
        || ('cli' === $context ? 'cli' : 'apache2handler') !== ($runtime['sapi'] ?? null)) {
        throw new RuntimeException('Unexpected '.$context.' runtime; refusing misleading compatibility results.');
    }
}
WP_CLI::success('CLI and HTTP dependency versions match the selected profile.');
