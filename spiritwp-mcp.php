<?php
/**
 * Plugin Name:       SpiritWP MCP
 * Plugin URI:        https://spiritwp.com/mcp
 * Description:       WordPress MCP server exposing 20 task-shaped tools for AI-assisted site management. Dual-mode: private bridge for SpiritMCP or standalone MCP server for Claude Desktop / Claude Code.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            The Spiritual Agency
 * Author URI:        https://spiritual.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spiritwp-mcp
 * Domain Path:       /languages
 *
 * @package SpiritWP_MCP
 */

defined( 'ABSPATH' ) || exit;

define( 'SPIRITWP_MCP_VERSION', '0.1.0' );
define( 'SPIRITWP_MCP_FILE', __FILE__ );
define( 'SPIRITWP_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPIRITWP_MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'SPIRITWP_MCP_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader.
spl_autoload_register( static function ( string $class ): void {
    $prefix = 'SpiritWP_MCP\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }

    $relative = substr( $class, strlen( $prefix ) );
    $parts    = explode( '\\', $relative );
    $filename = 'class-' . str_replace( '_', '-', strtolower( array_pop( $parts ) ) ) . '.php';

    $subdir = '';
    if ( $parts ) {
        $subdir = strtolower( implode( '/', $parts ) ) . '/';
    }

    $file = SPIRITWP_MCP_DIR . 'includes/' . $subdir . $filename;
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// Boot.
add_action( 'plugins_loaded', static function (): void {
    SpiritWP_MCP\Plugin::instance();
} );

// Activation / deactivation.
register_activation_hook( __FILE__, [ SpiritWP_MCP\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ SpiritWP_MCP\Deactivator::class, 'deactivate' ] );
