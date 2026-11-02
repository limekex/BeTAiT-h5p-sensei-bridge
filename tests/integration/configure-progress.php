<?php
/** Change only the disposable test site's configuration, before a fresh WP process. */
if (!defined('FKHS_INTEGRATION_TESTS') || !FKHS_INTEGRATION_TESTS || !defined('WP_CLI') || !WP_CLI) throw new RuntimeException('Disposable fixture required.');
$mode = $args[0] ?? '';
if (!in_array($mode, array('comments','dual-comments','dual-tables'),true)) throw new RuntimeException('Unknown storage mode.');
$settings = get_option(Sensei()->settings->token, array());
$settings['experimental_progress_storage'] = 'comments' !== $mode;
$settings['experimental_progress_storage_synchronization'] = 'comments' !== $mode;
$settings['experimental_progress_storage_repository'] = 'dual-tables' === $mode ? 'custom_tables' : 'comments';
update_option(Sensei()->settings->token, $settings);
