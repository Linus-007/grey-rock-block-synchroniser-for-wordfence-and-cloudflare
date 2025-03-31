<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Admin;

use WPCF\FirewallSync\Plugin;

final class Settings {
  public static function register(): void {
    add_action('admin_menu', [self::class, 'add_settings_page']);
    add_action('admin_enqueue_scripts', [self::class, 'enqueue_styles']);
    add_action('load-toplevel_page_firewall-sync-settings', [self::class, 'add_help_tabs']);
  }

  public static function add_help_tabs(): void {
    $screen = get_current_screen();

    $screen->add_help_tab([
      'id' => 'cloudflare-token-help',
      'title' => __('Cloudflare Token Setup', Plugin::get_text_domain()),
      'content' => '<p>' . sprintf(
        esc_html(__(
          "To sync with Cloudflare, you'll need an API token with the following permissions:",
          Plugin::get_text_domain()
        )) . '</p><ul>
          <li><code>Zone → Firewall Services: Edit</code></li>
          <li><code>Zone → Zone Settings: Read</code></li>
          <li><code>Zone → Zone: Read</code></li>
        </ul><p>' .
        esc_html__('Create your token at:') .
        '<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer">https://dash.cloudflare.com/profile/api-tokens</a>.' .
        '</p><p>' .
        esc_html(__('More token generation information can be found in')) .
        ' <a href="https://developers.cloudflare.com/cloudflare-one/api-terraform/scoped-api-tokens/" target="_BLANK" rel="noopener noreferrer">the Cloudflare token generation documentation</a>.' . 
        '</p>'
        ) . '</p>',
    ]);

    $screen->add_help_tab([
      'id' => 'cloudflare-zone-id-help',
      'title' => __('Cloudflare Zone ID Setup', Plugin::get_text_domain()),
      'content' => '<p>' . sprintf(
        esc_html(__(
          "To sync with Cloudflare, you'll need your Cloudflare Zone ID",
          Plugin::get_text_domain()
        )) . '</p>' .
        esc_html__('Get your Zone ID by following ') .
        '<a href="https://developers.cloudflare.com/fundamentals/setup/find-account-and-zone-ids/" target="_blank" rel="noopener noreferrer">the Clareflare Zone ID location documentation</a>'
      ) . '</p>',
    ]);
  }

  public static function add_settings_page(): void {
    add_menu_page(
      __('Firewall Sync', Plugin::get_text_domain()),
      __('Firewall Sync', Plugin::get_text_domain()),
      'manage_options',
      'firewall-sync-settings',
      [self::class, 'render_settings'],
      'dashicons-shield-alt',
      81
    );

    add_submenu_page(
      'firewall-sync-settings',
      __('Block Log', Plugin::get_text_domain()),
      __('Block Log', Plugin::get_text_domain()),
      'manage_options',
      'firewall-sync-log',
      [self::class, 'render_log']
    );

    add_submenu_page(
      'firewall-sync-settings',
      __('Manual IP Block', Plugin::get_text_domain()),
      __('Manual IP Block', Plugin::get_text_domain()),
      'manage_options',
      'firewall-manual-block',
      [self::class, 'render_manual_block']
    );
  }

  public static function enqueue_styles(): void {
    wp_register_style('firewall-sync-admin', WPCF_FS_PLUGIN_URL . 'assets/admin.css');
    wp_enqueue_style('firewall-sync-admin');
  }

  public static function render_log(): void {
    $log_table = new LogTable();
    $log_table->prepare_items();
    ?>

    <div class="wrap">
      <h1><?php echo esc_html(__('Firewall Sync Log', Plugin::get_text_domain())); ?></h1>
      <?php $log_table->display(); ?>
    </div>

    <?php
  }

