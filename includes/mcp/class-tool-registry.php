<?php

namespace SpiritWP_MCP\MCP;

defined( 'ABSPATH' ) || exit;

use SpiritWP_MCP\Mode;

/**
 * MCP Tool Registry.
 *
 * Maps MCP tool names to REST routes and provides rich tool descriptions
 * that teach Claude about capabilities, gotchas, and best practices.
 */
final class Tool_Registry {

    /**
     * Get all tools available in the current mode.
     *
     * @return array<string, array{description: string, inputSchema: array, route: string, method: string}>
     */
    public static function get_tools(): array {
        $tools = self::all_tools();

        // In Mode B, filter to the safe whitelist.
        if ( Mode::is_b() ) {
            $tools = array_filter( $tools, static fn( $t ) => ! ( $t['mode_a_only'] ?? false ) );
        }

        // Strip internal metadata before returning to MCP client.
        return array_map( static function ( $tool ) {
            unset( $tool['route'], $tool['method'], $tool['mode_a_only'] );
            return $tool;
        }, $tools );
    }

    /**
     * Resolve a tool name to its internal route info.
     *
     * @return array{route: string, method: string}|null
     */
    public static function resolve( string $tool_name ): ?array {
        $tools = self::all_tools();

        if ( ! isset( $tools[ $tool_name ] ) ) {
            return null;
        }

        $tool = $tools[ $tool_name ];

        // Mode B check.
        if ( Mode::is_b() && ( $tool['mode_a_only'] ?? false ) ) {
            return null;
        }

        return [
            'route'  => $tool['route'],
            'method' => $tool['method'],
        ];
    }

