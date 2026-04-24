<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Authentication handler for both modes.
 *
 * Mode A: X-Bridge-Key header (shared secret, bcrypt-hashed).
 * Mode B: WP Application Password (HTTP Basic) or plugin-issued JWT (HS256).
 */
final class Auth {

    private const KEYS_OPTION   = 'spiritwp_mcp_bridge_keys';
    private const JWT_SECRET    = 'spiritwp_mcp_jwt_secret';
    private const DUMMY_HASH    = '$2y$10$dummyhashfortimingequalitypadding00000000000000000';

    /**
     * Hook into rest_pre_dispatch to authenticate spiritmcp routes.
     */
    public static function register(): void {
        add_filter( 'rest_pre_dispatch', [ __CLASS__, 'authenticate' ], 10, 3 );
    }

    /**
     * Authenticate the request based on current mode.
     */
    public static function authenticate( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
        if ( null !== $result ) {
            return $result;
        }

        $route = $request->get_route();
        if ( ! str_starts_with( $route, '/spiritwp-mcp/v1/' ) ) {
            return $result;
        }

        // Skip auth for OPTIONS (CORS preflight).
        if ( 'OPTIONS' === $request->get_method() ) {
            return $result;
        }

        if ( Mode::is_a() ) {
            return self::auth_bridge_key( $request ) ?? $result;
        }

        // Mode B — try JWT first, fall back to App Password.
        $auth_header = sanitize_text_field( $_SERVER['HTTP_AUTHORIZATION'] ?? '' );

        if ( str_starts_with( $auth_header, 'Bearer ' ) ) {
            return self::auth_jwt( $auth_header, $request ) ?? $result;
        }

        // App Password via HTTP Basic is handled by WP core (WP >= 5.6).
        // Check if WP already authenticated the user.
        if ( is_user_logged_in() ) {
            return $result;
        }

        return Response::error( 'UNAUTHORIZED', 'Valid authentication required.', 401 );
    }

    // ── Mode A: Bridge Key ──────────────────────────────────────────────

    /**
     * Verify X-Bridge-Key header.
     */
    private static function auth_bridge_key( \WP_REST_Request $request ): ?\WP_REST_Response {
        $key = sanitize_text_field( $_SERVER['HTTP_X_BRIDGE_KEY'] ?? '' );

        if ( ! $key ) {
            return Response::error( 'UNAUTHORIZED', 'X-Bridge-Key header required.', 401 );
        }

        $keys = get_option( self::KEYS_OPTION, [] );

        foreach ( $keys as $i => $entry ) {
            if ( password_verify( $key, $entry['hash'] ) ) {
                wp_set_current_user( (int) $entry['user_id'] );

                // Update last_used_at.
                $keys[ $i ]['last_used_at'] = time();
                update_option( self::KEYS_OPTION, $keys );

                return null; // Authenticated.
            }
        }

        // Timing-constant dummy verify.
        password_verify( $key, self::DUMMY_HASH );

        return Response::error( 'UNAUTHORIZED', 'Invalid bridge key.', 401 );
    }

    /**
     * Generate a new bridge key.
     *
     * @param int    $user_id User to associate.
     * @param string $label   Human-readable label.
     * @return array{id: string, key: string} The plaintext key (show once).
     */
    public static function generate_bridge_key( int $user_id, string $label = '' ): array {
        $key  = wp_generate_password( 48, true, true );
        $hash = password_hash( $key, PASSWORD_BCRYPT );
        $id   = wp_generate_uuid4();

        $keys   = get_option( self::KEYS_OPTION, [] );
        $keys[] = [
            'id'           => $id,
            'user_id'      => $user_id,
            'hash'         => $hash,
            'label'        => sanitize_text_field( $label ),
            'created_at'   => time(),
            'last_used_at' => null,
        ];
        update_option( self::KEYS_OPTION, $keys );

        return [ 'id' => $id, 'key' => $key ];
    }

    /**
     * Revoke a bridge key by ID.
     */
    public static function revoke_bridge_key( string $id ): bool {
        $keys    = get_option( self::KEYS_OPTION, [] );
        $keys    = array_values( array_filter( $keys, static fn( $k ) => $k['id'] !== $id ) );
        return update_option( self::KEYS_OPTION, $keys );
    }

