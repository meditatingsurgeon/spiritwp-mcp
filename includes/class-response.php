<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Standardised response envelope for all endpoints.
 *
 * Success: { "ok": true, "data": <any> }
 * Error:   { "ok": false, "error_code": "UPPER_SNAKE_CASE", "message": "human readable" }
 */
final class Response {

    /**
     * Success envelope.
     *
     * @param mixed $data    Payload.
     * @param int   $status  HTTP status code.
     * @return \WP_REST_Response
     */
    public static function ok( mixed $data = null, int $status = 200 ): \WP_REST_Response {
        return new \WP_REST_Response(
            [ 'ok' => true, 'data' => $data ],
            $status
        );
    }

    /**
     * Error envelope.
     *
     * @param string $code    UPPER_SNAKE_CASE error code.
     * @param string $message Human-readable message.
     * @param int    $status  HTTP status code.
     * @return \WP_REST_Response
     */
    public static function error( string $code, string $message, int $status = 400 ): \WP_REST_Response {
        return new \WP_REST_Response(
            [
                'ok'         => false,
                'error_code' => $code,
                'message'    => $message,
            ],
            $status
        );
    }

    /**
     * Confirm-token-required envelope.
     *
     * @param string $pending_token Random token.
     * @param int    $expires_at    Unix timestamp.
     * @return \WP_REST_Response
     */
    public static function confirm_required( string $pending_token, int $expires_at ): \WP_REST_Response {
        return new \WP_REST_Response(
            [
                'ok'            => false,
                'error_code'    => 'CONFIRM_REQUIRED',
                'pending_token' => $pending_token,
                'expires_at'    => $expires_at,
            ],
            409
        );
    }

    /**
     * Map a WP_Error to an error envelope.
     *
     * @param \WP_Error $wp_error The error.
     * @param int       $status   HTTP status.
     * @return \WP_REST_Response
     */
    public static function from_wp_error( \WP_Error $wp_error, int $status = 500 ): \WP_REST_Response {
        return self::error(
            strtoupper( str_replace( '-', '_', $wp_error->get_error_code() ) ),
            $wp_error->get_error_message(),
            $status
        );
    }
}
