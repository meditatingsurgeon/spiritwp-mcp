<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;

final class Nav_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'nav';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/nav/menus', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'list', 'edit_theme_options', [ $this, 'list_menus' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/nav/menus/(?P<id>\d+)/items', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'items', 'edit_theme_options', [ $this, 'get_menu_items' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/nav/locations', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'locations', 'edit_theme_options', [ $this, 'get_locations' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function list_menus( \WP_REST_Request $request ): \WP_REST_Response {
        $menus = wp_get_nav_menus();
        $items = array_map( static fn( $m ) => [
            'term_id' => $m->term_id,
            'name'    => $m->name,
            'slug'    => $m->slug,
            'count'   => $m->count,
        ], $menus );

        return Response::ok( $items );
    }

    public function get_menu_items( \WP_REST_Request $request ): \WP_REST_Response {
        $id    = Sanitize::int( $request->get_param( 'id' ) );
        $items = wp_get_nav_menu_items( $id );

        if ( false === $items ) {
            return Response::error( 'NOT_FOUND', 'Menu not found.', 404 );
        }

        $formatted = array_map( static fn( $item ) => [
            'id'        => $item->ID,
            'title'     => $item->title,
            'url'       => $item->url,
            'type'      => $item->type,
            'object'    => $item->object,
            'object_id' => (int) $item->object_id,
            'parent'    => (int) $item->menu_item_parent,
            'order'     => (int) $item->menu_order,
            'classes'   => $item->classes,
        ], $items );

        return Response::ok( $formatted );
    }

    public function get_locations( \WP_REST_Request $request ): \WP_REST_Response {
        $registered = get_registered_nav_menus();
        $assigned   = get_nav_menu_locations();

        $locations = [];
        foreach ( $registered as $slug => $name ) {
            $locations[] = [
                'slug'    => $slug,
                'name'    => $name,
                'menu_id' => $assigned[ $slug ] ?? null,
            ];
        }

        return Response::ok( $locations );
    }
}