  public static function render_settings(): void {
    $sync_disabled = get_option('firewall_sync_is_running') ? 'disabled' : '';
    $last_sync = get_option('firewall_sync_last_run');
    $last_sync_time = $last_sync
      ? date_i18n(
        get_option('date_format') . ' ' . get_option('time_format'),
        strtotime($last_sync)
      )
      : __('Never', Plugin::get_text_domain()); ?>

    <div class="wrap">
      <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
      <?php settings_errors('firewall_sync_messages'); ?>
      <form method="post" action="options.php">
        <?php settings_fields('firewall_sync_options_group'); ?>
        <?php do_settings_sections('firewall-sync-settings'); ?>
        <?php submit_button(); ?>
      </form>

      <hr>

      <h2><?php echo esc_html(__('Last Sync Time', Plugin::get_text_domain())); ?></h2>
      <p><?php echo esc_html($last_sync_time); ?></p>

      <div class="firewall-sync-actions">
        <h2><?php echo esc_html(__('Manual Actions', Plugin::get_text_domain())); ?></h2>
        <?php self::render_action_button('firewall_sync_now', __('Sync Now', Plugin::get_text_domain()), 'primary', $sync_disabled); ?>
        <?php self::render_action_button('firewall_sync_cleanup_now', __('Run Cleanup Now', Plugin::get_text_domain())); ?>
        <?php self::render_action_button('firewall_sync_reconcile', __('Run Reconciliation Now', Plugin::get_text_domain())); ?>
      </div>

      <?php if ($result = get_transient('firewall_sync_reconcile_result')): ?>
        <?php delete_transient('firewall_sync_reconcile_result'); ?>
        <h2><?php echo esc_html(__('Reconciliation Results', Plugin::get_text_domain())); ?></h2>
        
        <?php if (empty($result['missing_in_cf']) && empty($result['orphaned_in_cf'])): ?>
          <p><?php echo esc_html(__('Reconciliation completed with no differences.', Plugin::get_text_domain())); ?></p>
        <?php else: ?>
          <h3><?php echo esc_html(__('Missing in Cloudflare', Plugin::get_text_domain())); ?></h3>
          <ul>
            <?php foreach ($result['missing_in_cf'] ?? [] as $ip): ?>
              <li><?php echo esc_html($ip); ?></li>
            <?php endforeach; ?>
          </ul>

          <h3><?php echo esc_html(__('Orphaned in Cloudflare', Plugin::get_text_domain())); ?></h3>
          <ul>
            <?php foreach ($result['orphaned_in_cf'] ?? [] as $ip): ?>
              <li><?php echo esc_html($ip); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif;
  }

  public static function render_action_button(string $action, string $label, string $type = 'secondary', string $disabled = ''): void {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="firewall-sync-form">
      <?php wp_nonce_field($action, $action . '_nonce'); ?>
      <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
      <?php submit_button($label, $type, $action, false, ['disabled' => $disabled]); ?>
    </form>
    <?php
  }

  public static function render_manual_block(): void {
    ?>
    <div class="wrap">
      <h1><?php echo esc_html(__('Manually Block an IP Address', Plugin::get_text_domain())); ?></h1>
      <?php settings_errors('firewall_sync_manual_block'); ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('firewall_sync_manual_block', 'firewall_sync_manual_block_nonce'); ?>
        <table class="form-table">
          <tr>
            <th scope="row">
              <label for="manual_ip">
                <?php echo esc_html(__('IP Address', Plugin::get_text_domain())); ?>
              </label>
            </th>
            <td>
              <input type="text" name="manual_ip" id="manual_ip" class="regular-text" required>
            </td>
          </tr>
          <tr>
            <th scope="row">
              <label for="manual_reason">
                <?php echo esc_html(__('Reason (optional)', Plugin::get_text_domain())); ?>
              </label>
            </th>
            <td>
              <input type="text" name="manual_reason" id="manual_reason" class="regular-text">
            </td>
          </tr>
        </table>
        <?php submit_button(__('Block IP', Plugin::get_text_domain())); ?>
        <input type="hidden" name="action" value="firewall_sync_manual_block">
      </form>
    </div>
    <?php
  }
}
