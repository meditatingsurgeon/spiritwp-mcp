<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the plugin's operating mode.
 *
 * Mode A (bridge):     Private. Localhost/RFC1918 only. X-Bridge-Key auth.
 * Mode B (standalone): Public MCP server. HTTPS + App Password / JWT auth.
 */
final class Mode {

    private const OPTION = 'spiritwp_mcp_mode';

    public static function get(): string {
        return get_option( self::OPTION, 'a' );
    }

    public static function is_a(): bool {
        return 'a' === self::get();
    }

    public static function is_b(): bool {
        return 'b' === self::get();
    }

    /**
     * Switch to Mode B — only after all health checks pass.
     *
     * @return true|\WP_Error
     */
    public static function switch_to_b(): true|\WP_Error {
        $checks = self::run_health_checks();
        $failed = array_filter( $checks, static fn( $c ) => ! $c['pass'] );

        if ( $failed ) {
            $labels = implode( ', ', array_column( $failed, 'label' ) );
            return new \WP_Error(
                'health_check_failed',
                sprintf( 'Mode B health checks failed: %s', $labels )
            );
        }

        update_option( self::OPTION, 'b' );
        return true;
    }

    /**
     * Switch to Mode A — no checks required.
     */
    public static function switch_to_a(): void {
        update_option( self::OPTION, 'a' );
    }

    /**
     * Health checks required before enabling Mode B.
     *
     * @return array<int, array{label: string, pass: bool, detail: string}>
     */
    public static function run_health_checks(): array {
        $checks = [];

        // 1. SSL.
        $checks[] = [
            'label'  => 'HTTPS',
            'pass'   => is_ssl(),
            'detail' => is_ssl() ? 'Site served over HTTPS.' : 'Mode B requires HTTPS.',
        ];

        // 2. Application Passwords available.
        $app_pass = wp_is_application_passwords_available();
        $checks[] = [
            'label'  => 'Application Passwords',
            'pass'   => $app_pass,
            'detail' => $app_pass ? 'Available.' : 'WP Application Passwords must be enabled.',
        ];

        // 3. Audit log directory writable.
        $audit_dir = Audit::get_log_dir();
        $writable  = wp_mkdir_p( $audit_dir ) && wp_is_writable( $audit_dir );
        $checks[] = [
            'label'  => 'Audit log writable',
            'pass'   => $writable,
            'detail' => $writable ? $audit_dir : 'Cannot write to audit directory.',
        ];

        // 4. License active (stub always passes in v0.1).
        $license = Licensing\License_Manager::get_state();
        $active  = 'active' === ( $license['status'] ?? '' );
        $checks[] = [
            'label'  => 'License',
            'pass'   => $active,
            'detail' => $active ? 'Active.' : 'License must be active.',
        ];

        return $checks;
    }
}
