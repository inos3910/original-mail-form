<?php
require __DIR__ . '/port-helpers.php';
use Sharesl\Original\MailForm\OMF_Port_Admin;
use Sharesl\Original\MailForm\OMF_Port_Apply;
use Sharesl\Original\MailForm\OMF_Port_Export;
use Sharesl\Original\MailForm\OMF_Port_Plan;
use Sharesl\Original\MailForm\OMF_Port_Reader;

build_source();
$plain=export_file(['settings'=>true,'forms'=>'all']);
check($plain['format']==='omf-export'&&$plain['version']===1&&!array_key_exists('includes_secrets',$plain)&&$plain['plugin_version']==='1.2.0','書き出しのヘッダを持ち、includes_secrets は出さない');
check(!isset($plain['settings']['omf_recaptcha_site_key'])&&!isset($plain['settings']['omf_recaptcha_secret_key'])&&!isset($plain['settings']['omf_turnstile_site_key'])&&isset($plain['settings']['omf_is_rest_api']),'認証キーなどの秘密情報のオプションは書き出さない');
check(!isset($plain['settings']['omf_google_redirect_uri'])&&!isset($plain['settings']['omf_delivery_schema']),'環境依存・内部状態のオプションは書き出さない');
check(!isset($plain['forms'][0]['meta']['cf_omf_slack_webhook_url']),'Slack Webhook URLは既定で書き出さない');
check(!isset($plain['forms'][0]['meta']['cf_omf_mail_id'])&&!isset($plain['forms'][0]['meta']['cf_omf_condition_id'])&&!isset($plain['forms'][0]['meta']['other_meta']),'cf_omf_mail_id・cf_omf_condition_id・cf_omf_以外のメタは書き出さない');
check($plain['forms'][0]['condition_targets']===[['post_type'=>'page','path'=>'contact'],['post_type'=>'page','path'=>'about/team']],'表示条件のIDを投稿タイプ+パスに変換し、存在しないIDは除く');
check($plain['forms'][0]['linked_pages']===[['post_type'=>'page','path'=>'contact'],['post_type'=>'page','path'=>'about/team']],'連携ページをパスで書き出す');
check(!str_contains(json_encode($plain),'SECRET')&&!str_contains(json_encode($plain),'hooks.example.test')&&!str_contains(json_encode($plain),'only-site'),'書き出しJSON全体に秘密情報の値が含まれない');
check($plain['forms'][0]['parts']===['info','validation','screen','mail','other']&&!isset($plain['forms'][0]['meta']['cf_omf_data_memo']),'全項目の書き出しは parts を持ち、メモは出さない');
$secret=$plain;
check(!isset(export_file(['settings'=>false,'forms'=>[10]])['settings'])&&!isset(export_file(['settings'=>true,'forms'=>null])['forms']),'設定・フォームは選んだものだけ書き出す');

build_target();
$result=import_json($secret);
$new=get_page_by_path('contact-form',OBJECT,'original_mail_forms');
check($result['created']===1&&$result['updated']===0&&$new&&$new->ID===900,'同じスラッグが無ければ新規作成する');
check($GLOBALS['meta'][900]['cf_omf_condition_id']==='501,502','表示条件を取り込み先のページIDへ戻す');
check($GLOBALS['meta'][501]['cf_omf_select']==='contact-form'&&$GLOBALS['meta'][502]['cf_omf_select']==='contact-form','連携ページを取り込み先のページへ設定する');
check(!isset($GLOBALS['meta'][900]['cf_omf_mail_id'])&&!isset($GLOBALS['meta'][900]['cf_omf_slack_webhook_url'])&&$GLOBALS['meta'][900]['cf_omf_form_mode']==='builder','新規作成ではメールIDとWebhook URLを持たず、ファイルのメタを設定する');
check(!isset($GLOBALS['options']['omf_recaptcha_secret_key'])&&$GLOBALS['options']['omf_retention_days']==='30','設定を取り込み、認証キーは取り込まない');

