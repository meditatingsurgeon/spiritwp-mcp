<?php

namespace SpiritWP_MCP\MCP;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;

/**
 * MCP Server — JSON-RPC 2.0 over Streamable HTTP.
 *
 * Handles: initialize, tools/list, tools/call.
 * Stateless — no session ID.
 */
final class MCP_Server {

    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME      = 'SpiritWP MCP';

    /**
     * Register the MCP endpoint.
     */
    public static function register(): void {
        register_rest_route( 'spiritwp-mcp/v1', '/mcp', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle' ],
            'permission_callback' => '__return_true', // Auth handled by Auth class.
        ] );
    }

    /**
     * Handle a JSON-RPC 2.0 request.
     */
    public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_body();

        try {
            $rpc = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
        } catch ( \JsonException $e ) {
            return self::jsonrpc_error( null, -32700, 'Parse error: ' . $e->getMessage() );
        }

        $jsonrpc = $rpc['jsonrpc'] ?? '';
        $method  = $rpc['method'] ?? '';
        $id      = $rpc['id'] ?? null;
        $params  = $rpc['params'] ?? [];

        if ( '2.0' !== $jsonrpc ) {
            return self::jsonrpc_error( $id, -32600, 'Invalid JSON-RPC version. Must be "2.0".' );
        }

        return match ( $method ) {
            'initialize'  => self::handle_initialize( $id, $params ),
            'tools/list'  => self::handle_tools_list( $id, $params ),
            'tools/call'  => self::handle_tools_call( $id, $params ),
            default       => self::jsonrpc_error( $id, -32601, "Method not found: {$method}" ),
        };
    }

    /**
     * Handle initialize — return capabilities.
     */
    private static function handle_initialize( mixed $id, array $params ): \WP_REST_Response {
        return self::jsonrpc_result( $id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => [
                'tools' => new \stdClass(),
            ],
            'serverInfo' => [
                'name'    => self::SERVER_NAME,
                'version' => SPIRITWP_MCP_VERSION,
            ],
        ] );
    }

    /**
     * Handle tools/list — return all available tools.
     */
    private static function handle_tools_list( mixed $id, array $params ): \WP_REST_Response {
        $tools    = Tool_Registry::get_tools();
        $tool_list = [];

        foreach ( $tools as $name => $tool ) {
            $tool_list[] = [
                'name'        => $name,
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }

        return self::jsonrpc_result( $id, [ 'tools' => $tool_list ] );
    }

    /**
     * Handle tools/call — dispatch to the corresponding REST handler.
     */
    private static function handle_tools_call( mixed $id, array $params ): \WP_REST_Response {
        $tool_name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if ( ! $tool_name ) {
            return self::jsonrpc_error( $id, -32602, 'Missing tool name in params.name.' );
        }

        $resolved = Tool_Registry::resolve( $tool_name );

        if ( ! $resolved ) {
            return self::jsonrpc_error( $id, -32601, "Tool not found or not available in current mode: {$tool_name}" );
        }

        // Build an internal REST request to the tool's route.
        $route  = $resolved['route'];
        $method = $resolved['method'];

        // Replace path parameters like {id}, {post_id}, {object_type} etc.
        foreach ( $arguments as $key => $value ) {
            if ( str_contains( $route, '{' . $key . '}' ) ) {
                $route = str_replace( '{' . $key . '}', (string) $value, $route );
            }
        }

        $internal = new \WP_REST_Request( $method, $route );

        // Set path parameters (from the route).
        foreach ( $arguments as $key => $value ) {
            $internal->set_param( $key, $value );
        }

        // For POST/PUT, also set JSON body.
        if ( in_array( $method, [ 'POST', 'PUT', 'DELETE' ], true ) ) {
            $internal->set_body( wp_json_encode( $arguments ) );
            $internal->set_header( 'Content-Type', 'application/json' );
        }

        // Dispatch internally.
        $server   = rest_get_server();
        $response = $server->dispatch( $internal );
        $data     = $response->get_data();

        // Wrap the envelope inside MCP tools/call result content.
        return self::jsonrpc_result( $id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => wp_json_encode( $data, JSON_UNESCAPED_SLASHES ),
                ],
            ],
        ] );
    }

    // ── JSON-RPC helpers ────────────────────────────────────────────────

    private static function jsonrpc_result( mixed $id, mixed $result ): \WP_REST_Response {
        return new \WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ], 200 );
    }

    private static function jsonrpc_error( mixed $id, int $code, string $message, mixed $data = null ): \WP_REST_Response {
        $error = [
            'code'    => $code,
            'message' => $message,
        ];
        if ( null !== $data ) {
            $error['data'] = $data;
        }

        $status = match ( true ) {
            $code === -32700 => 400,
            $code === -32600 => 400,
            $code === -32601 => 404,
            $code === -32602 => 400,
            default          => 500,
        };

        return new \WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => $error,
        ], $status );
    }
}
