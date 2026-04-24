<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;
use SpiritWP_MCP\Feature_Flags;
use SpiritWP_MCP\Confirm_Token;

/**
 * Search-replace controller — database-wide find and replace.
 * Dry-run first, then execute with confirm token.
 */
final class Search_Replace_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'search-replace';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/search-replace', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'execute', 'manage_options', [ $this, 'execute' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function execute( \WP_REST_Request $request ): \WP_REST_Response {
        $flag_check = Feature_Flags::guard( 'enable_exec_sql_raw' );
        if ( $flag_check ) {
            return $flag_check;
        }

        $params  = $request->get_json_params();
        $search  = $params['search'] ?? '';
        $replace = $params['replace'] ?? '';
        $dry_run = $params['dry_run'] ?? true;
        $tables  = $params['tables'] ?? null;

        if ( ! $search ) {
            return Response::error( 'INVALID_INPUT', 'Provide a "search" string.', 400 );
        }

        global $wpdb;

        // Determine tables.
        if ( ! $tables ) {
            $tables = $wpdb->get_col( 'SHOW TABLES' );
        } else {
            $tables = Sanitize::text_array( $tables );
        }

        if ( $dry_run ) {
            $preview = $this->dry_run( $search, $tables );
            return Response::ok( [
                'dry_run'   => true,
                'search'    => $search,
                'replace'   => $replace,
                'matches'   => $preview,
            ] );
        }

        // Real execution needs confirm token.
        $confirm = Confirm_Token::guard(
            'search-replace.execute',
            [ 'search' => $search, 'replace' => $replace ],
            $params['confirm_token'] ?? null
        );
        if ( $confirm ) {
            return $confirm;
        }

        $results = $this->do_replace( $search, $replace, $tables );

        return Response::ok( [
            'dry_run'  => false,
            'search'   => $search,
            'replace'  => $replace,
            'affected' => $results,
        ] );
    }

    private function dry_run( string $search, array $tables ): array {
        global $wpdb;
        $matches = [];

        foreach ( $tables as $table ) {
            $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM `%1$s`', $table ) );
            foreach ( $columns as $col ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` LIKE %s",
                    '%' . $wpdb->esc_like( $search ) . '%'
                ) );
                if ( $count > 0 ) {
                    $matches[] = [
                        'table'  => $table,
                        'column' => $col,
                        'count'  => $count,
                    ];
                }
            }
        }

        return $matches;
    }

    private function do_replace( string $search, string $replace, array $tables ): array {
        global $wpdb;
        $results = [];

        foreach ( $tables as $table ) {
            $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM `%1$s`', $table ) );
            foreach ( $columns as $col ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $affected = $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$table}` SET `{$col}` = REPLACE(`{$col}`, %s, %s) WHERE `{$col}` LIKE %s",
                    $search,
                    $replace,
                    '%' . $wpdb->esc_like( $search ) . '%'
                ) );
                if ( $affected > 0 ) {
                    $results[] = [
                        'table'    => $table,
                        'column'   => $col,
                        'affected' => $affected,
                    ];
                }
            }
        }

        return $results;
    }
}
