<?php

namespace SpiritWP_MCP\Rest;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Response;
use SpiritWP_MCP\Sanitize;
use SpiritWP_MCP\Confirm_Token;

final class Options_Controller extends REST_Controller {

    private const ALLOWED = [
        'blogname', 'blogdescription', 'admin_email', 'timezone_string',
        'date_format', 'time_format', 'start_of_week', 'default_category',
        'default_comment_status', 'posts_per_page', 'permalink_structure',
        'siteurl', 'home', 'blog_public', 'template', 'stylesheet', 'WPLANG',
    ];

    private const RESERVED = [
        'siteurl', 'home', 'admin_email', 'template', 'stylesheet', 'blog_public',
    ];

    protected function tool_category(): string {
        return 'options';
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/options/(?P<key>[a-zA-Z0-9_]+)', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'get', 'manage_options', [ $this, 'get_option_value' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/options', [
            'methods'             => 'PUT',
            'callback'            => $this->wrap( 'set', 'manage_options', [ $this, 'set_option_value' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/options', [
            'methods'             => 'GET',
            'callback'            => $this->wrap( 'list', 'manage_options', [ $this, 'list_allowed' ] ),
            'permission_callback' => [ $this, 'public_permission' ],
        ] );
    }

    public function get_option_value( \WP_REST_Request $request ): \WP_REST_Response {
        $key = Sanitize::key( $request->get_param( 'key' ) );

        if ( ! in_array( $key, self::ALLOWED, true ) ) {
            return Response::error( 'OPTION_NOT_ALLOWED', "Option '{$key}' is not in the allowlist.", 403 );
        }

        return Response::ok( [
            'key'   => $key,
            'value' => get_option( $key ),
        ] );
    }

    public function set_option_value( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();
        $key    = Sanitize::key( $params['key'] ?? '' );
        $value  = $params['value'] ?? null;

        if ( ! $key ) {
            return Response::error( 'INVALID_INPUT', 'Provide "key" and "value".', 400 );
        }

        if ( ! in_array( $key, self::ALLOWED, true ) ) {
            return Response::error( 'OPTION_NOT_ALLOWED', "Option '{$key}' is not in the allowlist.", 403 );
        }

        // Reserved keys need confirm token.
        if ( in_array( $key, self::RESERVED, true ) ) {
            $confirm = Confirm_Token::guard(
                'options.set',
                [ 'key' => $key, 'value' => $value ],
                $params['confirm_token'] ?? null
            );
            if ( $confirm ) {
                return $confirm;
            }
        }

        $sanitised = is_string( $value ) ? Sanitize::text( $value ) : $value;
        update_option( $key, $sanitised );

        return Response::ok( [ 'key' => $key, 'value' => get_option( $key ) ] );
    }

    public function list_allowed( \WP_REST_Request $request ): \WP_REST_Response {
        $values = [];
        foreach ( self::ALLOWED as $key ) {
            $values[ $key ] = get_option( $key );
        }
        return Response::ok( $values );
    }
}