// 同じスラッグは上書き。IDとメールIDを維持し、ファイルに無い秘密情報は残す
build_target();
add_test_post(700,'original_mail_forms','contact-form','古い名前','draft');
$GLOBALS['meta'][700]=['cf_omf_mail_id'=>'5','cf_omf_slack_webhook_url'=>'https://keep.example.test','cf_omf_admin_title'=>'古い件名','cf_omf_old_only'=>'消える','cf_omf_condition_id'=>'777','unrelated_meta'=>'残る'];
$removal_plan=OMF_Port_Plan::build(OMF_Port_Reader::read(json_encode($plain)));
check($removal_plan['forms'][0]['removals']===['cf_omf_old_only'],'上書きで削除されるメタは、ファイルに無い cf_omf_ だけ（メールID・秘密情報・表示条件ID・他プラグインのメタは対象外）');
$view=render_template('omf_port_confirm.php',['omf_port_result'=>null,'omf_port_pending'=>['token'=>'tok','payload'=>OMF_Port_Reader::read(json_encode($plain))]]);
check(str_contains($view,'ファイルに無いため削除する設定：cf_omf_old_only'),'確認画面に削除されるメタのキー名を出す');
$GLOBALS['options']['omf_recaptcha_site_key']='KEEP_SITE'; $GLOBALS['options']['omf_recaptcha_secret_key']='KEEP_SECRET';
$result=import_json($plain);
check($result['updated']===1&&$result['created']===0&&form_count()===1,'同じスラッグのフォームは上書きし、増やさない');
check($GLOBALS['wp_posts'][700]->post_title==='お問い合わせ'&&$GLOBALS['wp_posts'][700]->post_status==='publish'&&$GLOBALS['meta'][700]['cf_omf_admin_title']==='件名\\テスト','上書きでタイトル・ステータス・メタが更新される');
check($GLOBALS['meta'][700]['cf_omf_mail_id']==='5'&&$GLOBALS['wp_posts'][700]->ID===700,'上書きでも記事IDとメールIDは取り込み先のまま');
check(!isset($GLOBALS['meta'][700]['cf_omf_old_only'])&&$GLOBALS['meta'][700]['unrelated_meta']==='残る'&&$GLOBALS['meta'][700]['cf_omf_condition_id']==='501,502','上書きでファイルに無い cf_omf_ メタが消え、他のメタは残る');
check($GLOBALS['meta'][700]['cf_omf_slack_webhook_url']==='https://keep.example.test'&&$GLOBALS['options']['omf_recaptcha_site_key']==='KEEP_SITE'&&$GLOBALS['options']['omf_recaptcha_secret_key']==='KEEP_SECRET','ファイルに無い秘密情報は取り込み先の値を残す');

// 秘密情報は常に残す。condition_targetsが無ければ表示条件IDに触れない
build_target();
add_test_post(700,'original_mail_forms','contact-form','古い名前','draft');
$GLOBALS['meta'][700]=['cf_omf_mail_id'=>'5','cf_omf_slack_webhook_url'=>'https://old.example.test','cf_omf_condition_id'=>'777'];
$no_condition=$plain; unset($no_condition['forms'][0]['condition_targets']);
import_json($no_condition);
check($GLOBALS['meta'][700]['cf_omf_slack_webhook_url']==='https://old.example.test'&&$GLOBALS['meta'][700]['cf_omf_mail_id']==='5'&&$GLOBALS['meta'][700]['cf_omf_condition_id']==='777','秘密情報とメールIDは残り、condition_targets無しの表示条件IDも残す');

// ID→パス→IDの往復と、見つからないページの警告
build_target(); unset($GLOBALS['wp_posts'][502]);
$payload=OMF_Port_Reader::read(json_encode($plain));
$plan=OMF_Port_Plan::build($payload);
check($plan['forms'][0]['action']==='create'&&$plan['forms'][0]['conditions']['ids']===[501]&&count($plan['warnings'])===2,'見つからないページは確認画面の計画で警告し、見つかったものだけ解決する');
$result=OMF_Port_Apply::run($plan);
check($GLOBALS['meta'][900]['cf_omf_condition_id']==='501'&&count($result['warnings'])===2,'実行結果にも警告が残り、見つかったIDだけ設定する');
check($plan['settings']['changes']['omf_is_rest_api']['new']==='1'&&!isset($plan['settings']['changes']['omf_recaptcha_secret_key']),'計画に秘密情報の設定は出ない');

