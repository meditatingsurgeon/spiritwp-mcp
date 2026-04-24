<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class — singleton bootstrap.
 */
final class Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Network guard (IP restriction for Mode A, HTTPS for Mode B).
        Network_Guard::register();

        // Authentication (Bridge Key / App Password / JWT).
        Auth::register();

        // License gating.
        add_filter( 'rest_pre_dispatch', [ $this, 'license_gate' ], 3, 3 );

        // CORS for Mode B.
        add_filter( 'rest_pre_serve_request', [ $this, 'cors_headers' ], 10, 4 );

        // Register REST routes.
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Admin settings page.
        if ( is_admin() ) {
            add_action( 'admin_menu', [ $this, 'admin_menu' ] );
            add_action( 'admin_init', [ $this, 'admin_init' ] );
        }
    }

    /**
     * Register all REST controllers + MCP endpoint.
     */
    public function register_rest_routes(): void {
        $controllers = [
            new Rest\Status_Controller(),
            new Rest\Content_Controller(),
            new Rest\Meta_Controller(),
            new Rest\Options_Controller(),
            new Rest\Cache_Controller(),
            new Rest\Media_Controller(),
            new Rest\Nav_Controller(),
            new Rest\Builder_Controller(),
            new Rest\SEO_Controller(),
            new Rest\Users_Controller(),
            new Rest\Rewrite_Controller(),
            new Rest\Forms_Controller(),
            new Rest\Search_Replace_Controller(),
            new Rest\Patterns_Controller(),
            new Rest\Taxonomies_Controller(),
            new Rest\Widgets_Controller(),
            new Rest\Exec_SQL_Controller(),
            new Rest\Exec_PHP_Controller(),
            new Rest\Filesystem_Controller(),
            new Rest\Passthrough_Controller(),
        ];

        foreach ( $controllers as $controller ) {
            $controller->register_routes();
        }

        // MCP JSON-RPC endpoint (both modes — Mode B public, Mode A local).
        MCP\MCP_Server::register();
    }

    /**
     * License gating — block all routes except /license/* and /tokens/*
     * when license is not active.
     */
    public function license_gate( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
        if ( null !== $result ) {
            return $result;
        }

        $route = $request->get_route();
        if ( ! str_starts_with( $route, '/spiritwp-mcp/v1/' ) ) {
            return $result;
        }

        // Always allow license and token management.
        if ( str_contains( $route, '/license' ) || str_contains( $route, '/tokens' ) ) {
            return $result;
        }

        $state = Licensing\License_Manager::get_state();
        if ( 'active' !== $state['status'] ) {
            return Response::error(
                'LICENSE_INACTIVE',
                sprintf( 'License is %s. Please re-activate in Settings → SpiritWP MCP.', $state['status'] ),
                402
            );
        }

        return $result;
    }

    /**
     * CORS headers for Mode B.
     */
    public function cors_headers( bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server ): bool {
        if ( ! Mode::is_b() ) {
            return $served;
        }

        $route = $request->get_route();
        if ( ! str_starts_with( $route, '/spiritwp-mcp/v1/' ) ) {
            return $served;
        }

        $origin  = sanitize_text_field( $_SERVER['HTTP_ORIGIN'] ?? '' );
        $allowed = get_option( 'spiritwp_mcp_allowed_origins', [] );

        if ( $origin && is_array( $allowed ) && in_array( $origin, $allowed, true ) ) {
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Bridge-Key' );
        }

        return $served;
    }

    /**
     * Admin menu.
     */
    public function admin_menu(): void {
        add_options_page(
            'SpiritWP MCP',
            'SpiritWP MCP',
            'manage_options',
            'spiritwp-mcp',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Admin settings init.
     */
    public function admin_init(): void {
        // Handle settings form submissions.
        if ( isset( $_POST['spiritwp_mcp_action'] ) && check_admin_referer( 'spiritwp_mcp_settings' ) ) {
            $this->handle_settings_action( sanitize_key( $_POST['spiritwp_mcp_action'] ) );
        }
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $mode          = Mode::get();
        $flags         = Feature_Flags::registry();
        $bridge_keys   = Auth::list_bridge_keys();
        $license       = Licensing\License_Manager::get_state();
        $confirm_ttl   = Confirm_Token::get_ttl();
        $audit_size    = Audit::get_size();
        $health_checks = Mode::run_health_checks();

        include SPIRITWP_MCP_DIR . 'admin/views/settings.php';
    }

    /**
     * Handle settings form actions.
     */
    private function handle_settings_action( string $action ): void {
        switch ( $action ) {
            case 'switch_mode_a':
                Mode::switch_to_a();
                add_settings_error( 'spiritwp_mcp', 'mode', 'Switched to Mode A (Bridge).', 'updated' );
                break;

            case 'switch_mode_b':
                $result = Mode::switch_to_b();
                if ( is_wp_error( $result ) ) {
                    add_settings_error( 'spiritwp_mcp', 'mode', $result->get_error_message(), 'error' );
                } else {
                    add_settings_error( 'spiritwp_mcp', 'mode', 'Switched to Mode B (Standalone MCP).', 'updated' );
                }
                break;

            case 'generate_bridge_key':
                $label = sanitize_text_field( $_POST['key_label'] ?? '' );
                $result = Auth::generate_bridge_key( get_current_user_id(), $label );
                add_settings_error( 'spiritwp_mcp', 'key', sprintf(
                    'Bridge key generated. Copy it now — it won\'t be shown again: <code>%s</code>',
                    esc_html( $result['key'] )
                ), 'updated' );
                break;

            case 'revoke_bridge_key':
                $id = sanitize_text_field( $_POST['key_id'] ?? '' );
                Auth::revoke_bridge_key( $id );
                add_settings_error( 'spiritwp_mcp', 'key', 'Bridge key revoked.', 'updated' );
                break;

            case 'issue_jwt':
                $label = sanitize_text_field( $_POST['jwt_label'] ?? '' );
                $ttl   = absint( $_POST['jwt_ttl'] ?? 86400 );
                $token = Auth::issue_jwt( get_current_user_id(), $label, '*', $ttl );
                add_settings_error( 'spiritwp_mcp', 'jwt', sprintf(
                    'JWT issued. Copy it now: <code>%s</code>',
                    esc_html( $token )
                ), 'updated' );
                break;

            case 'rotate_jwt_secret':
                Auth::rotate_jwt_secret();
                add_settings_error( 'spiritwp_mcp', 'jwt', 'JWT secret rotated. All existing tokens are now invalid.', 'updated' );
                break;

            case 'update_flags':
                $flags = $_POST['flags'] ?? [];
                foreach ( Feature_Flags::registry() as $key => $_ ) {
                    Feature_Flags::set( $key, isset( $flags[ $key ] ) );
                }
                add_settings_error( 'spiritwp_mcp', 'flags', 'Feature flags updated.', 'updated' );
                break;

            case 'update_confirm_ttl':
                $ttl = absint( $_POST['confirm_ttl'] ?? 60 );
                Confirm_Token::set_ttl( $ttl );
                add_settings_error( 'spiritwp_mcp', 'ttl', 'Confirm token TTL updated.', 'updated' );
                break;

            case 'clear_audit':
                Audit::clear();
                add_settings_error( 'spiritwp_mcp', 'audit', 'Audit log cleared.', 'updated' );
                break;
        }
    }
}
