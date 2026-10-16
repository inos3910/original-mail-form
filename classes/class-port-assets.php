<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * エクスポート・インポート画面（設定タブ port）のCSS・JSを、その画面だけで読み込む。
 */
class OMF_Port_Assets
{
  public function __construct()
  {
    add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
  }

  /**
   * @return void
   */
  public static function enqueue(): void
  {
    if (($_GET['page'] ?? '') !== 'omf_settings' || ($_GET['tab'] ?? '') !== 'port') {
      return;
    }
    $dir = dirname(__DIR__);
    wp_enqueue_style('omf-port-admin', plugins_url('assets/port-admin.css', __DIR__), [], (string) filemtime($dir . '/assets/port-admin.css'));
    wp_enqueue_script('omf-port-admin', plugins_url('assets/port-admin.js', __DIR__), [], (string) filemtime($dir . '/assets/port-admin.js'), true);
  }
}