// 許可外キーの無視と警告、ファイルの拒否
build_target();
$file=$plain; $file['settings']['siteurl']='https://evil.test'; $file['settings']['omf_google_redirect_uri']='https://evil.test/cb'; $file['forms'][0]['meta']['evil_key']='x'; $file['forms'][0]['meta']['cf_omf_mail_id']='99'; $file['forms'][0]['meta']['cf_omf_condition_id']='1,2';
$payload=OMF_Port_Reader::read(json_encode($file));
check(!isset($payload['settings']['siteurl'])&&!isset($payload['settings']['omf_google_redirect_uri'])&&!isset($payload['forms'][0]['meta']['evil_key'])&&!isset($payload['forms'][0]['meta']['cf_omf_mail_id'])&&count($payload['warnings'])===5,'許可外のオプション・メタは無視して警告する');
import_json($file);
check(!isset($GLOBALS['options']['siteurl'])&&!isset($GLOBALS['meta'][900]['cf_omf_mail_id'])&&$GLOBALS['meta'][900]['cf_omf_condition_id']==='501,502','許可外の値は書き込まれない');
foreach ([['format'=>'other'],['version'=>2],['version'=>'1']] as $i=>$patch) {
  check(is_wp_error(OMF_Port_Reader::read(json_encode($patch+$plain))),'format/versionが一致しないファイルを拒否する('.$i.')');
}
check(is_wp_error(OMF_Port_Reader::read('{broken')),'JSONでないファイルを拒否する');
check(is_wp_error(OMF_Port_Reader::read(str_repeat(' ',2097153))),'2MBを超えるファイルを拒否する');
$only_forms=$plain; unset($only_forms['settings']);
build_target(); $GLOBALS['options']['omf_retention_days']='9';
import_json($only_forms);
check($GLOBALS['options']['omf_retention_days']==='9','settingsキーが無いファイルでは設定に触れない');

// 不正な項目定義はそのフォームだけ中止する
build_target();
$mixed=$plain; $bad=$plain['forms'][0]; $bad['slug']='broken-form'; $bad['meta']['cf_omf_field_schema']=['version'=>2,'fields'=>[]]; $mixed['forms'][]=$bad;
$result=import_json($mixed);
check($result['created']===1&&count($result['blocked'])===1&&$result['blocked'][0]['slug']==='broken-form'&&str_contains($result['blocked'][0]['errors'][0],'項目定義が不正'),'不正なfield_schemaのフォームは理由つきで中止し、他のフォームは取り込む');
check(get_page_by_path('broken-form',OBJECT,'original_mail_forms')===null&&form_count()===1,'中止したフォームは作成されない');

// 管理画面の入口：権限・nonce・確認と実行の一時データ
$_SERVER['REQUEST_METHOD']='POST'; $GLOBALS['can_edit']=false; $GLOBALS['admin_nonce']=true;
$denied=false; try { OMF_Port_Admin::handle_run(); } catch (RuntimeException $e) { $denied=str_contains($e->getMessage(),'権限'); }
check($denied,'manage_options権限が無ければ拒否する');
$GLOBALS['can_edit']=true; $GLOBALS['admin_nonce']=false;
$denied=false; try { OMF_Port_Admin::handle_run(); } catch (RuntimeException $e) { $denied=$e->getMessage()==='nonce拒否'; }
check($denied,'nonceが無ければ拒否する');
$GLOBALS['admin_nonce']=true; build_target();
set_transient('omf_port_pending_1',['token'=>'abc','payload'=>OMF_Port_Reader::read(json_encode($plain))],3600);
$_POST['omf_port_token']='wrong'; $redirect='';
try { OMF_Port_Admin::handle_run(); } catch (RuntimeException $e) { $redirect=$e->getMessage(); }
check(form_count()===0&&OMF_Port_Admin::pending()!==null&&str_contains($redirect,'redirect:'),'トークンが違えば実行せず、一時データも残す');
$_POST['omf_port_token']='abc';
try { OMF_Port_Admin::handle_run(); } catch (RuntimeException $e) { $redirect=$e->getMessage(); }
check(form_count()===1&&OMF_Port_Admin::pending()===null&&str_ends_with($redirect,'step=result')&&OMF_Port_Admin::take('omf_port_result_')['created']===1,'確認後の実行で取り込み、一時データを削除して結果を渡す');

// 画面テンプレートが警告なく描画できること（確認画面・結果画面・入口画面）
build_target(); unset($GLOBALS['wp_posts'][502]);
$view=render_template('omf_port_confirm.php',['omf_port_result'=>null,'omf_port_pending'=>['token'=>'tok','payload'=>OMF_Port_Reader::read(json_encode($secret))]]);
check(str_contains($view,'contact-form')&&str_contains($view,'新規作成')&&str_contains($view,'見つかりません')&&str_contains($view,'omf_port_run'),'確認画面に新規／上書き・警告・実行ボタンを出す');
$view=render_template('omf_port_confirm.php',['omf_port_result'=>['created'=>1,'updated'=>0,'settings'=>2,'blocked'=>[['slug'=>'x','errors'=>['理由']]],'warnings'=>['注意']],'omf_port_pending'=>null]);
check(str_contains($view,'取り込み結果')&&str_contains($view,'理由')&&str_contains($view,'注意'),'結果画面に件数・中止理由・警告を出す');
$_GET=[];
$view=render_template('omf_port.php');
check(str_contains($view,'omf_port_export')&&str_contains($view,'omf_port_upload'),'入口画面に書き出し・取り込みの操作を出す');
echo "エクスポート・インポートのテスト完了\n";
