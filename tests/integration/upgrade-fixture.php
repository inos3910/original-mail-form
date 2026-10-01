<?php
// 原型からの載せ替え試験用。実送信・外部通信・実サイトの設定参照を行わない。
if (!defined('OMF_INTEGRATION_TEST') || !OMF_INTEGRATION_TEST) { exit; }
add_filter('pre_http_request', static function ($pre, $args, $url) {
  // WordPressの更新後の再確認も固定応答とし、ネット遮断由来の警告を出さない。
  if (str_starts_with($url, 'https://api.wordpress.org/') || str_starts_with($url, 'http://api.wordpress.org/')) {
    return ['headers' => [], 'body' => wp_json_encode(['offers' => [], 'plugins' => [], 'themes' => [], 'no_update' => [], 'translations' => []]), 'response' => ['code' => 200], 'cookies' => []];
  }
  if (str_contains($url, '/siteverify')) {
    return ['headers' => [], 'body' => wp_json_encode(['success' => ($args['body']['response'] ?? '') === 'fixture-pass', 'hostname' => '127.0.0.1', 'score' => 0.9]), 'response' => ['code' => 200], 'cookies' => []];
  }
  return new WP_Error('test_network_blocked', '試験中は外部通信禁止');
}, 10, 3);
add_filter('pre_wp_mail', static function ($pre, $mail) {
  $items = get_option('upgrade_mail', []);
  $items[] = ['message' => $mail['message'], 'attachments' => count((array) $mail['attachments'])];
  update_option('upgrade_mail', $items, false);
  return !get_option('upgrade_mail_fail');
}, 10, 2);
add_action('omf_after_send_mail', static function ($data, $form, $page) { update_option('upgrade_hook', $data, false); }, 10, 3);
function prefectures() { return ['大阪府', '京都府']; }
function add_validation_error_html($key, $errors = []) {
  foreach ($errors[$key] ?? [] as $value) { echo '<div class="error">' . esc_html($value) . '</div>'; }
}
