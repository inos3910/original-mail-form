<?php
if (!defined('OMF_INTEGRATION_TEST')) { exit; }
$ids = [];
foreach (['entry', 'confirm', 'complete', 'managed-shortcode', 'managed-custom', 'ordinary'] as $slug) {
  $ids[$slug] = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug]);
}
$form = wp_insert_post(['post_type' => 'original_mail_forms', 'post_status' => 'publish', 'post_title' => '結合試験', 'post_name' => 'integration']);
foreach (['entry', 'confirm', 'complete', 'managed-shortcode', 'managed-custom'] as $slug) {
  update_post_meta($ids[$slug], 'cf_omf_select', 'integration');
  update_post_meta($form, 'cf_omf_screen_' . $slug, $slug);
}
$meta = [
  'cf_omf_condition_post' => ['page'], 'cf_omf_save_db' => '1',
  'cf_omf_reply_to' => '{email}', 'cf_omf_reply_title' => '返信', 'cf_omf_reply_mail' => '{message}',
  'cf_omf_admin_to' => 'admin@example.test', 'cf_omf_admin_title' => '通知', 'cf_omf_admin_mail' => '{message}',
  'cf_omf_validation' => [
    ['target' => 'email', 'type' => 'text', 'required' => '1', 'email' => '1'],
    ['target' => 'message', 'type' => 'text', 'required' => '1'],
    ['target' => 'file', 'type' => 'file', 'extension' => ['txt']],
  ],
];
foreach ($meta as $key => $value) { update_post_meta($form, $key, $value); }
update_option('omf_is_rest_api', '1');
update_option('omf_turnstile_secret_key', 'fixture-only');
update_option('omf_test_form_id', $form);
update_option('permalink_structure', '/%postname%/');
switch_theme('omf-integration');
flush_rewrite_rules(false);
echo wp_json_encode(['pages' => $ids, 'form' => $form]);
