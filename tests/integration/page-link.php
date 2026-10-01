<?php
if (!defined('OMF_INTEGRATION_TEST')) { exit; }
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$checks = 0;
$check = static function ($ok, $label) use (&$checks) {
  if (!$ok) { throw new RuntimeException($label); }
  $checks++; echo "PASS: {$label}\n";
};
$admin = $GLOBALS['global_omf']->get_instance('admin');
$page_api = $GLOBALS['global_omf']->get_instance('page');
$page = get_page_by_path('entry');
$form = (int) get_option('omf_test_form_id');
$created = [];
$make = static function ($slug, $types, $ids = '') use (&$created) {
  $id = wp_insert_post(['post_type' => 'original_mail_forms', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'menu_order' => -1000 + count($created)]);
  $created[] = $id;
  if ($types) { update_post_meta($id, 'cf_omf_condition_post', $types); }
  update_post_meta($id, 'cf_omf_condition_id', $ids);
  return $id;
};
wp_set_current_user(1);
set_current_screen('page');
try {
  $make('link-unconfigured', []);
  $make('link-other-type', ['post']);
  $make('link-other-id', ['page'], (string) ($page->ID + 100000));
  $make('link-other-path', ['page']);
  $by_id = $make('link-id-match', ['page'], ' 999999, ' . $page->ID . ' ');
  update_post_meta($by_id, 'cf_omf_screen_entry', 'entry');
  $GLOBALS['wp_meta_boxes'] = [];
  do_action('add_meta_boxes', 'page', $page);
  $check(isset($GLOBALS['wp_meta_boxes']['page']['side']['default']['omf-metabox-link_form']), '先行フォームが未設定・不一致でも連携欄を登録');
  ob_start(); $admin->select_mail_form_meta_box_callback($page); $html = ob_get_clean();
  $check(str_contains($html, 'value="integration"'), '画面パスに合うフォームを選択肢に表示');
  $check(str_contains($html, 'value="link-id-match"'), '投稿ID条件・画面パスとも合うフォームを選択肢に表示');
  $check(!str_contains($html, 'value="link-other-') && !str_contains($html, 'value="link-unconfigured"'), '投稿タイプ・ID・画面パスの不一致フォームを選択肢から除外');
  $check(str_contains($html, 'name="omf_meta_nonce"') && str_contains($html, 'value=""'), '連携欄自身に保存nonceと連携しない選択肢を出力');
  $unrelated = get_page_by_path('ordinary');
  $GLOBALS['wp_meta_boxes'] = [];
  $admin->add_meta_box_posts('page', $unrelated);
  $check(!isset($GLOBALS['wp_meta_boxes']['page']['side']['default']['omf-metabox-link_form']), '表示条件に合わないページには連携欄を出さない');
  $_POST = ['omf_meta_nonce' => wp_create_nonce('omf_save_meta'), 'cf_omf_select' => ''];
  $admin->save_omf_custom_field($page->ID);
  $check(get_post_meta($page->ID, 'cf_omf_select', true) === '' && !$page_api->get_form($page->ID), '連携しない保存後はPHPテンプレートのフォームを無効化');
  $_POST['cf_omf_select'] = 'integration';
  $admin->save_omf_custom_field($page->ID);
  $check($page_api->get_form($page->ID)->ID === $form, '連携する保存後は選択したフォームを有効化');
  unset($_POST['omf_meta_nonce']); $_POST['cf_omf_select'] = '';
  $admin->save_omf_custom_field($page->ID);
  $check(get_post_meta($page->ID, 'cf_omf_select', true) === 'integration', 'nonceなしでは連携設定を変更できない');
  wp_set_current_user(0); $_POST['omf_meta_nonce'] = wp_create_nonce('omf_save_meta');
  $admin->save_omf_custom_field($page->ID);
  $check(get_post_meta($page->ID, 'cf_omf_select', true) === 'integration', '編集権限なしでは連携設定を変更できない');
} finally {
  wp_set_current_user(1); $_POST = [];
  update_post_meta($page->ID, 'cf_omf_select', 'integration');
  foreach ($created as $id) { wp_delete_post($id, true); }
}
echo "ページ連携結合試験: {$checks}項目成功\n";
