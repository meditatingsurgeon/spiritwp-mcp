<?php

defined( 'ABSPATH' ) || exit;

/**
 * @var string $mode
 * @var array  $flags
 * @var array  $bridge_keys
 * @var array  $license
 * @var int    $confirm_ttl
 * @var int    $audit_size
 * @var array  $health_checks
 */
?>
<div class="wrap">
    <h1>SpiritWP MCP</h1>
    <?php settings_errors( 'spiritwp_mcp' ); ?>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; max-width: 1200px;">

    <!-- Mode -->
    <div class="card" style="grid-column: span 2;">
        <h2>Operating Mode</h2>
        <p>Current mode: <strong><?php echo 'a' === $mode ? 'A — Bridge (private)' : 'B — Standalone MCP (public)'; ?></strong></p>
        <p>MCP Endpoint: <code><?php echo esc_url( rest_url( 'spiritwp-mcp/v1/mcp' ) ); ?></code></p>
        <form method="post">
            <?php wp_nonce_field( 'spiritwp_mcp_settings' ); ?>
            <?php if ( 'a' === $mode ) : ?>
                <input type="hidden" name="spiritwp_mcp_action" value="switch_mode_b">
                <h3>Mode B Health Checks</h3>
                <table class="widefat" style="max-width:600px;">
                    <?php foreach ( $health_checks as $check ) : ?>
                    <tr>
                        <td><?php echo $check['pass'] ? '✅' : '❌'; ?></td>
                        <td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
                        <td><?php echo esc_html( $check['detail'] ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <p><label><input type="checkbox" required> I understand Mode B exposes the MCP endpoint publicly over HTTPS.</label></p>
                <p><button type="submit" class="button button-primary">Switch to Mode B</button></p>
            <?php else : ?>
                <input type="hidden" name="spiritwp_mcp_action" value="switch_mode_a">
                <p><button type="submit" class="button">Switch to Mode A</button></p>
            <?php endif; ?>
        </form>
    </div>

    <!-- Bridge Keys (Mode A) -->
    <div class="card">
        <h2>Bridge Keys (Mode A)</h2>
        <?php if ( $bridge_keys ) : ?>
        <table class="widefat striped">
            <thead><tr><th>Label</th><th>Created</th><th>Last Used</th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $bridge_keys as $k ) : ?>
            <tr>
                <td><?php echo esc_html( $k['label'] ?: '(unlabelled)' ); ?></td>
                <td><?php echo esc_html( date( 'Y-m-d H:i', $k['created_at'] ) ); ?></td>
                <td><?php echo $k['last_used_at'] ? esc_html( date( 'Y-m-d H:i', $k['last_used_at'] ) ) : 'Never'; ?></td>
                <td>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field( 'spiritwp_mcp_settings' ); ?>
                        <input type="hidden" name="spiritwp_mcp_action" value="revoke_bridge_key">
                        <input type="hidden" name="key_id" value="<?php echo esc_attr( $k['id'] ); ?>">
                        <button type="submit" class="button button-link-delete" onclick="return confirm('Revoke this key?')">Revoke</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <form method="post" style="margin-top:10px;">
            <?php wp_nonce_field( 'spiritwp_mcp_settings' ); ?>
            <input type="hidden" name="spiritwp_mcp_action" value="generate_bridge_key">
            <input type="text" name="key_label" placeholder="Key label (optional)" class="regular-text">
            <button type="submit" class="button">Generate Bridge Key</button>
        </form>
    </div>

    <!-- JWT (Mode B) -->
    <div class="card">
        <h2>JWT Tokens (Mode B)</h2>
        <form method="post" style="margin-bottom:10px;">
            <?php wp_nonce_field( 'spiritwp_mcp_settings' ); ?>
            <input type="hidden" name="spiritwp_mcp_action" value="issue_jwt">
            <p><input type="text" name="jwt_label" placeholder="Token label" class="regular-text"></p>
            <p><label>Expiry: <input type="number" name="jwt_ttl" value="86400" min="3600" max="2592000" style="width:100px"> seconds</label></p>
            <button type="submit" class="button">Issue JWT</button>
        </form>
        <form method="post">
            <?php wp_nonce_field( 'spiritwp_mcp_settings' ); ?>
            <input type="hidden" name="spiritwp_mcp_action" value="rotate_jwt_secret">
            <button type="submit" class="button button-link-delete" onclick="return confirm('This invalidates ALL existing JWTs. Continue?')">Rotate JWT Secret</button>
        </form>
        <p style="margin-top:10px;"><em>Application Passwords: <a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>">Manage in your profile →</a></em></p>
    </div>

    <!-- Feature Flags -->
    <div class="card">
        <h2>Feature Flags</h2>
        <form method="post">
            <?php wp_nonce_field( 'spiritwp_mcp_settings' ); ?>
            <input type="hidden" name="spiritwp_mcp_action" value="update_flags">
            <table class="form-table">
                <?php foreach ( $flags as $key => $flag ) : ?>
                <tr>
                    <th><label for="flag_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $flag['label'] ); ?></label></th>
                    <td>
                        <input type="checkbox" id="flag_<?php echo esc_attr( $key ); ?>" name="flags[<?php echo esc_attr( $key ); ?>]" <?php checked( $flag['enabled'] ); ?>>
                        <p class="description"><?php echo esc_html( $flag['desc'] ); ?></p>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <p><button type="submit" class="button button-primary">Save Flags</button></p>
        </form>
    </div>

    <!-- Settings -->
    <div class="card">
        <h2>Settings</h2>
        <form method="post">
            <?php wp_nonce_field( 'spiritwp_mcp_settings' ); ?>
            <input type="hidden" name="spiritwp_mcp_action" value="update_confirm_ttl">
            <p><label>Confirm Token TTL: <input type="number" name="confirm_ttl" value="<?php echo esc_attr( $confirm_ttl ); ?>" min="10" max="600" style="width:80px"> seconds</label></p>
            <p><button type="submit" class="button">Save</button></p>
        </form>

        <h3>Audit Log</h3>
        <p>Size: <?php echo esc_html( size_format( $audit_size ) ); ?></p>
        <form method="post" style="display:inline;">
            <?php wp_nonce_field( 'spiritwp_mcp_settings' ); ?>
            <input type="hidden" name="spiritwp_mcp_action" value="clear_audit">
            <button type="submit" class="button" onclick="return confirm('Clear audit log?')">Clear Log</button>
        </form>

        <h3>License</h3>
        <p>Status: <span style="color: <?php echo 'active' === $license['status'] ? 'green' : 'red'; ?>; font-weight:bold;">
            <?php echo esc_html( ucfirst( $license['status'] ) ); ?>
        </span></p>
        <p class="description">v0.1 uses a license stub (always active). Full Polar.sh / Lemon Squeezy licensing ships in v1.1.</p>
    </div>

    </div>
</div>
