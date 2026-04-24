<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Two-step confirm-token pattern for destructive operations.
 *
 * First call with confirm_token: null → 409 with pending token + TTL.
 * Second call with matching confirm_token → verify, delete, execute.
 * Single-use. TTL configurable (default 60s, 10–600).
 */
final class Confirm_Token {

    private const TTL_OPTION = 'spiritwp_mcp_confirm_ttl';
    private const PREFIX     = 'spiritwp_mcp_ct_';

    /**
     * Get configured TTL in seconds.
     */
    public static function get_ttl(): int {
        $ttl = (int) get_option( self::TTL_OPTION, 60 );
        return max( 10, min( 600, $ttl ) );
    }

    /**
     * Set TTL.
     */
    public static function set_ttl( int $seconds ): void {
        update_option( self::TTL_OPTION, max( 10, min( 600, $seconds ) ) );
    }

    /**
     * Guard a destructive operation.
     *
     * Call this at the start of any handler that requires confirmation.
     * Returns null if the confirm_token is valid → proceed with the operation.
     * Returns a WP_REST_Response if confirmation is required or token is wrong.
     *
     * @param string $tool  Tool identifier (e.g. "content.delete").
     * @param array  $args  Arguments hash (used to bind token to specific call).
     * @param string|null $token The confirm_token from the request.
     * @return \WP_REST_Response|null
     */
    public static function guard( string $tool, array $args, ?string $token ): ?\WP_REST_Response {
        $key = self::transient_key( $tool, $args );

        if ( null === $token || '' === $token ) {
            // Issue a new pending token.
            $pending    = wp_generate_password( 32, false );
            $ttl        = self::get_ttl();
            $expires_at = time() + $ttl;

            set_transient( $key, $pending, $ttl );

            return Response::confirm_required( $pending, $expires_at );
        }

        // Verify supplied token.
        $stored = get_transient( $key );

        if ( ! $stored ) {
            return Response::error(
                'CONFIRM_TOKEN_EXPIRED',
                'Confirm token has expired or was already used. Retry the operation.',
                409
            );
        }

        if ( ! hash_equals( $stored, $token ) ) {
            return Response::error(
                'CONFIRM_TOKEN_INVALID',
                'Confirm token does not match. Retry the operation.',
                409
            );
        }

        // Valid — consume (single-use).
        delete_transient( $key );
        return null;
    }

    /**
     * Build a transient key from tool name + args hash.
     */
    private static function transient_key( string $tool, array $args ): string {
        $hash = hash( 'sha256', $tool . '|' . wp_json_encode( $args ) );
        return self::PREFIX . $hash;
    }
}
