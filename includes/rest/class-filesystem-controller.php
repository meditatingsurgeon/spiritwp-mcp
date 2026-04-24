<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;
use SpiritWP_MCP\Feature_Flags;
use SpiritWP_MCP\Confirm_Token;

/**
 * Filesystem controller.
 *
 * Read: always available. Write/delete: feature flag gated.
 * Paths are restricted to ABSPATH and wp-content.
 */
final class Filesystem_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'filesystem';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/filesystem/read', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'read', 'manage_options', [ $this, 'read_file' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/filesystem/write', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'write', 'manage_options', [ $this, 'write_file' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/filesystem/delete', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'delete', 'manage_options', [ $this, 'delete_file' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/filesystem/list', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'list', 'manage_options', [ $this, 'list_files' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function read_file( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();
        $path   = $this->resolve_path( $params['path'] ?? '' );

        if ( is_wp_error( $path ) ) {
            return Response::from_wp_error( $path, 403 );
        }

        if ( ! file_exists( $path ) ) {
            return Response::error( 'NOT_FOUND', 'File not found.', 404 );
        }

        if ( is_dir( $path ) ) {
            return Response::error( 'IS_DIRECTORY', 'Path is a directory. Use filesystem/list.', 400 );
        }

        $size = filesize( $path );
        if ( $size > 5 * 1024 * 1024 ) {
            return Response::error( 'FILE_TOO_LARGE', 'File exceeds 5 MB limit.', 413 );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = file_get_contents( $path );

        return Response::ok( [
            'path'    => $path,
            'size'    => $size,
            'content' => $content,
            'mtime'   => filemtime( $path ),
        ] );
    }

    public function write_file( \WP_REST_Request $request ): \WP_REST_Response {
        $flag_check = Feature_Flags::guard( 'enable_filesystem_write' );
        if ( $flag_check ) {
            return $flag_check;
        }

        $params  = $request->get_json_params();
        $path    = $this->resolve_path( $params['path'] ?? '' );
        $content = $params['content'] ?? '';

        if ( is_wp_error( $path ) ) {
            return Response::from_wp_error( $path, 403 );
        }

        // Ensure parent directory exists.
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $mode   = ( $params['append'] ?? false ) ? FILE_APPEND : 0;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $result = file_put_contents( $path, $content, $mode | LOCK_EX );

        if ( false === $result ) {
            return Response::error( 'WRITE_FAILED', 'Could not write to file.', 500 );
        }

        return Response::ok( [
            'path'    => $path,
            'bytes'   => $result,
            'appended' => ! empty( $params['append'] ),
        ] );
    }

    public function delete_file( \WP_REST_Request $request ): \WP_REST_Response {
        $flag_check = Feature_Flags::guard( 'enable_filesystem_write' );
        if ( $flag_check ) {
            return $flag_check;
        }

        $params = $request->get_json_params();
        $path   = $this->resolve_path( $params['path'] ?? '' );

        if ( is_wp_error( $path ) ) {
            return Response::from_wp_error( $path, 403 );
        }

        if ( ! file_exists( $path ) ) {
            return Response::error( 'NOT_FOUND', 'File not found.', 404 );
        }

        // Confirm token for file deletion.
        $confirm = Confirm_Token::guard(
            'filesystem.delete',
            [ 'path' => $path ],
            $params['confirm_token'] ?? null
        );
        if ( $confirm ) {
            return $confirm;
        }

        $deleted = wp_delete_file_from_directory( $path, dirname( $path ) );

        return Response::ok( [
            'path'    => $path,
            'deleted' => $deleted,
        ] );
    }

    public function list_files( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();
        $path   = $this->resolve_path( $params['path'] ?? '' );

        if ( is_wp_error( $path ) ) {
            return Response::from_wp_error( $path, 403 );
        }

        if ( ! is_dir( $path ) ) {
            return Response::error( 'NOT_DIRECTORY', 'Path is not a directory.', 400 );
        }

        $depth = min( 3, max( 1, (int) ( $params['depth'] ?? 1 ) ) );
        $items = $this->scan_dir( $path, $depth );

        return Response::ok( [
            'path'  => $path,
            'items' => $items,
        ] );
    }

    /**
     * Resolve and validate a path — must be within ABSPATH.
     *
     * @return string|\WP_Error
     */
    private function resolve_path( string $raw ): string|\WP_Error {
        if ( ! $raw ) {
            return new \WP_Error( 'path_empty', 'Path cannot be empty.' );
        }

        $resolved = realpath( $raw ) ?: $raw;

        // Must be under ABSPATH or wp-content.
        $abspath = rtrim( ABSPATH, '/' );
        if ( ! str_starts_with( $resolved, $abspath ) ) {
            return new \WP_Error( 'path_outside_root', 'Path must be within the WordPress installation.' );
        }

        return $resolved;
    }

    /**
     * Recursively scan a directory.
     */
    private function scan_dir( string $dir, int $depth, int $current = 0 ): array {
        $items = [];
        $entries = scandir( $dir );

        foreach ( $entries as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }

            $full = $dir . '/' . $entry;
            $item = [
                'name' => $entry,
                'type' => is_dir( $full ) ? 'dir' : 'file',
                'size' => is_file( $full ) ? filesize( $full ) : null,
            ];

            if ( 'dir' === $item['type'] && $current < $depth - 1 ) {
                $item['children'] = $this->scan_dir( $full, $depth, $current + 1 );
            }

            $items[] = $item;
        }

        return $items;
    }
}
