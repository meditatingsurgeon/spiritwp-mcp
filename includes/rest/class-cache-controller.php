<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;

/**
 * Cache controller — direct do_action calls to LiteSpeed, not REST.
 * This is where we beat WP Vibe: LiteSpeed /v3/purge returns 404 via CLI.
 */
final class Cache_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'cache';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/cache/purge', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'purge', 'manage_options', [ $this, 'purge' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/cache/status', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'status', 'manage_options', [ $this, 'status' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function purge( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();
        $target = Sanitize::key( $params['target'] ?? 'all' );

        $results = [];

        if ( in_array( $target, [ 'all', 'page' ], true ) ) {
            if ( has_action( 'litespeed_purge_all' ) ) {
                do_action( 'litespeed_purge_all' );
                $results[] = 'litespeed_page_cache';
            }
        }

        if ( in_array( $target, [ 'all', 'ccss' ], true ) ) {
            if ( has_action( 'litespeed_purge_cssjs' ) ) {
                do_action( 'litespeed_purge_cssjs' );
                $results[] = 'litespeed_ccss_ucss';
            }
        }

        if ( in_array( $target, [ 'all', 'object' ], true ) ) {
            $flushed = wp_cache_flush();
            if ( $flushed ) {
                $results[] = 'object_cache';
            }
        }

        if ( in_array( $target, [ 'all', 'transients' ], true ) ) {
            global $wpdb;
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );
            $results[] = 'transients';
        }

        // Single URL purge.
        $url = Sanitize::url( $params['url'] ?? '' );
        if ( $url && has_action( 'litespeed_purge_url' ) ) {
            do_action( 'litespeed_purge_url', $url );
            $results[] = 'url:' . $url;
        }

        return Response::ok( [
            'target'  => $target,
            'purged'  => $results,
        ] );
    }

    public function status( \WP_REST_Request $request ): \WP_REST_Response {
        return Response::ok( [
            'litespeed'    => class_exists( 'LiteSpeed\Core' ),
            'object_cache' => wp_using_ext_object_cache(),
            'redis'        => class_exists( 'Redis' ) || class_exists( 'Predis\Client' ),
        ] );
    }
}
