<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FKHS_Lifecycle_CLI {
    /**
     * Preview or clear one bounded batch of older raw xAPI statements.
     *
     * Scores, completion flags and duplicate-delivery receipts remain intact.
     * Use --user=<administrator> and --url=<site> on multisite.
     *
     * ## OPTIONS
     *
     * --before=<date>
     * : Exclusive cutoff in stored site time, YYYY-MM-DD.
     *
     * [--through-id=<id>]
     * : Highest eligible attempt ID from a reviewed dry run; required with --apply.
     *
     * [--after-id=<id>]
     * : Resume after this ID. Start at 0; after applying a batch use next_after_id.
     * ---
     * default: 0
     * ---
     *
     * [--batch-size=<count>]
     * : Rows per invocation, 1–200.
     * ---
     * default: 100
     * ---
     *
     * [--apply]
     * : Clear the selected raw statements. Omit for a read-only preview.
     */
    public static function cleanup( $positional, $assoc ): void {
        $result = FKHS_Privacy::cleanup_statements(array(
            'before'=>$assoc['before'] ?? '',
            'through_id'=>$assoc['through-id'] ?? null,
            'after_id'=>$assoc['after-id'] ?? 0,
            'batch_size'=>$assoc['batch-size'] ?? 100,
            'apply'=>$assoc['apply'] ?? false,
        ));
        if ( is_wp_error($result) ) { WP_CLI::error($result->get_error_message()); }
        WP_CLI::line(wp_json_encode($result));
    }
}
