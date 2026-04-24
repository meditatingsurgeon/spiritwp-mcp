<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;

/**
 * Block patterns controller.
 */
final class Patterns_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'patterns';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/patterns', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'list', 'edit_posts', [ $this, 'list_patterns' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/patterns/categories', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'categories', 'edit_posts', [ $this, 'list_categories' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function list_patterns( \WP_REST_Request $request ): \WP_REST_Response {
        $registry = \WP_Block_Patterns_Registry::get_instance();
        $patterns = $registry->get_all_registered();

        $category = Sanitize::key( $request->get_param( 'category' ) ?? '' );
        if ( $category ) {
            $patterns = array_filter( $patterns, static fn( $p ) =>
                in_array( $category, $p['categories'] ?? [], true )
            );
        }

        $items = array_map( static fn( $p ) => [
            'name'       => $p['name'],
            'title'      => $p['title'],
            'categories' => $p['categories'] ?? [],
            'content'    => $p['content'] ?? '',
        ], array_values( $patterns ) );

        return Response::ok( $items );
    }

    public function list_categories( \WP_REST_Request $request ): \WP_REST_Response {
        $registry   = \WP_Block_Pattern_Categories_Registry::get_instance();
        $categories = $registry->get_all_registered();

        return Response::ok( array_map( static fn( $c ) => [
            'name'  => $c['name'],
            'label' => $c['label'],
        ], $categories ) );
    }
}
