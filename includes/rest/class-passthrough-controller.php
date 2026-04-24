<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;

/**
 * Passthrough controller — proxy arbitrary WP REST API routes.
 *
 * For edge cases where the 19 task-shaped controllers don't cover
 * a specific need. Sends an internal REST request and returns the result.
 */
final class Passthrough_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'passthrough';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/passthrough', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'proxy', 'manage_options', [ $this, 'proxy' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function proxy( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();
        $route  = $params['route'] ?? '';
        $method = strtoupper( Sanitize::text( $params['method'] ?? 'GET' ) );
        $body   = $params['body'] ?? [];

        if ( ! $route || ! str_starts_with( $route, '/' ) ) {
            return Response::error( 'INVALID_INPUT', 'Provide a "route" starting with /.', 400 );
        }

        // Block recursive calls to our own namespace.
        if ( str_starts_with( $route, '/spiritwp-mcp/' ) ) {
            return Response::error( 'RECURSIVE_CALL', 'Cannot passthrough to spiritwp-mcp routes.', 400 );
        }

        $internal = new \WP_REST_Request( $method, $route );

        if ( $body && is_array( $body ) ) {
            foreach ( $body as $key => $value ) {
                $internal->set_param( $key, $value );
            }
            $internal->set_body( wp_json_encode( $body ) );
            $internal->set_header( 'Content-Type', 'application/json' );
        }

        $server   = rest_get_server();
        $response = $server->dispatch( $internal );
        $data     = $server->response_to_data( $response, false );

        return Response::ok( [
            'status' => $response->get_status(),
            'data'   => $data,
        ] );
    }
}
