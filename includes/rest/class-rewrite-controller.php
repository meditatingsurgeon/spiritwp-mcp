<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;

final class Rewrite_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'rewrite';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/rewrite/rules', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'list', 'manage_options', [ $this, 'list_rules' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/rewrite/flush', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'flush', 'manage_options', [ $this, 'flush_rules' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function list_rules( \WP_REST_Request $request ): \WP_REST_Response {
        global $wp_rewrite;
        return Response::ok( [
            'rules'     => $wp_rewrite->wp_rewrite_rules() ?: [],
            'structure' => $wp_rewrite->permalink_structure,
        ] );
    }

    public function flush_rules( \WP_REST_Request $request ): \WP_REST_Response {
        flush_rewrite_rules();
        return Response::ok( [ 'flushed' => true ] );
    }
}
