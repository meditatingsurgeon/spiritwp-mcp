<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;

/**
 * Forms controller — adapter for Forminator (SA stack).
 * v0.1 supports listing forms and submissions. Other plugins by demand.
 */
final class Forms_Controller extends REST_Controller {

    protected function tool_category(): string {
        return 'forms';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/forms', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'list', 'manage_options', [ $this, 'list_forms' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/forms/(?P<id>\d+)/submissions', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'submissions', 'manage_options', [ $this, 'get_submissions' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function list_forms( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! class_exists( 'Forminator_API' ) ) {
            return Response::ok( [ 'provider' => 'none', 'forms' => [] ] );
        }

        $forms = \Forminator_API::get_forms( null, 1, 50 );
        $items = array_map( static fn( $f ) => [
            'id'     => $f->id,
            'name'   => $f->name,
            'status' => $f->status,
        ], is_array( $forms ) ? $forms : [] );

        return Response::ok( [ 'provider' => 'forminator', 'forms' => $items ] );
    }

    public function get_submissions( \WP_REST_Request $request ): \WP_REST_Response {
        $id = Sanitize::int( $request->get_param( 'id' ) );

        if ( ! class_exists( 'Forminator_API' ) ) {
            return Response::error( 'PROVIDER_MISSING', 'Forminator not active.', 404 );
        }

        $entries = \Forminator_API::get_form_entries( $id );
        if ( is_wp_error( $entries ) ) {
            return Response::from_wp_error( $entries );
        }

        return Response::ok( [
            'form_id' => $id,
            'count'   => count( $entries ),
            'entries' => array_slice( $entries, 0, 50 ),
        ] );
    }
}
