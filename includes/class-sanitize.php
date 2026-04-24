<?php

namespace SpiritWP_MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Centralised input sanitisation helpers.
 */
final class Sanitize {

    public static function text( mixed $value ): string {
        return sanitize_text_field( (string) $value );
    }

    public static function key( mixed $value ): string {
        return sanitize_key( (string) $value );
    }

    public static function int( mixed $value ): int {
        return absint( $value );
    }

    public static function email( mixed $value ): string {
        return sanitize_email( (string) $value );
    }

    public static function url( mixed $value ): string {
        return esc_url_raw( (string) $value );
    }

    public static function html( mixed $value ): string {
        return wp_kses_post( (string) $value );
    }

    public static function slug( mixed $value ): string {
        return sanitize_title( (string) $value );
    }

    /**
     * Sanitise a post type name — alphanumeric, dashes, underscores.
     */
    public static function post_type( mixed $value ): string {
        $clean = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
        return $clean ?: 'post';
    }

    /**
     * Sanitise an array of strings.
     *
     * @param mixed $value
     * @return string[]
     */
    public static function text_array( mixed $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        return array_map( [ self::class, 'text' ], $value );
    }
}
