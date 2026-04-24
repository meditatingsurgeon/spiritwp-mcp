<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Network guard for Mode A.
 *
 * Restricts /spiritwp-mcp/v1/* to localhost and RFC1918 private IPs.
 * Mode B: no IP restriction but requires HTTPS.
 */
final class Network_Guard {

    private const PRIVATE_RANGES = [
        '127.0.0.1',
        '::1',
    ];

    private const PRIVATE_CIDRS = [
        [ '10.0.0.0',     '10.255.255.255'     ],
        [ '172.16.0.0',   '172.31.255.255'     ],
        [ '192.168.0.0',  '192.168.255.255'    ],
    ];

    /**
     * Hook into rest_pre_dispatch.
     */
    public static function register(): void {
        add_filter( 'rest_pre_dispatch', [ __CLASS__, 'check' ], 5, 3 );
    }

    /**
     * Filter callback — checks network posture before any spiritmcp route.
     *
     * @param mixed            $result  Pre-dispatch result.
     * @param \WP_REST_Server  $server  REST server.
     * @param \WP_REST_Request $request Current request.
     * @return mixed|\WP_REST_Response
     */
    public static function check( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
        if ( null !== $result ) {
            return $result;
        }

        $route = $request->get_route();
        if ( ! str_starts_with( $route, '/spiritwp-mcp/v1/' ) ) {
            return $result;
        }

        if ( Mode::is_a() ) {
            return self::check_mode_a( $request ) ?? $result;
        }

        // Mode B — require HTTPS.
        if ( ! is_ssl() ) {
            return Response::error(
                'HTTPS_REQUIRED',
                'Mode B requires HTTPS. Upgrade your connection.',
                426
            );
        }

        return $result;
    }

    /**
     * Mode A — reject unless from localhost / private network.
     */
    private static function check_mode_a( \WP_REST_Request $request ): ?\WP_REST_Response {
        $remote = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );

        if ( ! self::is_private( $remote ) ) {
            return Response::error(
                'MODE_A_LOCALHOST_ONLY',
                'This endpoint is restricted to local network calls. Enable Mode B in settings for public access.',
                451
            );
        }

        // Also check X-Forwarded-For first entry if present.
        $forwarded = sanitize_text_field( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' );
        if ( $forwarded ) {
            $first = trim( explode( ',', $forwarded )[0] );
            if ( ! self::is_private( $first ) ) {
                return Response::error(
                    'MODE_A_LOCALHOST_ONLY',
                    'X-Forwarded-For origin is not a private address. Enable Mode B for public access.',
                    451
                );
            }
        }

        return null;
    }

    /**
     * Check if an IP is localhost or RFC1918 private.
     */
    public static function is_private( string $ip ): bool {
        if ( in_array( $ip, self::PRIVATE_RANGES, true ) ) {
            return true;
        }

        $long = ip2long( $ip );
        if ( false === $long ) {
            return false;
        }

        foreach ( self::PRIVATE_CIDRS as [ $start, $end ] ) {
            if ( $long >= ip2long( $start ) && $long <= ip2long( $end ) ) {
                return true;
            }
        }

        return false;
    }
}
