<?php

declare(strict_types=1);

namespace WPCF\FirewallSync;

use WPCF\FirewallSync\Admin\Settings;
use WPCF\FirewallSync\Admin\Fields;
use WPCF\FirewallSync\Services\SyncScheduler;
use WPCF\FirewallSync\Services\BlockLogger;

final class Plugin {
  public const VERSION = '1.0.0';

  /**
   * Initialize the plugin
   */
  public static function init(): void {
    self::define_constants();
    self::load_admin();
    self::load_services();
  }

  private static function define_constants(): void {
    if (!defined('WPCF_FS_VERSION')) {
      define('WPCF_FS_VERSION', self::VERSION);
    }

    if (!defined('WPCF_FS_PLUGIN_DIR')) {
      define('WPCF_FS_PLUGIN_DIR', __DIR__ . '/../index.php');
    }

    if (!defined('WPCF_FS_PLUGIN_URL')) {
      define('WPCF_FS_PLUGIN_URL', plugin_dir_url(__DIR . '/../index.php'));
    }
  }

  private static function load_admin(): void {
    if (is_admin()) {
      Settings::init();
      Fields::init();
    }
  }

  private static function load_services(): void {
    SyncScheduler::register();
    BlockLogger::create_table();
  }
}