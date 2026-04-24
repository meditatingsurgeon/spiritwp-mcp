<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

    public static function deactivate(): void {
        // Remove any scheduled cron hooks.
        wp_clear_scheduled_hook( 'spiritwp_mcp_license_recheck' );
        flush_rewrite_rules();
    }
}
