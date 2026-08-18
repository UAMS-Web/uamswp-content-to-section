<?php
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's options. Pre-conversion backups are intentionally left
 * in place so a converted post can still be restored after the plugin is
 * reinstalled; delete them with `wp post meta delete <id> _uams_c2s_backup`.
 *
 * @package UAMS_Content_To_Section
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'uams_c2s_options' );
delete_transient( 'uams_c2s_batch_skipped' );
