<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

final class Activator {

    public static function activate(): void {
        // Default to Mode A.
        if ( false === get_option( 'spiritwp_mcp_mode' ) ) {
            add_option( 'spiritwp_mcp_mode', 'a' );
        }

        // Default feature flags — all off.
        if ( false === get_option( 'spiritwp_mcp_flags' ) ) {
            add_option( 'spiritwp_mcp_flags', [] );
        }

        // Default confirm token TTL.
        if ( false === get_option( 'spiritwp_mcp_confirm_ttl' ) ) {
            add_option( 'spiritwp_mcp_confirm_ttl', 60 );
        }

        // Ensure audit directory exists.
        $dir = Audit::get_log_dir();
        wp_mkdir_p( $dir );

        // Flush rewrite rules so the REST route registers.
        flush_rewrite_rules();
    }
}
