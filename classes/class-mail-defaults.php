<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 新規フォームの文面。保存済みのメール設定は変更しない。 */
class OMF_Mail_Defaults
{
  public function __construct()
  {
    add_action('admin_enqueue_scripts', static function () {
      $screen = get_current_screen();
      if (!$screen || $screen->post_type !== OMF_Config::NAME || $screen->base !== 'post') { return; }
      wp_enqueue_script('omf-mail-defaults', plugins_url('assets/mail-defaults.js', __DIR__), [], filemtime(dirname(__DIR__) . '/assets/mail-defaults.js'), true);
      wp_localize_script('omf-mail-defaults', 'omfMailDefaults', self::values());
      wp_enqueue_style('omf-mail-tags', plugins_url('assets/mail-tags.css', __DIR__), [], filemtime(dirname(__DIR__) . '/assets/mail-tags.css'));
    });
  }

  public static function values(): array
  {
    return [
      'cf_omf_reply_title' => '【{site_name}】お問い合わせを受け付けました',
      'cf_omf_reply_mail' => "お問い合わせありがとうございます。\n以下の内容で受け付けました。内容を確認のうえ、担当者からご連絡いたします。\n\n受付日時：{send_datetime}\n\n{form_data}\n\nこのメールは自動送信です。\n心当たりがない場合は、このメールを破棄してください。\n\n{site_name}\n{site_url}",
      'cf_omf_admin_title' => '【{site_name}】新しいお問い合わせ',
      'cf_omf_admin_mail' => "サイトからお問い合わせが届きました。\n\n受付日時：{send_datetime}\n\n{form_data}\n\n内容をご確認のうえ、ご対応ください。\n{site_url}",
    ];
  }

  public static function value(\WP_Post $post, string $key, string $value): string
  {
    return $post->post_status === 'auto-draft' && $value === '' ? (self::values()[$key] ?? $value) : $value;
  }

  public static function button(string $kind): void
  {
    echo '<p><button type="button" class="button" data-omf-mail-default="' . esc_attr($kind) . '">空欄に標準の件名・本文を入れる</button></p><p class="description">入力済みの件名・本文は変更しません。宛先・送信元は別途設定してください。</p>';
  }
  /** 本文欄の補足説明として、タグを簡潔に共通表示する。 */
  public static function tag_description(): string
  {
    return '<span class="omf-mail-tags">項目キーを <code>{name}</code> のように囲むと、入力値をメールに反映できます（コード方式ではname属性）。<br>'
      . '<code>{form_data}</code>：全項目を「ラベル：入力値」で一覧表示（画面でかんたんに作成・本文専用）。追加・並べ替えにも追従します。<br>'
      . '<code>{send_datetime}</code>：送信日時（Y/m/d (曜日) H:i）<br>'
      . '<code>{mail_id}</code>：メールID（連番）<br>'
      . '<code>{site_name}</code>：WordPressサイト名<br>'
      . '<code>{site_url}</code>：サイトURL</span>';
  }
}
