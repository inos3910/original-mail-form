<?php
if (!defined('OMF_INTEGRATION_TEST')) { exit; }
$form = (int) get_option('omf_test_form_id');
$block = '<!-- wp:original-mail-form/form {"formId":' . $form . ',"className":"custom-form","align":"wide"} /-->';
$shortcode = '<!-- wp:shortcode -->[original_mail_form id="' . $form . '"]<!-- /wp:shortcode -->';
$slug_shortcode = '<!-- wp:shortcode -->[omf_render_form slug="integration"]<!-- /wp:shortcode -->';
$pattern = wp_insert_post(['post_type'=>'wp_block','post_status'=>'publish','post_title'=>'フォームパターン','post_content'=>$block]);
$cycle = wp_insert_post(['post_type'=>'wp_block','post_status'=>'publish','post_title'=>'循環パターン']);
wp_update_post(['ID'=>$cycle,'post_content'=>'<!-- wp:core/block {"ref":'.$cycle.'} /-->']);
$draft = wp_insert_post(['post_type'=>'original_mail_forms','post_status'=>'draft','post_title'=>'未公開フォーム']);
$code = wp_insert_post(['post_type'=>'original_mail_forms','post_status'=>'publish','post_title'=>'コード方式']);
$contents = [
 'fse-block'=>$block, 'fse-shortcode'=>$shortcode, 'fse-slug-shortcode'=>$slug_shortcode,
 'fse-nested'=>'<!-- wp:group --><div class="wp-block-group">'.$block.'</div><!-- /wp:group -->',
 'fse-pattern'=>'<!-- wp:block {"ref":'.$pattern.'} /-->', 'fse-post'=>$block,
 'fse-duplicate'=>$block.$block, 'fse-mixed'=>$block.$shortcode,
 'fse-missing'=>'<!-- wp:original-mail-form/form {"formId":999999} /-->',
 'fse-empty'=>'<!-- wp:original-mail-form/form /-->',
 'fse-draft'=>'[original_mail_form id="'.$draft.'"]', 'fse-code'=>'[original_mail_form id="'.$code.'"]',
 'fse-query'=>'<!-- wp:query --><div class="wp-block-query">'.$block.'</div><!-- /wp:query -->',
 'fse-cycle'=>'<!-- wp:block {"ref":'.$cycle.'} /-->'.$block,
];
$ids=[];
$valid=['fse-block','fse-shortcode','fse-slug-shortcode','fse-nested','fse-pattern','fse-post'];
foreach($contents as $slug=>$content) {
 if (in_array($slug,$valid,true)) {
  $copy=wp_insert_post(['post_type'=>'original_mail_forms','post_status'=>'publish','post_title'=>$slug,'post_name'=>$slug.'-form']);
  foreach(get_post_meta($form) as $key=>$values) { update_post_meta($copy,$key,maybe_unserialize($values[0])); }
  $content=str_replace(['"formId":'.$form.',', '"formId":'.$form.'}', 'id="'.$form.'"', 'slug="integration"'],['"formId":'.$copy.',', '"formId":'.$copy.'}', 'id="'.$copy.'"', 'slug="'.$slug.'-form"'],$content);
  if ($slug==='fse-pattern') {
   $ref=wp_insert_post(['post_type'=>'wp_block','post_status'=>'publish','post_title'=>'専用パターン','post_content'=>'<!-- wp:original-mail-form/form {"formId":'.$copy.'} /-->']);
   $content='<!-- wp:block {"ref":'.$ref.'} /-->';
  }
  foreach(['entry'=>'','confirm'=>'-confirm','complete'=>'-complete'] as $step=>$suffix) {
   $id=wp_insert_post(['post_type'=>$slug==='fse-post'?'post':'page','post_status'=>'publish','post_name'=>$slug.$suffix,'post_title'=>$slug.$suffix,'post_content'=>$content]);
   $ids[$slug.$suffix]=$id;
   update_post_meta($copy,'cf_omf_screen_'.$step,$slug.$suffix);
  }
  update_post_meta($copy,'cf_omf_condition_post',['page','post']);
  $ids[$slug.'-form']=$copy;
 } else {
  $ids[$slug]=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_name'=>$slug,'post_title'=>$slug,'post_content'=>$content]);
 }
}
update_post_meta($ids['fse-block'],'cf_omf_select','nonexistent-old-form');
switch_theme('omf-fse'); flush_rewrite_rules(false);
echo wp_json_encode($ids);
