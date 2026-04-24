<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Feature_Flags;
use SpiritWP_MCP\Confirm_Token;

/**
 * PHP execution controller.
 *
 * Gated behind enable_exec_php feature flag + confirm token.
 * Captures output buffer and return value.
 */
final class Exec_PHP_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'exec-php';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/exec-php', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'eval', 'manage_options', [ $this, 'evaluate' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function evaluate( \WP_REST_Request $request ): \WP_REST_Response {
        // Feature flag gate.
        $flag_check = Feature_Flags::guard( 'enable_exec_php' );
        if ( $flag_check ) {
            return $flag_check;
        }

        $params = $request->get_json_params();
        $code   = $params['code'] ?? '';

        if ( ! $code || ! is_string( $code ) ) {
            return Response::error( 'INVALID_INPUT', 'Provide a "code" string.', 400 );
        }

        // Confirm token.
        $confirm = Confirm_Token::guard(
            'exec-php.eval',
            [ 'code_hash' => hash( 'sha256', $code ) ],
            $params['confirm_token'] ?? null
        );
        if ( $confirm ) {
            return $confirm;
        }

        // Execute with output buffering.
        ob_start();
        $error  = null;
        $result = null;

        try {
            // phpcs:ignore Squiz.PHP.Eval.Discouraged
            $result = eval( $code );
        } catch ( \Throwable $e ) {
            $error = [
                'class'   => get_class( $e ),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ];
        }

        $output = ob_get_clean();

        if ( $error ) {
            return Response::error(
                'PHP_ERROR',
                $error['message'],
                500
            );
        }

        return Response::ok( [
            'output' => $output,
            'return' => is_scalar( $result ) || is_null( $result ) ? $result : wp_json_encode( $result ),
        ] );
    }
}
