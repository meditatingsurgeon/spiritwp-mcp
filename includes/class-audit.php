<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

/**
 * JSONL audit log at wp-content/uploads/spiritwp-mcp/audit.log.
 *
 * No argument or response bodies — hashes only.
 * Rotate at 10 MB; keep 5 generations.
 */
final class Audit {

    private const DIR_NAME       = 'spiritwp-mcp';
    private const FILE_NAME      = 'audit.log';
    private const MAX_SIZE       = 10 * 1024 * 1024; // 10 MB.
    private const MAX_ROTATIONS  = 5;

    /**
     * Log a request/response pair.
     */
    public static function log( array $entry ): void {
        $dir  = self::get_log_dir();
        $file = $dir . '/' . self::FILE_NAME;

        if ( ! wp_mkdir_p( $dir ) ) {
            return;
        }

        self::protect_directory( $dir );

        // Rotate if needed.
        if ( file_exists( $file ) && filesize( $file ) >= self::MAX_SIZE ) {
            self::rotate( $dir, $file );
        }

        $line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES ) . "\n";
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
    }

    /**
     * Build a log entry from a request context.
     */
    public static function entry(
        string $tool,
        string $route,
        string $method,
        int $status,
        string $auth_type,
        ?string $auth_ref,
        array $args,
        mixed $output,
        float $start_time
    ): array {
        return [
            'ts'              => time(),
            'mode'            => Mode::get(),
            'tool'            => $tool,
            'route'           => $route,
            'method'          => $method,
            'status'          => $status,
            'actor_user_id'   => get_current_user_id(),
            'auth_type'       => $auth_type,
            'auth_ref_prefix' => $auth_ref ? substr( $auth_ref, 0, 8 ) : null,
            'args_sha256'     => hash( 'sha256', wp_json_encode( $args ) ),
            'output_sha256'   => hash( 'sha256', wp_json_encode( $output ) ),
            'remote_addr'     => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
            'duration_ms'     => (int) ( ( microtime( true ) - $start_time ) * 1000 ),
        ];
    }

    /**
     * Get the log directory path.
     */
    public static function get_log_dir(): string {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/' . self::DIR_NAME;
    }

    /**
     * Get log file path.
     */
    public static function get_log_file(): string {
        return self::get_log_dir() . '/' . self::FILE_NAME;
    }

    /**
     * Get log size in bytes.
     */
    public static function get_size(): int {
        $file = self::get_log_file();
        return file_exists( $file ) ? (int) filesize( $file ) : 0;
    }

    /**
     * Clear the audit log.
     */
    public static function clear(): void {
        $file = self::get_log_file();
        if ( file_exists( $file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $file, '' );
        }
    }

    /**
     * Rotate log files.
     */
    private static function rotate( string $dir, string $file ): void {
        // Remove oldest.
        $oldest = $file . '.' . self::MAX_ROTATIONS;
        if ( file_exists( $oldest ) ) {
            wp_delete_file( $oldest );
        }

        // Shift existing rotations.
        for ( $i = self::MAX_ROTATIONS - 1; $i >= 1; $i-- ) {
            $from = $file . '.' . $i;
            $to   = $file . '.' . ( $i + 1 );
            if ( file_exists( $from ) ) {
                rename( $from, $to );
            }
        }

        // Current → .1.
        rename( $file, $file . '.1' );
    }

    /**
     * Protect directory with .htaccess and index.php.
     */
    private static function protect_directory( string $dir ): void {
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $htaccess, "Deny from all\n" );
        }

        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
    }

    /**
     * Remove audit directory and all files (for uninstall).
     */
    public static function destroy(): void {
        $dir = self::get_log_dir();
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $files = glob( $dir . '/*' );
        if ( $files ) {
            foreach ( $files as $f ) {
                wp_delete_file( $f );
            }
        }
        rmdir( $dir );
    }
}
