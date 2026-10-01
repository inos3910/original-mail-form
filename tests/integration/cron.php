<?php
// 隔離サイトだけで、認証付きHTTP起動を実送信前の捕捉と組み合わせて検証する。
if (!defined('OMF_INTEGRATION_TEST') || !OMF_INTEGRATION_TEST) { exit; }
use Sharesl\Original\MailForm\OMF_Delivery_Cron as Cron;
use Sharesl\Original\MailForm\OMF_Delivery as Delivery;
use Sharesl\Original\MailForm\OMF_Delivery_Store as Store;
$checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS: $label\n";};
$events=static function(){global $wpdb;return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'omf_test_event_%'");};
// 先行試験で残った待機・結果不明の本文を破棄し、この試験の受付だけを扱う。
foreach(Store::rows() as $row){Store::purge((int)$row['id']);}
wp_set_current_user(1);$command=Cron::command('command');$key=get_option(Cron::KEY);wp_set_current_user(0);
$check(is_string($command) && str_contains($command,'--header') && !str_contains($command,'--path='),'実WordPressの管理者だけがパス不要の登録用コマンドを生成');
$check(is_wp_error(Cron::command('command')),'未ログインのコマンド取得を拒否');
$call=static function($token,$probe=false,$method='POST'){
  $h=curl_init(rest_url('omf/v1/delivery-cron'));
  curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>40]);
  if($token!==null)curl_setopt($h,CURLOPT_HTTPHEADER,['X-OMF-Cron-Key: '.$token]);
  if($probe)curl_setopt($h,CURLOPT_POSTFIELDS,'probe=1');
  $body=curl_exec($h);$code=curl_getinfo($h,CURLINFO_HTTP_CODE);curl_close($h);return [$code,json_decode((string)$body,true)];
};
$before=$events();$last=get_option('omf_delivery_last_server_cron',0);
$check($call(null)[0]===401 && $call(str_repeat('0',64))[0]===401,'HTTPの認証なし・誤ったキーを拒否');
$check($call($key,false,'GET')[0]===404,'GETで配送処理を起動しない');
[$code,$body]=$call($key,true);wp_cache_delete('omf_delivery_cron_probe_at','options');wp_cache_delete('notoptions','options');
$check($code===200 && ($body['probe']??false)===true && get_option('omf_delivery_cron_probe_at',0)>0,'HTTP接続確認の成功日時を保存');
$check($events()===$before && get_option('omf_delivery_last_server_cron',0)===$last,'接続確認はメールも定期実行の成功日時も更新しない');
$form=(int)get_option('omf_test_form_id');
$original_mode=get_post_meta($form,'cf_omf_delivery_mode',true);
$original_mail=get_post_meta($form,'cf_omf_admin_mail',true);
$original_failure=get_option('omf_test_fail_admin',false);
update_option('omf_test_fail_admin',false);update_post_meta($form,'cf_omf_admin_mail','{message}');
$submit=static function($mode)use($form){
  update_post_meta($form,'cf_omf_delivery_mode',$mode);$_SESSION=['omf_token'=>bin2hex(random_bytes(12))];
  return Delivery::accept(['email'=>'queue@example.test','message'=>'HTTP cron試験','agree'=>'1'],$form,0,['tags'=>['email'=>'queue@example.test','message'=>'HTTP cron試験','agree'=>'1'],'attachment_paths'=>[],'attachment_ids'=>[]]);
};
$server=$submit('server_cron')['receipt_id'];$wordpress=$submit('wp_async')['receipt_id'];
[$code,$body]=$call($key);$row=Store::get($server);
$check($code===200 && ($body['ok']??false) && $row['reply']==='sent' && $row['admin']==='sent' && $row['effects']==='done' && $events()===$before+2,'HTTP起動でサーバーcronの2通と完了処理を実行');
$check(Store::get($wordpress)['reply']==='pending' && Store::get($wordpress)['admin']==='pending','HTTP起動はWordPress非同期の受付を処理しない');
$call($key);$check($events()===$before+2,'HTTP起動が重なっても成功済みメールを再送しない');
update_option(Cron::KEY,bin2hex(random_bytes(32)));
$check($call($key)[0]===401,'キーを再発行すると古いHTTPコマンドを拒否');
Store::purge($wordpress);
update_post_meta($form,'cf_omf_delivery_mode',$original_mode);
update_post_meta($form,'cf_omf_admin_mail',$original_mail);
update_option('omf_test_fail_admin',$original_failure);
echo "cron HTTP結合試験: {$checks}項目成功\n";
