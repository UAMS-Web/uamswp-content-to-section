<?php

declare(strict_types=1);
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's options and the transients its batch runs park failures
 * in. This file runs without the plugin bootstrap, so the keys are repeated as
 * literals rather than read from class constants -- when a new option or
 * transient is added, it has to be added here too. The current set is every
 * `set_transient()`/`update_option()` call under `includes/`.
 *
 * Per-post meta is intentionally left in place so a converted post can still be
 * restored after the plugin is reinstalled. To clear it by hand:
 *
 *     wp post meta delete <id> _uams_c2s_backup
 *     wp post meta delete <id> _uams_c2s_converted
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('uams_c2s_options');
delete_transient('uams_c2s_batch_skipped');
delete_transient('uams_c2s_repair_skipped');
