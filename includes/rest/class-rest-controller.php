<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Audit;
use SpiritWP_MCP\Rate_Limit;

/**
 * Abstract base controller for all 20 task-shaped tools.
 *
 * Subclasses implement register_routes() and their handlers.
 * This base provides common infrastructure: namespace, audit wrapping,
 * rate limiting, and capability checking.
 */
abstract class REST_Controller {

    protected const NAMESPACE = 'spiritwp-mcp/v1';

    /**
     * Register this controller's routes. Called by Plugin::register_rest().
     */
    abstract public function register_routes(): void;

    /**
     * Tool category name (e.g. "content", "meta", "status").
     * Used for audit logging and MCP tool naming.
     */
    abstract protected function tool_category(): string;

    /**
     * Wrap a handler with capability checking, rate limiting, and audit logging.
     *
     * @param string   $tool_action  Action name (e.g. "list", "get", "create").
     * @param string   $capability   Required WP capability.
     * @param callable $handler      The actual handler fn(WP_REST_Request): WP_REST_Response.
     * @return callable
     */
    protected function wrap( string $tool_action, string $capability, callable $handler ): callable {
        return function ( \WP_REST_Request $request ) use ( $tool_action, $capability, $handler ) {
            $start = microtime( true );
            $tool  = $this->tool_category() . '.' . $tool_action;

            // Rate limit (Mode B only).
            $rate_check = Rate_Limit::check();
            if ( $rate_check ) {
                return $rate_check;
            }

            // Capability check.
            if ( ! current_user_can( $capability ) ) {
                return Response::error(
                    'FORBIDDEN',
                    sprintf( 'You do not have the "%s" capability.', $capability ),
                    403
                );
            }

            // Execute handler.
            try {
                $response = $handler( $request );
            } catch ( \Throwable $e ) {
                $response = Response::error(
                    'INTERNAL_ERROR',
                    'An unexpected error occurred: ' . $e->getMessage(),
                    500
                );
            }

            // Audit.
            $data = $response->get_data();
            Audit::log( Audit::entry(
                $tool,
                $request->get_route(),
                $request->get_method(),
                $response->get_status(),
                $this->detect_auth_type(),
                $this->detect_auth_ref(),
                $request->get_params(),
                $data,
                $start
            ) );

            return $response;
        };
    }

    /**
     * Capability check for a specific post.
     *
     * @param string $base_cap  e.g. "edit_post".
     * @param int    $post_id   The post ID.
     * @return \WP_REST_Response|null Null if allowed.
     */
    protected function check_post_cap( string $base_cap, int $post_id ): ?\WP_REST_Response {
        if ( ! current_user_can( $base_cap, $post_id ) ) {
            return Response::error(
                'FORBIDDEN',
                sprintf( 'You cannot %s post %d.', $base_cap, $post_id ),
                403
            );
        }
        return null;
    }

    /**
     * Standard permission callback — always true (auth is handled in rest_pre_dispatch).
     */
    public function public_permission(): bool {
        return true;
    }

    /**
     * Detect auth type from current request context.
     */
    private function detect_auth_type(): string {
        if ( ! empty( $_SERVER['HTTP_X_BRIDGE_KEY'] ) ) {
            return 'bridge_key';
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ( str_starts_with( $auth, 'Bearer ' ) ) {
            return 'jwt';
        }
        if ( str_starts_with( $auth, 'Basic ' ) ) {
            return 'app_password';
        }
        return 'unknown';
    }

    /**
     * Detect auth reference (first 8 chars of key/token for log correlation).
     */
    private function detect_auth_ref(): ?string {
        if ( ! empty( $_SERVER['HTTP_X_BRIDGE_KEY'] ) ) {
            return substr( sanitize_text_field( $_SERVER['HTTP_X_BRIDGE_KEY'] ), 0, 8 );
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ( str_starts_with( $auth, 'Bearer ' ) ) {
            return substr( $auth, 7, 8 );
        }
        return null;
    }
}
