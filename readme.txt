=== SpiritWP MCP ===
Contributors: meditatingsurgeon
Tags: mcp, ai, claude, automation, wordpress-management
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress MCP server exposing 20 task-shaped tools for AI-assisted site management.

== Description ==

SpiritWP MCP turns your WordPress site into a Model Context Protocol (MCP) server that AI assistants like Claude Desktop and Claude Code can connect to directly. It exposes 20 task-shaped tools for content management, meta operations, cache control, SEO, and more.

**Dual-mode architecture:**

* **Mode A (Bridge)** — Private REST API for localhost/private network access. SpiritMCP or other tools SSH into your server and call the bridge over 127.0.0.1.
* **Mode B (Standalone MCP)** — Public MCP-over-HTTP endpoint that Claude Desktop, Claude Code, Cursor, or any MCP client connects to directly over HTTPS.

**Key advantages over WP-CLI based tools:**

* Atomic multi-field meta writes via JSON (spaces, hashes, newlines all work)
* Native Blocksy page meta support (REST silently drops these)
* Direct LiteSpeed cache purge via do_action (CLI endpoint returns 404)
* GreenShift CSS updates that work on canvas pages
* SEO adapter pattern (SEOPress, Yoast, Rank Math)
* Feature flags for dangerous operations (exec-php, raw SQL, filesystem writes)
* Two-step confirm tokens for destructive operations
* JSONL audit logging with hashed arguments

== Installation ==

1. Upload the plugin to `/wp-content/plugins/spiritwp-mcp/`
2. Activate the plugin
3. Go to Settings → SpiritWP MCP
4. Generate a Bridge Key (Mode A) or issue a JWT (Mode B)

== Changelog ==

= 0.1.0 =
* Initial release
* 20 task-shaped tool controllers
* Dual-mode architecture (A: Bridge, B: Standalone MCP)
* Feature flags, confirm tokens, audit logging
* License stub (full licensing in v1.1)
