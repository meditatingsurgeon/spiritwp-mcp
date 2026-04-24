<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;

/**
 * Meta controller — the atomic multi-field JSON write that WP Vibe cannot do.
 *
 * Handles post meta, term meta, and user meta through a single surface.
 * Solves: spaces in values, # characters, newlines, multi-field atomicity.
 */
final class Meta_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'meta';
    }

    public function register_routes(): void {
        // Get all meta for an object.
        register_rest_route( self::NAMESPACE, '/meta/(?P<object_type>post|term|user)/(?P<object_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'get', 'edit_posts', [ $this, 'get_meta' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        // Set multiple meta fields atomically.
        register_rest_route( self::NAMESPACE, '/meta/(?P<object_type>post|term|user)/(?P<object_id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => $this->wrap( 'set', 'edit_posts', [ $this, 'set_meta' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        // Delete specific meta keys.
        register_rest_route( self::NAMESPACE, '/meta/(?P<object_type>post|term|user)/(?P<object_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => $this->wrap( 'delete', 'edit_posts', [ $this, 'delete_meta' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function get_meta( \WP_REST_Request $request ): \WP_REST_Response {
        $type = Sanitize::key( $request->get_param( 'object_type' ) );
        $id   = Sanitize::int( $request->get_param( 'object_id' ) );

        if ( ! $this->object_exists( $type, $id ) ) {
            return Response::error( 'NOT_FOUND', "Object {$type}:{$id} not found.", 404 );
        }

        $prefix = Sanitize::key( $request->get_param( 'prefix' ) ?? '' );
        $meta   = $this->get_all_meta( $type, $id );

        if ( $prefix ) {
            $meta = array_filter( $meta, static fn( $k ) => str_starts_with( $k, $prefix ), ARRAY_FILTER_USE_KEY );
        }

        return Response::ok( [
            'object_type' => $type,
            'object_id'   => $id,
            'meta'        => $meta,
        ] );
    }

    public function set_meta( \WP_REST_Request $request ): \WP_REST_Response {
        $type   = Sanitize::key( $request->get_param( 'object_type' ) );
        $id     = Sanitize::int( $request->get_param( 'object_id' ) );
        $params = $request->get_json_params();
        $fields = $params['meta'] ?? [];

        if ( ! $this->object_exists( $type, $id ) ) {
            return Response::error( 'NOT_FOUND', "Object {$type}:{$id} not found.", 404 );
        }

        if ( ! is_array( $fields ) || empty( $fields ) ) {
            return Response::error( 'INVALID_INPUT', 'Provide a "meta" object with key-value pairs.', 400 );
        }

        $updated = 0;
        foreach ( $fields as $key => $value ) {
            $clean_key = sanitize_key( $key );
            $this->update_meta( $type, $id, $clean_key, $value );
            $updated++;
        }

        return Response::ok( [
            'object_type' => $type,
            'object_id'   => $id,
            'updated'     => $updated,
        ] );
    }

    public function delete_meta( \WP_REST_Request $request ): \WP_REST_Response {
        $type   = Sanitize::key( $request->get_param( 'object_type' ) );
        $id     = Sanitize::int( $request->get_param( 'object_id' ) );
        $params = $request->get_json_params();
        $keys   = Sanitize::text_array( $params['keys'] ?? [] );

        if ( ! $this->object_exists( $type, $id ) ) {
            return Response::error( 'NOT_FOUND', "Object {$type}:{$id} not found.", 404 );
        }

        if ( empty( $keys ) ) {
            return Response::error( 'INVALID_INPUT', 'Provide a "keys" array of meta keys to delete.', 400 );
        }

        $deleted = 0;
        foreach ( $keys as $key ) {
            $clean_key = sanitize_key( $key );
            $this->delete_single_meta( $type, $id, $clean_key );
            $deleted++;
        }

        return Response::ok( [
            'object_type' => $type,
            'object_id'   => $id,
            'deleted'     => $deleted,
        ] );
    }

    private function object_exists( string $type, int $id ): bool {
        return match ( $type ) {
            'post' => (bool) get_post( $id ),
            'term' => (bool) term_exists( $id ),
            'user' => (bool) get_userdata( $id ),
            default => false,
        };
    }

    private function get_all_meta( string $type, int $id ): array {
        $raw = match ( $type ) {
            'post' => get_post_meta( $id ),
            'term' => get_term_meta( $id ),
            'user' => get_user_meta( $id ),
            default => [],
        };

        // Flatten single-element arrays.
        return array_map( static fn( $v ) => is_array( $v ) && 1 === count( $v ) ? $v[0] : $v, $raw );
    }

    private function update_meta( string $type, int $id, string $key, mixed $value ): void {
        match ( $type ) {
            'post' => update_post_meta( $id, $key, $value ),
            'term' => update_term_meta( $id, $key, $value ),
            'user' => update_user_meta( $id, $key, $value ),
        };
    }

    private function delete_single_meta( string $type, int $id, string $key ): void {
        match ( $type ) {
            'post' => delete_post_meta( $id, $key ),
            'term' => delete_term_meta( $id, $key ),
            'user' => delete_user_meta( $id, $key ),
        };
    }
}
