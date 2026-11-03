<?php
/** Uninstall retains attempts, schema metadata and lesson thresholds on every site. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
// No DROP, DELETE, user cleanup or multisite traversal. Reinstallation may reuse data.
// Raw statement cleanup and approved personal-data erasure are explicit tools only.
