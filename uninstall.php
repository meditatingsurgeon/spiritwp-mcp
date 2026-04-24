<?php
/**
 * Uninstall SpiritWP MCP.
 *
 * Removes all plugin options, audit directory, and cron hooks.
 * Does NOT touch user content.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove all options.
$options = [
    'spiritwp_mcp_mode',
    'spiritwp_mcp_flags',
    'spiritwp_mcp_confirm_ttl',
    'spiritwp_mcp_bridge_keys',
    'spiritwp_mcp_jwt_secret',
    'spiritwp_mcp_allowed_origins',
    'spiritwp_mcp_activated_at',
    'spiritwp_mcp_license_provider',
    'spiritwp_mcp_license_key',
    'spiritwp_mcp_license_instance_id',
    'spiritwp_mcp_license_state',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// Remove audit directory.
$upload_dir = wp_upload_dir();
$audit_dir  = $upload_dir['basedir'] . '/spiritwp-mcp';
if ( is_dir( $audit_dir ) ) {
    $files = glob( $audit_dir . '/*' );
    if ( $files ) {
        foreach ( $files as $file ) {
            wp_delete_file( $file );
        }
    }
    rmdir( $audit_dir );
}

// Remove cron hooks.
wp_clear_scheduled_hook( 'spiritwp_mcp_license_recheck' );

// Clean transients.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_spiritwp_mcp_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_spiritwp_mcp_%'" );
