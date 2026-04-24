<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;

final class Users_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'users';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/users', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'list', 'list_users', [ $this, 'list_users' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/users/me', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'me', 'read', [ $this, 'me' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/users/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'get', 'list_users', [ $this, 'get_user' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function list_users( \WP_REST_Request $request ): \WP_REST_Response {
        $args  = [
            'number'  => min( 100, Sanitize::int( $request->get_param( 'per_page' ) ?: 20 ) ),
            'paged'   => max( 1, Sanitize::int( $request->get_param( 'page' ) ?: 1 ) ),
            'orderby' => Sanitize::key( $request->get_param( 'orderby' ) ?: 'display_name' ),
            'order'   => strtoupper( Sanitize::key( $request->get_param( 'order' ) ?: 'ASC' ) ),
        ];

        $role = Sanitize::key( $request->get_param( 'role' ) ?? '' );
        if ( $role ) {
            $args['role'] = $role;
        }

        $query = new \WP_User_Query( $args );

        $items = array_map( [ $this, 'format_user' ], $query->get_results() );

        return Response::ok( [
            'items' => $items,
            'total' => $query->get_total(),
        ] );
    }

    public function me( \WP_REST_Request $request ): \WP_REST_Response {
        $user = wp_get_current_user();

        if ( ! $user->exists() ) {
            return Response::error( 'NOT_AUTHENTICATED', 'No user context.', 401 );
        }

        return Response::ok( $this->format_user( $user, true ) );
    }

    public function get_user( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = Sanitize::int( $request->get_param( 'id' ) );
        $user = get_userdata( $id );

        if ( ! $user ) {
            return Response::error( 'NOT_FOUND', 'User not found.', 404 );
        }

        return Response::ok( $this->format_user( $user, true ) );
    }

    private function format_user( \WP_User $user, bool $detailed = false ): array {
        $data = [
            'id'           => $user->ID,
            'login'        => $user->user_login,
            'display_name' => $user->display_name,
            'email'        => $user->user_email,
            'roles'        => $user->roles,
            'registered'   => $user->user_registered,
        ];

        if ( $detailed ) {
            $data['url']         = $user->user_url;
            $data['description'] = $user->description;
            $data['capabilities'] = array_keys( array_filter( $user->allcaps ) );
        }

        return $data;
    }
}
