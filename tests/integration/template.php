<?php
if (!defined('OMF_INTEGRATION_TEST')) { exit; }
use Sharesl\Original\MailForm\OMF;
header('Content-Type: text/html; charset=utf-8');
$linked = $GLOBALS['global_omf']->get_instance('page')->get_form(get_queried_object_id());
// 連携OFFでも管理画面方式の試験を旧HTML方式へ切り替えず、公開APIの無効判定を通す。
if (\Sharesl\Original\MailForm\OMF_Managed_Form::enabled((int) get_option('omf_test_form_id'))) {
  echo '<!doctype html><html lang="ja"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>フォーム自動出力デモ</title>';
  wp_head();
  echo '</head><body>';
  if (get_option('omf_test_render_mode') === 'theme') {
    $context = OMF::form_context(['slug'=>'integration']);
    if (is_wp_error($context)) { echo esc_html($context->get_error_message()); }
    else { include get_stylesheet_directory() . '/theme-' . $context['step'] . '.php'; }
  } elseif (get_option('omf_test_render_mode') === 'custom') {
    OMF::render_form(['slug' => 'integration', 'fields_template' => get_stylesheet_directory() . '/custom-fields.php', 'complete_template' => get_stylesheet_directory() . '/custom-complete.php']);
  } elseif (get_option('omf_test_render_mode') === 'shortcode') {
    echo do_shortcode('[omf_render_form slug="integration"]');
  } else {
    OMF::render_form(['slug' => 'integration']);
  }
  wp_footer();
  echo '</body></html>';
  return;
}
$data = OMF::get_post_values();
$errors = OMF::get_errors();
$slug = get_post_field('post_name', get_queried_object_id());
echo '<!doctype html><html lang="ja"><title>結合試験</title><body>';
echo '<h1>' . esc_html($slug) . '</h1>';
echo '<pre id="data">' . esc_html(wp_json_encode($data, JSON_UNESCAPED_UNICODE)) . '</pre>';
echo '<pre id="errors">' . esc_html(wp_json_encode($errors, JSON_UNESCAPED_UNICODE)) . '</pre>';
if (in_array($slug, ['entry', 'confirm'], true)) {
  echo '<form method="post" enctype="multipart/form-data">';
  OMF::nonce_field();
  echo '<input name="email"><textarea name="message"></textarea><input type="file" name="file">';
  echo '<button name="confirm" value="confirm">確認</button><button name="send" value="send">送信</button></form>';
  echo '<meta name="rest-nonce" content="' . esc_attr(wp_create_nonce('wp_rest')) . '">';
}
echo '</body></html>';