    /**
     * Full tool registry.
     */
    private static function all_tools(): array {
        return [
            // ── Status ──
            'status.info' => [
                'description'  => 'Get site information: URL, name, WP version, PHP version, active theme, mode, feature flags, multisite status.',
                'inputSchema'  => [ 'type' => 'object', 'properties' => new \stdClass() ],
                'route'        => '/spiritwp-mcp/v1/status/info',
                'method'       => 'GET',
            ],
            'status.health' => [
                'description'  => 'Health check: DB connectivity, PHP memory, debug mode, audit log size.',
                'inputSchema'  => [ 'type' => 'object', 'properties' => new \stdClass() ],
                'route'        => '/spiritwp-mcp/v1/status/health',
                'method'       => 'GET',
            ],

            // ── Content ──
            'content.list' => [
                'description'  => 'List content (posts, pages, any CPT). Supports filtering by post_type, status, taxonomy/term, search, pagination.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => [ 'type' => 'string', 'default' => 'post', 'description' => 'Any registered post type slug.' ],
                        'status'    => [ 'type' => 'string', 'default' => 'publish' ],
                        'per_page'  => [ 'type' => 'integer', 'default' => 20 ],
                        'page'      => [ 'type' => 'integer', 'default' => 1 ],
                        's'         => [ 'type' => 'string', 'description' => 'Search term.' ],
                        'taxonomy'  => [ 'type' => 'string' ],
                        'term'      => [ 'type' => 'string', 'description' => 'Term slug or ID.' ],
                    ],
                ],
                'route'  => '/spiritwp-mcp/v1/content',
                'method' => 'GET',
            ],
            'content.get' => [
                'description'  => 'Get a single content item with full content body, excerpt, and template.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [ 'id' => [ 'type' => 'integer' ] ],
                    'required'   => [ 'id' ],
                ],
                'route'  => '/spiritwp-mcp/v1/content/{id}',
                'method' => 'GET',
            ],
            'content.create' => [
                'description'  => 'Create content. Supports title, content (HTML), excerpt, status, post_type, slug, taxonomies. Content is wp_kses_post sanitised — raw Gutenberg block comments pass through.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title'      => [ 'type' => 'string' ],
                        'content'    => [ 'type' => 'string', 'description' => 'HTML content including Gutenberg block comments.' ],
                        'excerpt'    => [ 'type' => 'string' ],
                        'status'     => [ 'type' => 'string', 'default' => 'draft' ],
                        'post_type'  => [ 'type' => 'string', 'default' => 'post' ],
                        'slug'       => [ 'type' => 'string' ],
                        'taxonomies' => [ 'type' => 'object', 'description' => '{"category": [1,2], "post_tag": ["tag1"]}' ],
                    ],
                    'required' => [ 'title' ],
                ],
                'route'  => '/spiritwp-mcp/v1/content',
                'method' => 'POST',
            ],
            'content.update' => [
                'description'  => 'Update content. Only provided fields are changed; omitted fields are preserved.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id'         => [ 'type' => 'integer' ],
                        'title'      => [ 'type' => 'string' ],
                        'content'    => [ 'type' => 'string' ],
                        'excerpt'    => [ 'type' => 'string' ],
                        'status'     => [ 'type' => 'string' ],
                        'slug'       => [ 'type' => 'string' ],
                        'taxonomies' => [ 'type' => 'object' ],
                    ],
                    'required' => [ 'id' ],
                ],
                'route'  => '/spiritwp-mcp/v1/content/{id}',
                'method' => 'PUT',
            ],
            'content.trash' => [
                'description'  => 'Move content to trash. Reversible via content.restore.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [ 'id' => [ 'type' => 'integer' ] ],
                    'required'   => [ 'id' ],
                ],
                'route'  => '/spiritwp-mcp/v1/content/{id}/trash',
                'method' => 'POST',
            ],
            'content.restore' => [
                'description'  => 'Restore trashed content.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [ 'id' => [ 'type' => 'integer' ] ],
                    'required'   => [ 'id' ],
                ],
                'route'  => '/spiritwp-mcp/v1/content/{id}/restore',
                'method' => 'POST',
            ],
            'content.delete' => [
                'description'  => 'Permanently delete content. Requires confirm_token (two-step). First call returns a token; second call with the token executes.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id'            => [ 'type' => 'integer' ],
                        'confirm_token' => [ 'type' => 'string', 'description' => 'Omit on first call; provide the returned token on second call.' ],
                    ],
                    'required' => [ 'id' ],
                ],
                'route'  => '/spiritwp-mcp/v1/content/{id}',
                'method' => 'DELETE',
            ],

            // ── Meta ──
            'meta.get' => [
                'description'  => 'Get all meta for a post, term, or user. Optional prefix filter. Returns JSON values including those with spaces, hashes, and newlines — unlike WP-CLI which mangles them.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'object_type' => [ 'type' => 'string', 'enum' => [ 'post', 'term', 'user' ] ],
                        'object_id'   => [ 'type' => 'integer' ],
                        'prefix'      => [ 'type' => 'string', 'description' => 'Filter keys by prefix.' ],
                    ],
                    'required' => [ 'object_type', 'object_id' ],
                ],
                'route'  => '/spiritwp-mcp/v1/meta/{object_type}/{object_id}',
                'method' => 'GET',
            ],
            'meta.set' => [
                'description'  => 'Set multiple meta fields atomically in one call. Values can contain spaces, # characters, newlines — all transmitted cleanly via JSON. This is the key advantage over WP-CLI meta updates.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'object_type' => [ 'type' => 'string', 'enum' => [ 'post', 'term', 'user' ] ],
                        'object_id'   => [ 'type' => 'integer' ],
                        'meta'        => [ 'type' => 'object', 'description' => 'Key-value pairs. Values can be strings, numbers, arrays, or objects.' ],
                    ],
                    'required' => [ 'object_type', 'object_id', 'meta' ],
                ],
                'route'  => '/spiritwp-mcp/v1/meta/{object_type}/{object_id}',
                'method' => 'PUT',
            ],
            'meta.delete' => [
                'description'  => 'Delete specific meta keys from a post, term, or user.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'object_type' => [ 'type' => 'string', 'enum' => [ 'post', 'term', 'user' ] ],
                        'object_id'   => [ 'type' => 'integer' ],
                        'keys'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    ],
                    'required' => [ 'object_type', 'object_id', 'keys' ],
                ],
                'route'  => '/spiritwp-mcp/v1/meta/{object_type}/{object_id}',
                'method' => 'DELETE',
            ],

            // ── Options ──
            'options.list' => [
                'description'  => 'List all allowed WP options with current values.',
                'inputSchema'  => [ 'type' => 'object', 'properties' => new \stdClass() ],
                'route'        => '/spiritwp-mcp/v1/options',
                'method'       => 'GET',
            ],
            'options.get' => [
                'description'  => 'Get a single WP option. Only allowlisted keys accepted.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [ 'key' => [ 'type' => 'string' ] ],
                    'required'   => [ 'key' ],
                ],
                'route'  => '/spiritwp-mcp/v1/options/{key}',
                'method' => 'GET',
            ],
            'options.set' => [
                'description'  => 'Set a WP option. Only allowlisted keys. Reserved keys (siteurl, home, admin_email, template, stylesheet, blog_public) require confirm_token.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'key'           => [ 'type' => 'string' ],
                        'value'         => [ 'description' => 'Any JSON value.' ],
                        'confirm_token' => [ 'type' => 'string' ],
                    ],
                    'required' => [ 'key', 'value' ],
                ],
                'route'  => '/spiritwp-mcp/v1/options',
                'method' => 'PUT',
            ],

            // ── Cache ──
            'cache.purge' => [
                'description'  => 'Purge caches. Targets: all, page, ccss, object, transients. Can also purge a single URL. Calls LiteSpeed do_action directly — works where /litespeed/v3/purge returns 404.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'target' => [ 'type' => 'string', 'enum' => [ 'all', 'page', 'ccss', 'object', 'transients' ], 'default' => 'all' ],
                        'url'    => [ 'type' => 'string', 'description' => 'Optional: purge a single URL.' ],
                    ],
                ],
                'route'  => '/spiritwp-mcp/v1/cache/purge',
                'method' => 'POST',
            ],
            'cache.status' => [
                'description'  => 'Check which cache systems are active (LiteSpeed, Redis, object cache).',
                'inputSchema'  => [ 'type' => 'object', 'properties' => new \stdClass() ],
                'route'        => '/spiritwp-mcp/v1/cache/status',
                'method'       => 'GET',
            ],

            // ── Media ──
            'media.list' => [
                'description'  => 'List media attachments. Filter by mime_type.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'per_page'  => [ 'type' => 'integer', 'default' => 20 ],
                        'page'      => [ 'type' => 'integer', 'default' => 1 ],
                        'mime_type' => [ 'type' => 'string', 'description' => 'e.g. image/jpeg, image, video' ],
                    ],
                ],
                'route'  => '/spiritwp-mcp/v1/media',
                'method' => 'GET',
            ],
            'media.get' => [
                'description'  => 'Get a single media item with full metadata (dimensions, sizes, alt text).',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [ 'id' => [ 'type' => 'integer' ] ],
                    'required'   => [ 'id' ],
                ],
                'route'  => '/spiritwp-mcp/v1/media/{id}',
                'method' => 'GET',
            ],
            'media.upload_url' => [
                'description'  => 'Upload media from a URL. Downloads the file and adds it to the WP media library.',
                'inputSchema'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'   => [ 'type' => 'string' ],
                        'title' => [ 'type' => 'string' ],
                    ],
                    'required' => [ 'url' ],
                ],
                'route'  => '/spiritwp-mcp/v1/media/upload-url',
                'method' => 'POST',
            ],

            // ── Remaining tools — abbreviated schemas ──
            'nav.menus'     => [ 'description' => 'List navigation menus.',     'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/nav/menus',     'method' => 'GET' ],
            'nav.items'     => [ 'description' => 'Get menu items for a menu.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'id' ] ], 'route' => '/spiritwp-mcp/v1/nav/menus/{id}/items', 'method' => 'GET' ],
            'nav.locations' => [ 'description' => 'List registered menu locations and assignments.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/nav/locations', 'method' => 'GET' ],

            'builder.blocksy_meta'  => [ 'description' => 'Set Blocksy page meta via native update_post_meta — works where REST silently drops the values.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer' ], 'meta' => [ 'type' => 'object' ] ], 'required' => [ 'post_id', 'meta' ] ], 'route' => '/spiritwp-mcp/v1/builder/blocksy-meta/{post_id}', 'method' => 'PUT' ],
            'builder.greenshift_css' => [ 'description' => 'Update GreenShift global custom CSS — bypasses REST which fails on canvas pages.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'css' => [ 'type' => 'string' ] ], 'required' => [ 'css' ] ], 'route' => '/spiritwp-mcp/v1/builder/greenshift-css', 'method' => 'PUT' ],
            'builder.additional_css' => [ 'description' => 'Get or set WP Additional CSS (Customizer CSS).', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'css' => [ 'type' => 'string' ] ] ], 'route' => '/spiritwp-mcp/v1/builder/additional-css', 'method' => 'PUT' ],
            'builder.content_blocks' => [ 'description' => 'List Blocksy Content Blocks (ct_content_block CPT).', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/builder/content-blocks', 'method' => 'GET' ],
            'builder.set_template'  => [ 'description' => 'Set page template. Use exact template slug e.g. "page-templates/full-width.php".', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer' ], 'template' => [ 'type' => 'string' ] ], 'required' => [ 'post_id', 'template' ] ], 'route' => '/spiritwp-mcp/v1/builder/template/{post_id}', 'method' => 'PUT' ],

            'seo.get'      => [ 'description' => 'Get SEO meta for a post (auto-detects SEOPress/Yoast/RankMath).', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer' ] ], 'required' => [ 'post_id' ] ], 'route' => '/spiritwp-mcp/v1/seo/{post_id}', 'method' => 'GET' ],
            'seo.set'      => [ 'description' => 'Set SEO meta for a post. Keys: title, description, canonical, noindex, focus_keyword, og_title, og_description.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer' ], 'meta' => [ 'type' => 'object' ] ], 'required' => [ 'post_id', 'meta' ] ], 'route' => '/spiritwp-mcp/v1/seo/{post_id}', 'method' => 'PUT' ],
            'seo.provider' => [ 'description' => 'Detect which SEO plugin is active.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/seo/provider', 'method' => 'GET' ],

            'users.list' => [ 'description' => 'List users. Filter by role.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'role' => [ 'type' => 'string' ], 'per_page' => [ 'type' => 'integer' ] ] ], 'route' => '/spiritwp-mcp/v1/users', 'method' => 'GET' ],
            'users.me'   => [ 'description' => 'Get the current authenticated user.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/users/me', 'method' => 'GET' ],
            'users.get'  => [ 'description' => 'Get a user by ID.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'id' ] ], 'route' => '/spiritwp-mcp/v1/users/{id}', 'method' => 'GET' ],

            'taxonomies.list'        => [ 'description' => 'List registered taxonomies.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/taxonomies', 'method' => 'GET' ],
            'taxonomies.terms'       => [ 'description' => 'List terms in a taxonomy.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'taxonomy' => [ 'type' => 'string' ] ], 'required' => [ 'taxonomy' ] ], 'route' => '/spiritwp-mcp/v1/taxonomies/{taxonomy}/terms', 'method' => 'GET' ],
            'taxonomies.create_term' => [ 'description' => 'Create a term in a taxonomy.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'taxonomy' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'slug' => [ 'type' => 'string' ], 'parent' => [ 'type' => 'integer' ] ], 'required' => [ 'taxonomy', 'name' ] ], 'route' => '/spiritwp-mcp/v1/taxonomies/{taxonomy}/terms', 'method' => 'POST' ],

            'rewrite.rules' => [ 'description' => 'List current rewrite rules.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/rewrite/rules', 'method' => 'GET' ],
            'rewrite.flush' => [ 'description' => 'Flush rewrite rules.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/rewrite/flush', 'method' => 'POST' ],

            'forms.list'        => [ 'description' => 'List forms (Forminator adapter).', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/forms', 'method' => 'GET' ],
            'forms.submissions' => [ 'description' => 'Get form submissions.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'id' ] ], 'route' => '/spiritwp-mcp/v1/forms/{id}/submissions', 'method' => 'GET' ],

            'search-replace.execute' => [ 'description' => 'Database-wide search and replace. Set dry_run: true first. Write requires enable_exec_sql_raw flag + confirm_token.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ], 'replace' => [ 'type' => 'string' ], 'dry_run' => [ 'type' => 'boolean', 'default' => true ], 'tables' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'confirm_token' => [ 'type' => 'string' ] ], 'required' => [ 'search', 'replace' ] ], 'route' => '/spiritwp-mcp/v1/search-replace', 'method' => 'POST' ],

            'patterns.list'       => [ 'description' => 'List registered block patterns.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'category' => [ 'type' => 'string' ] ] ], 'route' => '/spiritwp-mcp/v1/patterns', 'method' => 'GET' ],
            'patterns.categories' => [ 'description' => 'List block pattern categories.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/patterns/categories', 'method' => 'GET' ],

            'widgets.sidebars' => [ 'description' => 'List widget sidebars.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/widgets/sidebars', 'method' => 'GET' ],
            'widgets.list'     => [ 'description' => 'List registered widgets.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/widgets', 'method' => 'GET' ],

            // ── Feature-flagged tools ──
            'exec-sql.query'  => [ 'description' => 'Execute SQL. SELECT always works. UPDATE/INSERT/DELETE require enable_exec_sql_raw flag + confirm_token. DROP/TRUNCATE/ALTER are always blocked.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'query' => [ 'type' => 'string' ], 'confirm_token' => [ 'type' => 'string' ] ], 'required' => [ 'query' ] ], 'route' => '/spiritwp-mcp/v1/exec-sql/query', 'method' => 'POST' ],
            'exec-sql.tables' => [ 'description' => 'List database tables and prefix.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ], 'route' => '/spiritwp-mcp/v1/exec-sql/tables', 'method' => 'GET' ],

            'exec-php.eval' => [ 'description' => 'Execute PHP code. Requires enable_exec_php flag + confirm_token. Returns output buffer and return value.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'code' => [ 'type' => 'string', 'description' => 'PHP code without <?php tags.' ], 'confirm_token' => [ 'type' => 'string' ] ], 'required' => [ 'code' ] ], 'route' => '/spiritwp-mcp/v1/exec-php', 'method' => 'POST', 'mode_a_only' => true ],

            'filesystem.read'   => [ 'description' => 'Read a file within ABSPATH. Max 5 MB.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'path' => [ 'type' => 'string' ] ], 'required' => [ 'path' ] ], 'route' => '/spiritwp-mcp/v1/filesystem/read', 'method' => 'POST' ],
            'filesystem.write'  => [ 'description' => 'Write a file. Requires enable_filesystem_write flag. Supports append mode.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'path' => [ 'type' => 'string' ], 'content' => [ 'type' => 'string' ], 'append' => [ 'type' => 'boolean' ] ], 'required' => [ 'path', 'content' ] ], 'route' => '/spiritwp-mcp/v1/filesystem/write', 'method' => 'POST', 'mode_a_only' => true ],
            'filesystem.delete' => [ 'description' => 'Delete a file. Requires enable_filesystem_write flag + confirm_token.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'path' => [ 'type' => 'string' ], 'confirm_token' => [ 'type' => 'string' ] ], 'required' => [ 'path' ] ], 'route' => '/spiritwp-mcp/v1/filesystem/delete', 'method' => 'POST', 'mode_a_only' => true ],
            'filesystem.list'   => [ 'description' => 'List directory contents.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'path' => [ 'type' => 'string' ], 'depth' => [ 'type' => 'integer', 'default' => 1 ] ], 'required' => [ 'path' ] ], 'route' => '/spiritwp-mcp/v1/filesystem/list', 'method' => 'POST' ],

            'passthrough.proxy' => [ 'description' => 'Proxy a request to any WP REST API route. For edge cases not covered by other tools.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'route' => [ 'type' => 'string', 'description' => 'WP REST route starting with /.' ], 'method' => [ 'type' => 'string', 'default' => 'GET' ], 'body' => [ 'type' => 'object' ] ], 'required' => [ 'route' ] ], 'route' => '/spiritwp-mcp/v1/passthrough', 'method' => 'POST', 'mode_a_only' => true ],
        ];
    }
}
