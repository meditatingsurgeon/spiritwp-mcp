<?php

namespace SpiritWP_MCP\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * License manager — v0.1 stub.
 *
 * Always returns status "active". Real licensing (Polar.sh + Lemon Squeezy)
 * ships in v1.1.0.
 */
final class License_Manager {

    /**
     * Get current license state.
     *
     * @return array{status: string, expires_at: ?int, last_checked_at: int, last_error: ?string, details: ?array}
     */
    public static function get_state(): array {
        return [
            'status'          => 'active',
            'expires_at'      => null, // Lifetime for v0.1 stub.
            'last_checked_at' => time(),
            'last_error'      => null,
            'details'         => [ 'provider' => 'stub', 'version' => '0.1.0' ],
        ];
    }

    /**
     * Activate — no-op in stub.
     */
    public static function activate( string $key, string $instance_id ): array {
        return self::get_state();
    }

    /**
     * Validate — no-op in stub.
     */
    public static function validate( string $key, string $instance_id ): array {
        return self::get_state();
    }

    /**
     * Deactivate — no-op in stub.
     */
    public static function deactivate( string $key, string $instance_id ): bool {
        return true;
    }
}
