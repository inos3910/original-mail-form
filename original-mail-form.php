<?php

/**
 * Plugin Name: Original Mail Form
 * Plugin URI: https://github.com/inos3910/original-mail-form
 * Update URI: https://github.com/inos3910/original-mail-form
 * Description: メールフォーム設定プラグイン（クラシックテーマ用）
 * Author: SHARESL
 * Author URI: https://sharesl.net/
 * Version: 1.2.0
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Text Domain: original-mail-form
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

  /** 管理画面で作成したフォームを、入力・確認・完了の全段階で描画する。 */
  public static function render_form(int|array $selector = 0): void
  {
    OMF_Managed_Form::render($selector);
  }

  /** HTMLを出力せず、各画面のテンプレートに管理項目と検証済みデータを渡す。 */
  public static function form_context(int|array $selector = 0): array|\WP_Error
  {
    return OMF_Managed_Form::context($selector);
  }

  /** foreachで配置するための、OMF共通クラス付き項目HTMLを返す。 */
  public static function get_fields(int|array $selector = 0): array|\WP_Error
  {
    return OMF_Managed_Form::fields($selector);
  }

  /** 文言を指定して、現在の画面に必要なボタンだけを共通HTMLで出力する。 */
  public static function render_buttons(array $labels = [], int|array $selector = 0): void
  {
    $context = OMF_Managed_Form::context($selector);
    if (is_wp_error($context)) {
      echo '<p class="omf-render-notice">' . esc_html($context->get_error_message()) . '</p>';
      return;
    }
    echo OMF_Button_Renderer::html($context, $labels);
  }

  /** テーマの見出し等を切り替えるための現在の画面状態。 */
  public static function form_step(int|array $selector = 0): string
  {
    return OMF_Managed_Form::current_step($selector);
  }

  /** 独自テンプレート内で標準の項目描画を部分的に再利用する。 */
  public static function render_field(array $field, mixed $value, array $errors, bool $confirm, int $form_id): void
  {
    OMF_Field_Renderer::render($field, $value, $errors, $confirm, $form_id);
  }

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
