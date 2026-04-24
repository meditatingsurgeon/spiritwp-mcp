<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;

final class Taxonomies_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'taxonomies';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/taxonomies', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'list', 'edit_posts', [ $this, 'list_taxonomies' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/taxonomies/(?P<taxonomy>[a-z0-9_-]+)/terms', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'terms', 'edit_posts', [ $this, 'list_terms' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/taxonomies/(?P<taxonomy>[a-z0-9_-]+)/terms', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'create_term', 'manage_categories', [ $this, 'create_term' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function list_taxonomies( \WP_REST_Request $request ): \WP_REST_Response {
        $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        $items = [];

        foreach ( $taxonomies as $tax ) {
            $items[] = [
                'name'        => $tax->name,
                'label'       => $tax->label,
                'hierarchical' => $tax->hierarchical,
                'post_types'  => $tax->object_type,
                'count'       => wp_count_terms( [ 'taxonomy' => $tax->name ] ),
            ];
        }

        return Response::ok( $items );
    }

    public function list_terms( \WP_REST_Request $request ): \WP_REST_Response {
        $taxonomy = Sanitize::key( $request->get_param( 'taxonomy' ) );

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return Response::error( 'NOT_FOUND', "Taxonomy '{$taxonomy}' not found.", 404 );
        }

        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => min( 200, Sanitize::int( $request->get_param( 'per_page' ) ?: 100 ) ),
        ] );

        if ( is_wp_error( $terms ) ) {
            return Response::from_wp_error( $terms );
        }

        $items = array_map( static fn( $t ) => [
            'term_id' => $t->term_id,
            'name'    => $t->name,
            'slug'    => $t->slug,
            'parent'  => $t->parent,
            'count'   => $t->count,
        ], $terms );

        return Response::ok( $items );
    }

    public function create_term( \WP_REST_Request $request ): \WP_REST_Response {
        $taxonomy = Sanitize::key( $request->get_param( 'taxonomy' ) );
        $params   = $request->get_json_params();

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return Response::error( 'NOT_FOUND', "Taxonomy '{$taxonomy}' not found.", 404 );
        }

        $result = wp_insert_term(
            Sanitize::text( $params['name'] ?? '' ),
            $taxonomy,
            [
                'slug'        => Sanitize::slug( $params['slug'] ?? '' ),
                'parent'      => Sanitize::int( $params['parent'] ?? 0 ),
                'description' => Sanitize::text( $params['description'] ?? '' ),
            ]
        );

        if ( is_wp_error( $result ) ) {
            return Response::from_wp_error( $result );
        }

        return Response::ok( [
            'term_id'  => $result['term_id'],
            'taxonomy' => $taxonomy,
        ], 201 );
    }
}
