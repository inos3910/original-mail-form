<?php
// 隔離したWordPress内だけで、実DB・実フック・実ループバックを検証する。
if (!defined('OMF_INTEGRATION_TEST') || !OMF_INTEGRATION_TEST) { exit; }
use Sharesl\Original\MailForm\OMF_Delivery as Delivery;
use Sharesl\Original\MailForm\OMF_Delivery_Store as Store;
use Sharesl\Original\MailForm\OMF_Delivery_Runner as Runner;
use Sharesl\Original\MailForm\OMF_Delivery_Admin as Admin;
use Sharesl\Original\MailForm\OMF_Field_Schema as Schema;
use Sharesl\Original\MailForm\OMF_Uploads as Uploads;
$checks=0;
$check=static function($ok,$label) use (&$checks) { if (!$ok) { throw new RuntimeException($label); } $checks++; echo "PASS: $label\n"; };
$form=(int)get_option('omf_test_form_id');
$events=static function(){ global $wpdb; return array_map('maybe_unserialize',$wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'omf_test_event_%' ORDER BY option_id")); };
$submit=static function($mode,$extra=[]) use ($form) {
  update_post_meta($form,'cf_omf_delivery_mode',$mode);
  $_SESSION=['omf_token'=>bin2hex(random_bytes(16))];
  $data=array_merge(['email'=>'queue@example.test','message'=>'配送試験','mail_id'=>'Q1'],$extra);
  return Delivery::accept($data,$form,4,['tags'=>$data,'attachment_paths'=>[],'attachment_ids'=>[]]);
};
// 動的な選択肢は正規化と検証にも反映され、不正な定義は拒否される。
$choices=static function($choices,$field){ return $field['key']==='choice' ? [['value'=>'dynamic','label'=>'外部選択肢']] : $choices; };
add_filter('omf_field_choices',$choices,10,2);
$schema=Schema::read($form); $check($schema['fields'][5]['choices'][0]['value']==='dynamic','外部選択肢フックを正規化して読込');
remove_filter('omf_field_choices',$choices,10);
$bad=static fn()=>['version'=>999,'fields'=>[]];add_filter('omf_field_schema',$bad);
$check(is_wp_error(Schema::read($form)),'フック由来の不正な項目定義も拒否');remove_filter('omf_field_schema',$bad);

$custom=static fn()=>['message'=>['独自チェックのエラー']];add_filter('omf_validation_errors',$custom);
$page=$GLOBALS['global_omf']->get_instance('page');
$errors=$page->validate_mail_form_data(['email'=>'bad','message'=>'入力','agree'=>'1'],4);
$check(isset($errors['email'],$errors['message']) && in_array('独自チェックのエラー',$errors['message'],true),'独自検証で組み込みエラーを消さず追加');remove_filter('omf_validation_errors',$custom);
$render=static fn()=>'<p>独自描画テスト</p>';add_filter('omf_field_html',$render);ob_start();
\Sharesl\Original\MailForm\OMF_Field_Renderer::render(Schema::read($form)['fields'][0],'',[],false,$form);
$check(ob_get_clean()==='<p>独自描画テスト</p>','項目描画をフックで差し替え');remove_filter('omf_field_html',$render);
$filter=static function($args){$args['to']='changed@example.test';$args['message']='宛先変更フック';$args['attachments']=['/etc/passwd'];return $args;};
add_filter('omf_mail_args',$filter);$captured=null;
$capture=static function($pre,$mail) use (&$captured){$captured=$mail;return $pre;};add_filter('pre_wp_mail',$capture,20,2);
\Sharesl\Original\MailForm\OMF_Mail_Transport::send('reply',$form,'before@example.test','試験','本文',[],[]);
$check($captured['to']==='changed@example.test' && $captured['attachments']===[],'宛先変更を適用しフックからの添付持ち出しを防止');
remove_filter('omf_mail_args',$filter);remove_filter('pre_wp_mail',$capture,20);
$completed=0;$hook=static function() use (&$completed){$completed++;};add_action('omf_delivery_completed',$hook);
$before=count($events()); $r=$submit('server_cron');$id=$r['receipt_id']; $row=Store::get($id);
$check($r['is_sended'] && $row['reply']==='pending' && count($events())===$before,'cron受付は永続保存後に完了し、メールは未送信');
$check(!str_contains($row['payload'],'queue@example.test') && Store::unpack($row)['admin_info']['tag_to_text']['message']==='配送試験','保存本文の暗号化と復号');
$key=$row['request_key']; $check(Store::create($key,$form,'server_cron',[])===$id,'同じ受付キーの重複保存を防止');
update_post_meta($form,'cf_omf_admin_mail','設定変更後の本文'); update_post_meta($form,'cf_omf_delivery_mode','serial');
Delivery::run('wp_async');$check(count($events())===$before,'WordPressワーカーはサーバーcronの受付を送らない');
Delivery::run('server_cron');$row=Store::get($id);$all=$events();
$check($row['reply']==='sent' && $row['admin']==='sent' && $row['effects']==='done' && count($all)===$before+2,'方式変更後も受付時の方式で2通を送信');
$check(end($all)['message']==='配送試験','受付後のメール設定変更で保存済み本文が変わらない');
$check($row['payload']==='','全処理成功後に配送用本文を消去');
$check($completed===1,'送信後連携フックを完了時に一度実行');
Delivery::run('server_cron');$check(count($events())===$before+2,'ワーカー再実行で成功済みメールを送らない');
update_post_meta($form,'cf_omf_admin_mail','{message}');
update_option('omf_test_fail_admin',true);$r=$submit('wp_async');$id=$r['receipt_id'];
Delivery::run('wp_async');$row=Store::get($id);$check($row['reply']==='sent' && $row['admin']==='failed','片側失敗を個別に保存');
$before=count($events());Delivery::run('wp_async');$check(count($events())===$before,'再試行の待機時間内は再送しない');
update_option('omf_test_fail_admin',false);$check(Store::retry($id,'admin'),'失敗だけを手動再試行対象に戻す');Delivery::run('wp_async');
$check(count($events())===$before+1 && Store::get($id)['admin']==='sent','成功済み自動返信を再送せず通知だけ再試行');
$check(!Store::retry($id,'reply'),'送信成功を再試行できない');
$r=$submit('server_cron');$id=$r['receipt_id'];$check(Store::claim($id,'reply') && !Store::claim($id,'reply'),'同一メールの競合取得は片方だけ成功');
global $wpdb;$wpdb->update(Store::table(),['reply_started'=>time()-121],['id'=>$id]);Store::recover();
$before=count($events());Delivery::run('server_cron');
$check(Store::get($id)['reply']==='unknown' && count($events())===$before && !Store::retry($id,'reply'),'中断は結果不明となり自動・手動再送を止める');
$check(Store::purge($id),'結果不明の保存内容を消去できる');
$r=$submit('server_cron');$id=$r['receipt_id'];Store::claim($id,'admin');$check(!Store::purge($id),'処理中の個人データ消去は競合を避け保留');
Store::result($id,'admin','failed');Store::purge($id);

$r=$submit('server_cron');$id=$r['receipt_id'];
$privacy=new \Sharesl\Original\MailForm\OMF_Privacy;
$check(count(Store::personal('queue@example.test'))>0 && count($privacy->export('queue@example.test')['data'])>0,'配送待ち本文を本人の個人データとして出力');
$privacy->erase('queue@example.test');$check(Store::get($id)===null,'本人の配送待ちを消去し送信を取り消す');
update_option('omf_test_fail_reply',true);$r=$submit('server_cron');$id=$r['receipt_id'];
for($i=0;$i<4;$i++){ $wpdb->update(Store::table(),['reply_next'=>0],['id'=>$id]);Delivery::run('server_cron'); }
$check((int)Store::get($id)['reply_attempts']===3,'自動再試行を3回で停止');
update_option('omf_test_fail_reply',false);Store::purge($id);
// 署名のない公開リクエストから送信を起動できない。
$request=new WP_REST_Request('POST','/omf/v1/delivery-worker');$request->set_param('id',1);$request->set_param('kind','reply');$request->set_param('expires',time()+30);
$check(!(new Runner)->authorize($request),'署名なしワーカー実行を拒否');
$ready=Runner::diagnose();$check($ready,'実ループバック2リクエストの同時処理を診断');
$check(is_wp_error(Admin::validate($form,['omf_delivery_mode'=>'parallel','cf_omf_admin_mail'=>'{omf_reply_mail_sended}'])),'自動返信結果タグに依存する並列設定を拒否');
update_option('omf_test_delay_us',400000);$before=count($events());$start=microtime(true);$r=$submit('server_cron');Delivery::run('server_cron');$serial=microtime(true)-$start;
$start=microtime(true);$r=$submit('parallel');$parallel=microtime(true)-$start;$row=Store::get($r['receipt_id']);
$check($r['is_sended'] && $row['reply']==='sent' && $row['admin']==='sent','実ループバックで並列送信し両方の結果を待つ');
$check($parallel<$serial && count($events())===$before+4,'同一遅延条件で並列化により待機時間を短縮');
echo '計測: 直列 '.round($serial*1000).'ms / 並列 '.round($parallel*1000)."ms（各メール400msの試験遅延）\n";
update_option('omf_test_delay_us',0);
update_option('omf_test_fail_admin',true);$r=$submit('parallel');$id=$r['receipt_id'];$row=Store::get($id);
$check(!$r['is_sended'] && $row['reply']==='sent' && $row['admin']==='failed','並列の片側失敗を完了扱いにしない');
update_option('omf_test_fail_admin',false);$before=count($events());Store::retry($id,'admin');Runner::parallel($id);
$check(count($events())===$before+1 && Store::get($id)['admin']==='sent','並列の再試行でも成功済みメールを再送しない');
update_post_meta($form,'cf_omf_disable_reply_mail','1');$before=count($events());$r=$submit('server_cron');Delivery::run('server_cron');
$check(Store::get($r['receipt_id'])['reply']==='skipped' && count($events())===$before+1,'非同期の自動返信OFFでは通知だけ送信');
update_post_meta($form,'cf_omf_disable_reply_mail','');

update_post_meta($form,'cf_omf_delivery_mode','serial');
echo "配送・フック結合試験: {$checks}項目成功\n";
