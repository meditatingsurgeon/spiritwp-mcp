<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;
use SpiritWP_MCP\Confirm_Token;

/**
 * Unified content controller for posts, pages, and all CPTs.
 *
 * Unlike WP REST which has separate /posts, /pages, /cpt endpoints,
 * this uses a single /content surface with a post_type parameter.
 * This is the task-shaped approach: "manage content" not "manage posts."
 */
final class Content_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'content';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/content', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'list', 'edit_posts', [ $this, 'list_content' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/content/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'get', 'edit_posts', [ $this, 'get_content' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/content', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'create', 'edit_posts', [ $this, 'create_content' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/content/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => $this->wrap( 'update', 'edit_posts', [ $this, 'update_content' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/content/(?P<id>\d+)/trash', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'trash', 'delete_posts', [ $this, 'trash_content' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/content/(?P<id>\d+)/restore', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'restore', 'delete_posts', [ $this, 'restore_content' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/content/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => $this->wrap( 'delete', 'delete_posts', [ $this, 'delete_content' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function list_content( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'post_type'      => Sanitize::post_type( $request->get_param( 'post_type' ) ?: 'post' ),
            'post_status'    => Sanitize::key( $request->get_param( 'status' ) ?: 'publish' ),
            'posts_per_page' => min( 100, Sanitize::int( $request->get_param( 'per_page' ) ?: 20 ) ),
            'paged'          => max( 1, Sanitize::int( $request->get_param( 'page' ) ?: 1 ) ),
            'orderby'        => Sanitize::key( $request->get_param( 'orderby' ) ?: 'date' ),
            'order'          => strtoupper( Sanitize::key( $request->get_param( 'order' ) ?: 'DESC' ) ),
        ];

        $search = Sanitize::text( $request->get_param( 's' ) ?? '' );
        if ( $search ) {
            $args['s'] = $search;
        }

        $taxonomy = Sanitize::key( $request->get_param( 'taxonomy' ) ?? '' );
        $term     = Sanitize::text( $request->get_param( 'term' ) ?? '' );
        if ( $taxonomy && $term ) {
            $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => $taxonomy,
                    'field'    => is_numeric( $term ) ? 'term_id' : 'slug',
                    'terms'    => $term,
                ],
            ];
        }

        $query = new \WP_Query( $args );

        $items = array_map( [ $this, 'format_post' ], $query->posts );

        return Response::ok( [
            'items'       => $items,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
            'page'        => $args['paged'],
        ] );
    }

    public function get_content( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = Sanitize::int( $request->get_param( 'id' ) );
        $post = get_post( $id );

        if ( ! $post ) {
            return Response::error( 'NOT_FOUND', 'Content not found.', 404 );
        }

        $cap_check = $this->check_post_cap( 'edit_post', $id );
        if ( $cap_check ) {
            return $cap_check;
        }

        return Response::ok( $this->format_post( $post, true ) );
    }

    public function create_content( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();

        $post_data = [
            'post_title'   => Sanitize::text( $params['title'] ?? '' ),
            'post_content' => Sanitize::html( $params['content'] ?? '' ),
            'post_excerpt' => Sanitize::html( $params['excerpt'] ?? '' ),
            'post_status'  => Sanitize::key( $params['status'] ?? 'draft' ),
            'post_type'    => Sanitize::post_type( $params['post_type'] ?? 'post' ),
            'post_name'    => Sanitize::slug( $params['slug'] ?? '' ),
        ];

        if ( isset( $params['post_parent'] ) ) {
            $post_data['post_parent'] = Sanitize::int( $params['post_parent'] );
        }

        $result = wp_insert_post( $post_data, true );

        if ( is_wp_error( $result ) ) {
            return Response::from_wp_error( $result );
        }

        // Set taxonomies if provided.
        if ( ! empty( $params['taxonomies'] ) && is_array( $params['taxonomies'] ) ) {
            foreach ( $params['taxonomies'] as $tax => $terms ) {
                wp_set_object_terms( $result, $terms, Sanitize::key( $tax ) );
            }
        }

        return Response::ok( $this->format_post( get_post( $result ), true ), 201 );
    }

    public function update_content( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = Sanitize::int( $request->get_param( 'id' ) );
        $post = get_post( $id );

        if ( ! $post ) {
            return Response::error( 'NOT_FOUND', 'Content not found.', 404 );
        }

        $cap_check = $this->check_post_cap( 'edit_post', $id );
        if ( $cap_check ) {
            return $cap_check;
        }

        $params    = $request->get_json_params();
        $post_data = [ 'ID' => $id ];

        if ( isset( $params['title'] ) ) {
            $post_data['post_title'] = Sanitize::text( $params['title'] );
        }
        if ( isset( $params['content'] ) ) {
            $post_data['post_content'] = Sanitize::html( $params['content'] );
        }
        if ( isset( $params['excerpt'] ) ) {
            $post_data['post_excerpt'] = Sanitize::html( $params['excerpt'] );
        }
        if ( isset( $params['status'] ) ) {
            $post_data['post_status'] = Sanitize::key( $params['status'] );
        }
        if ( isset( $params['slug'] ) ) {
            $post_data['post_name'] = Sanitize::slug( $params['slug'] );
        }

        $result = wp_update_post( $post_data, true );

        if ( is_wp_error( $result ) ) {
            return Response::from_wp_error( $result );
        }

        // Update taxonomies if provided.
        if ( ! empty( $params['taxonomies'] ) && is_array( $params['taxonomies'] ) ) {
            foreach ( $params['taxonomies'] as $tax => $terms ) {
                wp_set_object_terms( $id, $terms, Sanitize::key( $tax ) );
            }
        }

        return Response::ok( $this->format_post( get_post( $id ), true ) );
    }

    public function trash_content( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = Sanitize::int( $request->get_param( 'id' ) );
        $post = get_post( $id );

        if ( ! $post ) {
            return Response::error( 'NOT_FOUND', 'Content not found.', 404 );
        }

        $cap_check = $this->check_post_cap( 'delete_post', $id );
        if ( $cap_check ) {
            return $cap_check;
        }

        $result = wp_trash_post( $id );
        if ( ! $result ) {
            return Response::error( 'TRASH_FAILED', 'Could not trash content.', 500 );
        }

        return Response::ok( [ 'id' => $id, 'status' => 'trash' ] );
    }

    public function restore_content( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = Sanitize::int( $request->get_param( 'id' ) );
        $post = get_post( $id );

        if ( ! $post ) {
            return Response::error( 'NOT_FOUND', 'Content not found.', 404 );
        }

        $cap_check = $this->check_post_cap( 'delete_post', $id );
        if ( $cap_check ) {
            return $cap_check;
        }

        $result = wp_untrash_post( $id );
        if ( ! $result ) {
            return Response::error( 'RESTORE_FAILED', 'Could not restore content.', 500 );
        }

        return Response::ok( $this->format_post( get_post( $id ) ) );
    }

    public function delete_content( \WP_REST_Request $request ): \WP_REST_Response {
        $id = Sanitize::int( $request->get_param( 'id' ) );

        $cap_check = $this->check_post_cap( 'delete_post', $id );
        if ( $cap_check ) {
            return $cap_check;
        }

        // Hard delete requires confirm token.
        $confirm = Confirm_Token::guard(
            'content.delete',
            [ 'id' => $id ],
            $request->get_param( 'confirm_token' )
        );
        if ( $confirm ) {
            return $confirm;
        }

        $result = wp_delete_post( $id, true );
        if ( ! $result ) {
            return Response::error( 'DELETE_FAILED', 'Could not delete content.', 500 );
        }

        return Response::ok( [ 'id' => $id, 'deleted' => true ] );
    }

    /**
     * Format a WP_Post into a clean array.
     */
    private function format_post( \WP_Post $post, bool $include_content = false ): array {
        $data = [
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'slug'        => $post->post_name,
            'status'      => $post->post_status,
            'post_type'   => $post->post_type,
            'date'        => $post->post_date,
            'modified'    => $post->post_modified,
            'author'      => (int) $post->post_author,
            'parent'      => (int) $post->post_parent,
            'menu_order'  => (int) $post->menu_order,
            'permalink'   => get_permalink( $post ),
        ];

        if ( $include_content ) {
            $data['content']  = $post->post_content;
            $data['excerpt']  = $post->post_excerpt;
            $data['template'] = get_page_template_slug( $post ) ?: '';
        }

        return $data;
    }
}
