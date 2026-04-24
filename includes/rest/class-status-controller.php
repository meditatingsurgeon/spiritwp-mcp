<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Mode;
use SpiritWP_MCP\Feature_Flags;

final class Status_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'status';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/status/info', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'info', 'manage_options', [ $this, 'info' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/status/health', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'health', 'manage_options', [ $this, 'health' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function info( \WP_REST_Request $request ): \WP_REST_Response {
        global $wp_version;

        return Response::ok( [
            'site_url'      => get_site_url(),
            'home_url'      => get_home_url(),
            'name'          => get_bloginfo( 'name' ),
            'description'   => get_bloginfo( 'description' ),
            'wp_version'    => $wp_version,
            'php_version'   => PHP_VERSION,
            'plugin_version' => SPIRITWP_MCP_VERSION,
            'mode'          => Mode::get(),
            'multisite'     => is_multisite(),
            'timezone'      => wp_timezone_string(),
            'language'      => get_locale(),
            'active_theme'  => get_stylesheet(),
            'is_ssl'        => is_ssl(),
            'feature_flags' => Feature_Flags::all(),
        ] );
    }

    public function health( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $db_ok = (bool) $wpdb->get_var( 'SELECT 1' );

        return Response::ok( [
            'status'        => 'ok',
            'db'            => $db_ok ? 'connected' : 'error',
            'php_memory'    => ini_get( 'memory_limit' ),
            'wp_debug'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'mode'          => Mode::get(),
            'uptime'        => time() - (int) get_option( 'spiritwp_mcp_activated_at', time() ),
            'audit_log_size' => \SpiritWP_MCP\Audit::get_size(),
        ] );
    }
}
