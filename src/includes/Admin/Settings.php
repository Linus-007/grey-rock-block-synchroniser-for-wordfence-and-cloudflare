<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Admin;

final class Settings {
    public static function register(): void {
      add_action('admin_menu', [self::class, 'add_settings_page']);
    }

    public static function add_settings_page(): void {
      add_menu_page(
        'Firewall Sync Settings',
        'Firewall Sync',
        'manage_options',
        'firewall-sync-settings',
        [self::class, 'render'],
        'dashicons-shield-alt',
        81
      );
    }

    public static function render(): void {
      ?>
        <div class="wrap">
          <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

          <?php settings_errors('firewall_sync_messages'); ?>

          <form method="post" action="options.php">
            <?php
              settings_fields('firewall_sync_options_group');
              do_settings_sections('firewall-sync-settings');
              submit_button();
              ?>
          </form>

          <hr>

          <?php
            $last_sync = get_option('firewall_sync_last_run');

            if ($last_sync) {
              $last_sync_time = date('Y-m-d H:i:s', $last_sync);
            } else {
              $last_sync_time = __('Never', 'firewall-sync');
            }
          ?>

          <h2><?php esc_html_e('Last Sync Time', 'firewall-sync'); ?></h2>
          <p><?php echo esc_html($last_sync_time); ?></p>

          <?php $sync_disabled = get_option('firewall_sync_is_running') ? 'disabled' : ''; ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 10px;">
            <?php wp_nonce_field('firewall_sync_now', 'firewall_sync_now_nonce'); ?>
            <input type="hidden" name="action" value="firewall_sync_now">
            <?php submit_button('Sync Now', 'primary', 'firewall_sync_now', false, ['disabled' => $sync_disabled]); ?>
          </form>

          <details style="margin-top: 20px;">
            <summary style="font-size: 1.2em; cursor: pointer;">
              <?php esc_html_e('View Block Log', 'firewall-sync'); ?>
            </summary>
            <div style="margin-top: 10px;">
              <?php
                $log_table = new LogTable();
                $log_table->prepare_items();
                $log_table->display();
              ?>
            </div>
          </details>
        </div>
      <?php
    }
}
