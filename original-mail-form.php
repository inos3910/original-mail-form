<?php

/**
 * Plugin Name: Original Mail Form
 * Plugin URI: https://github.com/inos3910/original-mail-form
 * Update URI: sharesl-omf-plugin
 * Description: メールフォーム設定プラグイン（クラシックテーマ用）
 * Author: SHARESL
 * Author URI: https://sharesl.net/
 * Version: 1.0
 */

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}
//オートロード
$instances = require_once(plugin_dir_path(__FILE__) . 'autoload.php');

class OMF
{
  use OMF_Trait_Form, OMF_Trait_Validation;
  /**
   * 各クラスのインスタンス
   *
   * @var array
   */
  private array $instances = [];

  /**
   * construct
   *
   * @param array $_instances
   */
  public function __construct(array $_instances)
  {
    $this->instances = $_instances;
  }

  /**
   * インスタンス取得
   *
   * @param string $instance_name
   * @return object|null
   */
  public function get_instance(string $instance_name): object|null
  {
    if (empty($this->instances[$instance_name])) {
      return null;
    }

    return $this->instances[$instance_name];
  }

  /**
   * エラー取得関数
   * @return array エラーメッセージの配列
   */
  public static function get_errors()
  {
    return apply_filters('omf_get_errors', []);
  }

  /**
   * 送信データを取得（確認画面用）
   * @return array
   */
  public static function get_post_values()
  {
    return apply_filters('omf_get_post_values', []);
  }

  /**
   * nonceフィールド出力
   * @return void
   */
  public static function nonce_field()
  {
    apply_filters('omf_nonce_field', []);
  }

  /**
   * reCAPTCHAフィールドを出力
   * @return void
   */
  public static function recaptcha_field()
  {
    apply_filters('omf_recaptcha_field', []);
  }

  /**
   * Cloudflare Turnstileフィールドを出力
   * @return void
   */
  public static function turnstile_field()
  {
    apply_filters('omf_turnstile_field', []);
  }

  /**
   * ワンタイムトークンを取得
   *
   * @return void
   */
  public static function get_omf_token()
  {
    return apply_filters('omf_create_token', []);
  }
}

$GLOBALS['global_omf'] = new OMF($instances);
