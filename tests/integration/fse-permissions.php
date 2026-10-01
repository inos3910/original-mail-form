<?php
if (!defined('OMF_INTEGRATION_TEST')) { exit; }
$checks=0;
$check=static function($ok,$label) use (&$checks) { if (!$ok) { throw new RuntimeException($label); } $checks++; echo "PASS: {$label}\n"; };
$form=(int)get_option('omf_test_form_id');
wp_set_current_user(1);
$r=new WP_REST_Request('GET','/original-mail-form/v1/forms');
$r->set_param('id',$form);
$response=rest_do_request($r);
$data=$response->get_data();
$check($response->get_status()===200 && count($data)===1 && $data[0]['id']===$form,'エディターは公開フォームと項目見本を取得');
$check(!isset($data[0]['fields'][0]['default']) && session_status()!==PHP_SESSION_ACTIVE,'見本APIは入力値やセッションを生成しない');
$r=new WP_REST_Request('GET','/original-mail-form/v1/forms');
$r->set_param('search','コード方式');
$check(rest_do_request($r)->get_data()===[],'コード方式はエディターの候補外');
$r=new WP_REST_Request('GET','/original-mail-form/v1/forms');
$r->set_param('search','未公開フォーム');
$check(rest_do_request($r)->get_data()===[],'未公開フォームはエディターの候補外');
$subscriber=wp_create_user('fse-reader',wp_generate_password(),'fse-reader@example.test');
wp_set_current_user($subscriber);
$r=new WP_REST_Request('GET','/original-mail-form/v1/forms');
$check(rest_do_request($r)->get_status()===403,'購読者の検索を拒否');
wp_set_current_user(1);
$_SERVER['HTTP_X_OMF_POST_ID']=(string)get_page_by_path('fse-block')->ID;
$_SERVER['HTTP_X_WP_NONCE']=wp_create_nonce('wp_rest');
$r=new WP_REST_Request('POST','/omf-api/v0/send');
$check(rest_do_request($r)->get_status()===403,'FSEフォームは旧REST経路から送信できない');
echo "FSE権限結合試験: {$checks}項目成功\n";
