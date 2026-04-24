<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Feature flags gate whether a capability exists at all.
 *
 * Confirm-tokens operate INSIDE an enabled capability — they don't replace flags.
 * Each flag is an opt-in boolean. Default: all off.
 */
final class Feature_Flags {

    private const OPTION = 'spiritwp_mcp_flags';

    /**
     * Registered flags with labels and descriptions.
     */
    private const REGISTRY = [
        'enable_exec_php'        => [
            'label' => 'PHP Execution',
            'desc'  => 'Allow eval-style PHP execution via the exec-php tool. High risk.',
        ],
        'enable_exec_sql_raw'    => [
            'label' => 'Raw SQL Writes',
            'desc'  => 'Allow UPDATE / INSERT / DELETE queries via the exec-sql tool. SELECT is always allowed.',
        ],
        'enable_filesystem_write' => [
            'label' => 'Filesystem Writes',
            'desc'  => 'Allow writing and deleting files via the filesystem tool. Reads are always allowed.',
        ],
        'enable_cli_exec'        => [
            'label' => 'WP-CLI Execution',
            'desc'  => 'Allow running arbitrary WP-CLI commands. High risk.',
        ],
    ];

    /**
     * Get all flag states.
     *
     * @return array<string, bool>
     */
    public static function all(): array {
        $saved = get_option( self::OPTION, [] );
        $flags = [];
        foreach ( self::REGISTRY as $key => $_ ) {
            $flags[ $key ] = ! empty( $saved[ $key ] );
        }
        return $flags;
    }

    /**
     * Check if a specific flag is enabled.
     */
    public static function is_enabled( string $flag ): bool {
        $all = self::all();
        return $all[ $flag ] ?? false;
    }

    /**
     * Set a flag value.
     */
    public static function set( string $flag, bool $value ): bool {
        if ( ! isset( self::REGISTRY[ $flag ] ) ) {
            return false;
        }
        $saved          = get_option( self::OPTION, [] );
        $saved[ $flag ] = $value;
        return update_option( self::OPTION, $saved );
    }

    /**
     * Get the registry metadata for display in admin.
     *
     * @return array<string, array{label: string, desc: string, enabled: bool}>
     */
    public static function registry(): array {
        $flags  = self::all();
        $result = [];
        foreach ( self::REGISTRY as $key => $meta ) {
            $result[ $key ] = array_merge( $meta, [ 'enabled' => $flags[ $key ] ] );
        }
        return $result;
    }

    /**
     * Guard helper — returns WP_REST_Response error if flag is off.
     *
     * @param string $flag Flag name.
     * @return \WP_REST_Response|null Null if allowed, error response if blocked.
     */
    public static function guard( string $flag ): ?\WP_REST_Response {
        if ( self::is_enabled( $flag ) ) {
            return null;
        }
        $label = self::REGISTRY[ $flag ]['label'] ?? $flag;
        return Response::error(
            'FEATURE_DISABLED',
            sprintf( '%s is disabled. Enable it in Settings → SpiritWP MCP → Feature Flags.', $label ),
            451
        );
    }
}
