<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;

/**
 * Widgets and sidebars controller.
 */
final class Widgets_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'widgets';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/widgets/sidebars', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'sidebars', 'edit_theme_options', [ $this, 'list_sidebars' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/widgets', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'list', 'edit_theme_options', [ $this, 'list_widgets' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function list_sidebars( \WP_REST_Request $request ): \WP_REST_Response {
        global $wp_registered_sidebars;
        $sidebars = [];
        foreach ( $wp_registered_sidebars as $id => $sidebar ) {
            $sidebars[] = [
                'id'          => $id,
                'name'        => $sidebar['name'],
                'description' => $sidebar['description'] ?? '',
            ];
        }
        return Response::ok( $sidebars );
    }

    public function list_widgets( \WP_REST_Request $request ): \WP_REST_Response {
        global $wp_registered_widgets;
        $widgets = [];
        foreach ( $wp_registered_widgets as $id => $widget ) {
            $widgets[] = [
                'id'   => $id,
                'name' => $widget['name'] ?? '',
            ];
        }
        return Response::ok( $widgets );
    }
}
