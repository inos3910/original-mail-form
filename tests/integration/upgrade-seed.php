<?php
if (!defined('OMF_INTEGRATION_TEST')) { exit; }
$entry = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'入力','post_name'=>'contact']);
$confirm = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'確認','post_name'=>'confirm','post_parent'=>$entry]);
$complete = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'完了','post_name'=>'complete','post_parent'=>$entry]);
$form = wp_insert_post(['post_type'=>'original_mail_forms','post_status'=>'publish','post_title'=>'載せ替え試験','post_name'=>'contact']);
foreach ([$entry,$confirm,$complete] as $id) { update_post_meta($id,'cf_omf_select','contact'); }
$meta = ['cf_omf_condition_post'=>['page'],'cf_omf_condition_id'=>"$entry,$confirm,$complete",'cf_omf_screen_entry'=>'/contact/','cf_omf_screen_confirm'=>'/contact/confirm/','cf_omf_screen_complete'=>'/contact/complete/','cf_omf_save_db'=>'1','cf_omf_reply_to'=>'{email}','cf_omf_reply_title'=>'返信','cf_omf_reply_mail'=>'{message}|{communication}|{file}','cf_omf_reply_from'=>'sender@example.test','cf_omf_reply_from_name'=>'試験','cf_omf_admin_to'=>'admin@example.test','cf_omf_admin_title'=>'通知','cf_omf_admin_mail'=>'{message}|{communication}|{file}','cf_omf_admin_from'=>'sender@example.test','cf_omf_admin_from_name'=>'試験',
  // 原型の保存形式。任意の連絡方法・hidden・添付には検証設定を作らない。
  'cf_omf_validation'=>[['target'=>'username','required'=>'1','file_size'=>'536870912'],['target'=>'email','email'=>'1','file_size'=>'536870912'],['target'=>'message','required'=>'1','file_size'=>'536870912'],['target'=>'privacy','required'=>'1','matching_char'=>'同意する','file_size'=>'536870912']]];
foreach ($meta as $key=>$value) { update_post_meta($form,$key,$value); }
update_option('upgrade_form',$form); update_option('omf_is_rest_api','1');
update_option('permalink_structure','/%postname%/'); switch_theme('omf-upgrade'); flush_rewrite_rules(false);
echo wp_json_encode(['form'=>$form,'entry'=>$entry,'confirm'=>$confirm,'complete'=>$complete]);
