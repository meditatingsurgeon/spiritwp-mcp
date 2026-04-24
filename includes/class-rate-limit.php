<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Rate limiter for Mode B. 30 requests per minute per user_id.
 * Uses transients keyed by user_id.
 */
final class Rate_Limit {

    private const LIMIT    = 30;
    private const WINDOW   = 60; // seconds.
    private const PREFIX   = 'spiritwp_mcp_rl_';

    /**
     * Check rate limit. Returns null if OK, error response if exceeded.
     */
    public static function check(): ?\WP_REST_Response {
        if ( Mode::is_a() ) {
            return null; // No rate limit in Mode A.
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return null;
        }

        $key   = self::PREFIX . $user_id;
        $state = get_transient( $key );

        $now = time();

        if ( ! $state ) {
            $state = [ 'count' => 0, 'window_start' => $now ];
        }

        // Reset window if expired.
        if ( ( $now - $state['window_start'] ) >= self::WINDOW ) {
            $state = [ 'count' => 0, 'window_start' => $now ];
        }

        $state['count']++;

        set_transient( $key, $state, self::WINDOW );

        if ( $state['count'] > self::LIMIT ) {
            $retry = self::WINDOW - ( $now - $state['window_start'] );
            $resp  = Response::error(
                'RATE_LIMITED',
                sprintf( 'Rate limit exceeded (%d/%d per minute). Retry after %ds.', self::LIMIT, self::LIMIT, $retry ),
                429
            );
            $resp->header( 'Retry-After', (string) max( 1, $retry ) );
            return $resp;
        }

        return null;
    }
}
