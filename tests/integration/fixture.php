<?php
// 専用の一時WordPressからだけ読み込む試験用フィクスチャ。
if (!defined('OMF_INTEGRATION_TEST') || !OMF_INTEGRATION_TEST) { exit; }
add_filter('pre_http_request', static function ($pre, $args, $url) {
  if (str_contains($url, '/siteverify')) {
    $valid = ($args['body']['response'] ?? '') === 'fixture-pass';
    return ['headers' => [], 'body' => wp_json_encode(['success' => $valid, 'hostname' => '127.0.0.1']), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
  }
  return new WP_Error('test_network_blocked', '結合試験では外部HTTP通信を禁止');
}, 10, 3);
add_filter('pre_wp_mail', static function ($pre, $mail) {
  $admin = in_array('admin@example.test', (array) $mail['to'], true);
  $failure = (get_option('omf_test_fail_admin') && $admin) || (get_option('omf_test_fail_reply') && !$admin);
  $delay=(int)get_option('omf_test_delay_us',0);
  if ($delay) { usleep($delay); }
  $records = get_option('omf_test_mail', []);
  $records[] = ['kind' => $admin ? 'admin' : 'reply', 'ok' => !$failure, 'attachments' => count((array) $mail['attachments']), 'message' => $mail['message']];
  update_option('omf_test_mail', $records, false);
  add_option('omf_test_event_'.bin2hex(random_bytes(10)),end($records),'',false);
  return !$failure;
}, 10, 2);
// メール配送は遮断するがWordPress本体のDB・フック・セッションは実物を使う。

add_action('omf_operation_timing', static function($operation,$ms,$form){ add_option('omf_test_timing_'.bin2hex(random_bytes(10)),['operation'=>$operation,'ms'=>$ms],'',false); },10,3);

function omf_test_records() { global $wpdb; return array_map('maybe_unserialize',$wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'omf_test_event_%' ORDER BY option_id")); }

// 別プラグイン・接頭辞が重なる別フォームの状態は対象フォームの清掃で消さない。
add_action('template_redirect', static function () {
  if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['other_plugin_state'] = 'preserved';
    $_SESSION['omf_integration_extra_data'] = ['message' => '別フォーム'];
  }
}, 1);
