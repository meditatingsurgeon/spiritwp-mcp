<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;

final class Media_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'media';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/media', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'list', 'upload_files', [ $this, 'list_media' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/media/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'get', 'upload_files', [ $this, 'get_media' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/media/upload-url', [
            'methods'             => 'POST',
            'callback'            => $this->wrap( 'upload_url', 'upload_files', [ $this, 'upload_from_url' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function list_media( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => min( 100, Sanitize::int( $request->get_param( 'per_page' ) ?: 20 ) ),
            'paged'          => max( 1, Sanitize::int( $request->get_param( 'page' ) ?: 1 ) ),
        ];

        $mime = Sanitize::text( $request->get_param( 'mime_type' ) ?? '' );
        if ( $mime ) {
            $args['post_mime_type'] = $mime;
        }

        $query = new \WP_Query( $args );
        $items = array_map( [ $this, 'format_attachment' ], $query->posts );

        return Response::ok( [
            'items'       => $items,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
        ] );
    }

    public function get_media( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = Sanitize::int( $request->get_param( 'id' ) );
        $post = get_post( $id );

        if ( ! $post || 'attachment' !== $post->post_type ) {
            return Response::error( 'NOT_FOUND', 'Media not found.', 404 );
        }

        return Response::ok( $this->format_attachment( $post, true ) );
    }

    public function upload_from_url( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();
        $url    = Sanitize::url( $params['url'] ?? '' );
        $title  = Sanitize::text( $params['title'] ?? '' );

        if ( ! $url ) {
            return Response::error( 'INVALID_INPUT', 'Provide a "url".', 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            return Response::from_wp_error( $tmp );
        }

        $file_array = [
            'name'     => $title ? sanitize_file_name( $title ) : basename( wp_parse_url( $url, PHP_URL_PATH ) ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, 0 );

        if ( is_wp_error( $attachment_id ) ) {
            wp_delete_file( $tmp );
            return Response::from_wp_error( $attachment_id );
        }

        return Response::ok( $this->format_attachment( get_post( $attachment_id ), true ), 201 );
    }

    private function format_attachment( \WP_Post $post, bool $detailed = false ): array {
        $data = [
            'id'        => $post->ID,
            'title'     => $post->post_title,
            'url'       => wp_get_attachment_url( $post->ID ),
            'mime_type' => $post->post_mime_type,
            'date'      => $post->post_date,
        ];

        if ( $detailed ) {
            $meta = wp_get_attachment_metadata( $post->ID );
            $data['alt']       = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
            $data['caption']   = $post->post_excerpt;
            $data['width']     = $meta['width'] ?? null;
            $data['height']    = $meta['height'] ?? null;
            $data['file_size'] = $meta['filesize'] ?? null;
            $data['sizes']     = $meta['sizes'] ?? [];
        }

        return $data;
    }
}
