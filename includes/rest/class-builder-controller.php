<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;

/**
 * Builder controller — operations specific to page builders.
 *
 * Handles Blocksy page meta (which REST silently drops), GreenShift CSS
 * (which REST fails on canvas pages), and generic block content operations.
 * This is where we encode the hard-won SA lessons.
 */
final class Builder_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'builder';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/builder/blocksy-meta/(?P<post_id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => $this->wrap( 'blocksy_meta', 'edit_posts', [ $this, 'set_blocksy_meta' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/builder/greenshift-css', [
            'methods'             => 'PUT',
            'callback'            => $this->wrap( 'greenshift_css', 'manage_options', [ $this, 'update_greenshift_css' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/builder/additional-css', [
            'methods'             => [ 'GET', 'PUT' ],
            'callback'            => $this->wrap( 'additional_css', 'manage_options', [ $this, 'additional_css' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/builder/content-blocks', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'list_cb', 'edit_posts', [ $this, 'list_content_blocks' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/builder/template/(?P<post_id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => $this->wrap( 'set_template', 'edit_posts', [ $this, 'set_page_template' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    /**
     * Set Blocksy page meta — native update_post_meta that REST silently drops.
     */
    public function set_blocksy_meta( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = Sanitize::int( $request->get_param( 'post_id' ) );

        if ( ! get_post( $post_id ) ) {
            return Response::error( 'NOT_FOUND', 'Post not found.', 404 );
        }

        $cap_check = $this->check_post_cap( 'edit_post', $post_id );
        if ( $cap_check ) {
            return $cap_check;
        }

        $params = $request->get_json_params();
        $meta   = $params['meta'] ?? [];

        if ( ! is_array( $meta ) || empty( $meta ) ) {
            return Response::error( 'INVALID_INPUT', 'Provide a "meta" object.', 400 );
        }

        $updated = 0;
        foreach ( $meta as $key => $value ) {
            // Blocksy uses specific meta keys; allow them through.
            update_post_meta( $post_id, sanitize_key( $key ), $value );
            $updated++;
        }

        return Response::ok( [
            'post_id' => $post_id,
            'updated' => $updated,
        ] );
    }

    /**
     * Update GreenShift global custom CSS — bypasses the REST endpoint
     * that fails on canvas pages.
     */
    public function update_greenshift_css( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();
        $css    = $params['css'] ?? '';

        if ( ! is_string( $css ) ) {
            return Response::error( 'INVALID_INPUT', 'Provide "css" as a string.', 400 );
        }

        // Get current GreenShift settings.
        $settings = get_option( 'gspb_global_settings', '' );

        if ( is_string( $settings ) ) {
            $settings = maybe_unserialize( $settings );
        }

        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        $settings['custom_css'] = $css;
        update_option( 'gspb_global_settings', $settings );

        return Response::ok( [ 'css_length' => strlen( $css ) ] );
    }

    /**
     * Get or set WP Additional CSS (post ID varies per site).
     */
    public function additional_css( \WP_REST_Request $request ): \WP_REST_Response {
        // Find the custom_css post.
        $custom_css_post = wp_get_custom_css_post();

        if ( 'GET' === $request->get_method() ) {
            return Response::ok( [
                'post_id' => $custom_css_post ? $custom_css_post->ID : null,
                'css'     => wp_get_custom_css(),
            ] );
        }

        // PUT.
        $params = $request->get_json_params();
        $css    = $params['css'] ?? '';

        $result = wp_update_custom_css_post( $css );

        if ( is_wp_error( $result ) ) {
            return Response::from_wp_error( $result );
        }

        return Response::ok( [
            'post_id'    => $result->ID,
            'css_length' => strlen( $css ),
        ] );
    }

    /**
     * List Blocksy Content Blocks (ct_content_block CPT).
     */
    public function list_content_blocks( \WP_REST_Request $request ): \WP_REST_Response {
        $blocks = get_posts( [
            'post_type'      => 'ct_content_block',
            'post_status'    => [ 'publish', 'draft', 'trash' ],
            'posts_per_page' => 50,
        ] );

        $items = array_map( static fn( $p ) => [
            'id'     => $p->ID,
            'title'  => $p->post_title,
            'status' => $p->post_status,
        ], $blocks );

        return Response::ok( $items );
    }

    /**
     * Set page template — uses the exact string WP expects.
     */
    public function set_page_template( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id  = Sanitize::int( $request->get_param( 'post_id' ) );
        $params   = $request->get_json_params();
        $template = Sanitize::text( $params['template'] ?? '' );

        if ( ! get_post( $post_id ) ) {
            return Response::error( 'NOT_FOUND', 'Post not found.', 404 );
        }

        update_post_meta( $post_id, '_wp_page_template', $template );

        return Response::ok( [
            'post_id'  => $post_id,
            'template' => $template,
        ] );
    }
}
