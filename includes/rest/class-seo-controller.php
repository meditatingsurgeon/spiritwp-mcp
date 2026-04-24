<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;

/**
 * SEO controller — adapter pattern for SEOPress, Yoast, Rank Math.
 * v0.1 ships with SEOPress support (SA's stack). Others added by demand.
 */
final class SEO_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'seo';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/seo/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'get', 'edit_posts', [ $this, 'get_seo' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/seo/(?P<post_id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => $this->wrap( 'set', 'edit_posts', [ $this, 'set_seo' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/seo/provider', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'provider', 'edit_posts', [ $this, 'detect_provider' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function get_seo( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = Sanitize::int( $request->get_param( 'post_id' ) );

        if ( ! get_post( $post_id ) ) {
            return Response::error( 'NOT_FOUND', 'Post not found.', 404 );
        }

        $provider = $this->get_provider();

        return Response::ok( [
            'post_id'  => $post_id,
            'provider' => $provider,
            'meta'     => $this->read_seo_meta( $post_id, $provider ),
        ] );
    }

    public function set_seo( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = Sanitize::int( $request->get_param( 'post_id' ) );
        $params  = $request->get_json_params();

        if ( ! get_post( $post_id ) ) {
            return Response::error( 'NOT_FOUND', 'Post not found.', 404 );
        }

        $cap_check = $this->check_post_cap( 'edit_post', $post_id );
        if ( $cap_check ) {
            return $cap_check;
        }

        $provider = $this->get_provider();
        $meta     = $params['meta'] ?? [];

        $updated = $this->write_seo_meta( $post_id, $provider, $meta );

        return Response::ok( [
            'post_id'  => $post_id,
            'provider' => $provider,
            'updated'  => $updated,
        ] );
    }

    public function detect_provider( \WP_REST_Request $request ): \WP_REST_Response {
        return Response::ok( [ 'provider' => $this->get_provider() ] );
    }

    private function get_provider(): string {
        if ( defined( 'SEOPRESS_VERSION' ) ) {
            return 'seopress';
        }
        if ( defined( 'WPSEO_VERSION' ) ) {
            return 'yoast';
        }
        if ( class_exists( 'RankMath' ) ) {
            return 'rankmath';
        }
        return 'none';
    }

    private function read_seo_meta( int $post_id, string $provider ): array {
        return match ( $provider ) {
            'seopress' => [
                'title'            => get_post_meta( $post_id, '_seopress_titles_title', true ),
                'description'      => get_post_meta( $post_id, '_seopress_titles_desc', true ),
                'canonical'        => get_post_meta( $post_id, '_seopress_robots_canonical', true ),
                'noindex'          => get_post_meta( $post_id, '_seopress_robots_index', true ),
                'nofollow'         => get_post_meta( $post_id, '_seopress_robots_follow', true ),
                'og_title'         => get_post_meta( $post_id, '_seopress_social_fb_title', true ),
                'og_description'   => get_post_meta( $post_id, '_seopress_social_fb_desc', true ),
                'focus_keyword'    => get_post_meta( $post_id, '_seopress_analysis_target_kw', true ),
            ],
            'yoast' => [
                'title'       => get_post_meta( $post_id, '_yoast_wpseo_title', true ),
                'description' => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
                'canonical'   => get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
                'noindex'     => get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ),
            ],
            default => [],
        };
    }

    private function write_seo_meta( int $post_id, string $provider, array $meta ): int {
        $map = match ( $provider ) {
            'seopress' => [
                'title'         => '_seopress_titles_title',
                'description'   => '_seopress_titles_desc',
                'canonical'     => '_seopress_robots_canonical',
                'noindex'       => '_seopress_robots_index',
                'nofollow'      => '_seopress_robots_follow',
                'og_title'      => '_seopress_social_fb_title',
                'og_description' => '_seopress_social_fb_desc',
                'focus_keyword' => '_seopress_analysis_target_kw',
            ],
            'yoast' => [
                'title'       => '_yoast_wpseo_title',
                'description' => '_yoast_wpseo_metadesc',
                'canonical'   => '_yoast_wpseo_canonical',
                'noindex'     => '_yoast_wpseo_meta-robots-noindex',
            ],
            default => [],
        };

        $updated = 0;
        foreach ( $meta as $key => $value ) {
            if ( isset( $map[ $key ] ) ) {
                update_post_meta( $post_id, $map[ $key ], sanitize_text_field( $value ) );
                $updated++;
            }
        }

        return $updated;
    }
}