    /**
     * List bridge keys (metadata only, no hashes).
     *
     * @return array<int, array{id: string, user_id: int, label: string, created_at: int, last_used_at: ?int}>
     */
    public static function list_bridge_keys(): array {
        $keys = get_option( self::KEYS_OPTION, [] );
        return array_map( static fn( $k ) => [
            'id'           => $k['id'],
            'user_id'      => $k['user_id'],
            'label'        => $k['label'],
            'created_at'   => $k['created_at'],
            'last_used_at' => $k['last_used_at'],
        ], $keys );
    }

    // ── Mode B: JWT ─────────────────────────────────────────────────────

    /**
     * Verify a Bearer JWT token.
     */
    private static function auth_jwt( string $auth_header, \WP_REST_Request $request ): ?\WP_REST_Response {
        $token = substr( $auth_header, 7 );

        $parts = explode( '.', $token );
        if ( 3 !== count( $parts ) ) {
            return Response::error( 'INVALID_TOKEN', 'Malformed JWT.', 401 );
        }

        [ $header_b64, $payload_b64, $sig_b64 ] = $parts;

        // Decode header — reject alg:none and anything except HS256.
        $header = json_decode( self::base64url_decode( $header_b64 ), true );
        if ( ! $header || ( $header['alg'] ?? '' ) !== 'HS256' ) {
            return Response::error( 'INVALID_TOKEN', 'Only HS256 algorithm is accepted.', 401 );
        }

        // Verify signature.
        $secret    = self::get_jwt_secret();
        $expected  = self::base64url_encode(
            hash_hmac( 'sha256', "{$header_b64}.{$payload_b64}", $secret, true )
        );

        if ( ! hash_equals( $expected, $sig_b64 ) ) {
            return Response::error( 'INVALID_TOKEN', 'JWT signature verification failed.', 401 );
        }

        // Decode payload.
        $payload = json_decode( self::base64url_decode( $payload_b64 ), true );
        if ( ! $payload ) {
            return Response::error( 'INVALID_TOKEN', 'JWT payload unreadable.', 401 );
        }

        // Check expiry.
        if ( isset( $payload['exp'] ) && $payload['exp'] < time() ) {
            return Response::error( 'TOKEN_EXPIRED', 'JWT has expired.', 401 );
        }

        // Set user context.
        $user_id = (int) ( $payload['sub'] ?? 0 );
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            return Response::error( 'INVALID_TOKEN', 'JWT subject is not a valid user.', 401 );
        }

        wp_set_current_user( $user_id );

        // Store scope on request for per-tool checks.
        $request->set_param( '_jwt_scope', $payload['scope'] ?? '*' );

        return null;
    }

    /**
     * Issue a JWT for a user.
     *
     * @param int      $user_id User ID.
     * @param string   $label   Human label.
     * @param array|string $scope Tool names or "*".
     * @param int      $ttl     Seconds until expiry.
     * @return string The JWT.
     */
    public static function issue_jwt( int $user_id, string $label = '', array|string $scope = '*', int $ttl = 86400 ): string {
        $secret = self::get_jwt_secret();
        $now    = time();

        $header  = self::base64url_encode( wp_json_encode( [ 'alg' => 'HS256', 'typ' => 'JWT' ] ) );
        $payload = self::base64url_encode( wp_json_encode( [
            'sub'   => $user_id,
            'iat'   => $now,
            'exp'   => $now + $ttl,
            'jti'   => wp_generate_uuid4(),
            'scope' => $scope,
            'label' => $label,
        ] ) );

        $sig = self::base64url_encode(
            hash_hmac( 'sha256', "{$header}.{$payload}", $secret, true )
        );

        return "{$header}.{$payload}.{$sig}";
    }

    /**
     * Get or create the JWT secret.
     */
    public static function get_jwt_secret(): string {
        $secret = get_option( self::JWT_SECRET );
        if ( ! $secret ) {
            $secret = bin2hex( random_bytes( 32 ) ); // 64 hex chars.
            update_option( self::JWT_SECRET, $secret, false );
        }
        return $secret;
    }

    /**
     * Rotate JWT secret — invalidates all issued tokens.
     */
    public static function rotate_jwt_secret(): string {
        $secret = bin2hex( random_bytes( 32 ) );
        update_option( self::JWT_SECRET, $secret, false );
        return $secret;
    }

    // ── Base64url helpers ───────────────────────────────────────────────

    private static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private static function base64url_decode( string $data ): string {
        return base64_decode( strtr( $data, '-_', '+/' ), true ) ?: '';
    }
}
