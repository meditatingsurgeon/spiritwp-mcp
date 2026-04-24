<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;
use SpiritWP_MCP\Feature_Flags;
use SpiritWP_MCP\Confirm_Token;

/**
 * Raw SQL execution controller.
 *
 * SELECT: always available (manage_options capability required).
 * UPDATE/INSERT/DELETE: feature flag + confirm token.
 * DROP/TRUNCATE/ALTER: always rejected.
 */
final class Exec_SQL_Controller extends REST_Controller {

    private const BLOCKED_KEYWORDS = [ 'DROP', 'TRUNCATE', 'ALTER', 'CREATE', 'GRANT', 'REVOKE' ];
    private const WRITE_KEYWORDS   = [ 'UPDATE', 'INSERT', 'DELETE', 'REPLACE' ];

    protected function tool_category(): string {
        return 'exec-sql';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/exec-sql/query', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'query', 'manage_options', [ $this, 'query' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/exec-sql/tables', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'tables', 'manage_options', [ $this, 'tables' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function query( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $params = $request->get_json_params();
        $sql    = trim( $params['query'] ?? '' );

        if ( ! $sql ) {
            return Response::error( 'INVALID_INPUT', 'Provide a "query" string.', 400 );
        }

        // Strip leading comments and whitespace to find the first keyword.
        $stripped = preg_replace( '/^(\s*(?:--[^\n]*\n|\/\*.*?\*\/)\s*)+/s', '', $sql );
        $keyword  = strtoupper( strtok( $stripped, " \t\n\r" ) );

        // Block dangerous keywords outright.
        if ( in_array( $keyword, self::BLOCKED_KEYWORDS, true ) ) {
            return Response::error(
                'SQL_BLOCKED',
                sprintf( '%s statements are never permitted.', $keyword ),
                403
            );
        }

        // Write statements need feature flag + confirm token.
        if ( in_array( $keyword, self::WRITE_KEYWORDS, true ) ) {
            $flag_check = Feature_Flags::guard( 'enable_exec_sql_raw' );
            if ( $flag_check ) {
                return $flag_check;
            }

            $confirm = Confirm_Token::guard(
                'exec-sql.query',
                [ 'query' => $sql ],
                $params['confirm_token'] ?? null
            );
            if ( $confirm ) {
                return $confirm;
            }
        }

        // Execute.
        if ( 'SELECT' === $keyword || 'SHOW' === $keyword || 'DESCRIBE' === $keyword || 'EXPLAIN' === $keyword ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $results = $wpdb->get_results( $sql, ARRAY_A );

            if ( $wpdb->last_error ) {
                return Response::error( 'SQL_ERROR', $wpdb->last_error, 400 );
            }

            return Response::ok( [
                'rows'         => $results,
                'rows_found'   => count( $results ),
            ] );
        }

        // Write query.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $affected = $wpdb->query( $sql );

        if ( false === $affected ) {
            return Response::error( 'SQL_ERROR', $wpdb->last_error, 400 );
        }

        return Response::ok( [
            'affected_rows' => $affected,
            'insert_id'     => $wpdb->insert_id,
        ] );
    }

    public function tables( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $tables = $wpdb->get_col( 'SHOW TABLES' );

        return Response::ok( [
            'tables' => $tables,
            'prefix' => $wpdb->prefix,
        ] );
    }
}
