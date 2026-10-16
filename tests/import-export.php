<?php
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Port_Admin;
use Sharesl\Original\MailForm\OMF_Port_Apply;
use Sharesl\Original\MailForm\OMF_Port_Export;
use Sharesl\Original\MailForm\OMF_Port_Plan;
use Sharesl\Original\MailForm\OMF_Port_Reader;

// エクスポート・インポート用の最小スタブ。投稿はメモリ上の $GLOBALS['wp_posts'] に持つ。
class TestPost extends WP_Post { public $post_title = ''; public $uri = ''; }
function wp_slash($v){return $v;}
function sanitize_title($v){return trim(preg_replace('/[^a-z0-9_%\-]+/','-',strtolower($v)),'-');}
function wp_date($format){return date($format,1760000000);}
function get_file_data($file,$headers){return ['version'=>'1.2.0'];}
function post_type_exists($type){return in_array($type,['post','page','original_mail_forms'],true);}
function get_page_uri($post){return $post->uri!==''?$post->uri:$post->post_name;}
function delete_post_meta($id,$key){unset($GLOBALS['meta'][$id][$key]);return true;}
function get_post_custom_keys($id){return array_keys($GLOBALS['meta'][$id]??[]);}
function admin_url($path=''){return 'https://example.test/wp-admin/'.$path;}
function wp_safe_redirect($url){throw new RuntimeException('redirect:'.$url);}
function get_posts($args){
  $found=[];
  foreach($GLOBALS['wp_posts'] as $p){
    $type=$args['post_type']??'post';
    if($type!=='any'&&$p->post_type!==$type){continue;}
    if(isset($args['name'])&&$p->post_name!==$args['name']){continue;}
    if(isset($args['post__in'])&&!in_array($p->ID,$args['post__in'],true)){continue;}
    if(isset($args['meta_key'])&&($GLOBALS['meta'][$p->ID][$args['meta_key']]??null)!==$args['meta_value']){continue;}
    if(is_array($args['post_status']??null)&&!in_array($p->post_status,$args['post_status'],true)){continue;}
    $found[]=$p;
  }
  return $found;
}
function wp_insert_post($post,$wp_error=false){$id=$GLOBALS['next_id']++;add_test_post($id,$post['post_type'],$post['post_name'],$post['post_title'],$post['post_status']);return $id;}
function wp_update_post($post,$wp_error=false){$p=$GLOBALS['wp_posts'][$post['ID']];$p->post_title=$post['post_title'];$p->post_status=$post['post_status'];return $p->ID;}
function add_test_post($id,$type,$slug,$title='',$status='publish',$uri=''){$p=new TestPost();$p->ID=$id;$p->post_type=$type;$p->post_name=$slug;$p->post_title=$title;$p->post_status=$status;$p->uri=$uri;$GLOBALS['wp_posts'][$id]=$p;}
function esc_attr($s){return htmlspecialchars((string)$s);}
function submit_button($text,...$args){echo '<button>'.esc_html($text).'</button>';}
function wp_nonce_field($action){echo '<input type="hidden" name="_wpnonce" value="valid">';}
function render_template(string $name, array $vars = []): string {extract($vars);ob_start();include dirname(__DIR__).'/templates/'.$name;return (string)ob_get_clean();}
function reset_env(){$GLOBALS['options']=[];$GLOBALS['meta']=[];$GLOBALS['wp_posts']=[];$GLOBALS['next_id']=900;$GLOBALS['strict_pages']=true;}
function form_count(){return count(array_filter($GLOBALS['wp_posts'],fn($p)=>$p->post_type==='original_mail_forms'));}
function valid_schema(){return ['version'=>1,'fields'=>[['key'=>'message','type'=>'text','label'=>'内容','required'=>true]]];}
function export_file(array $options): array {return json_decode(OMF_Port_Export::to_json(OMF_Port_Export::build($options)),true);}
function import_json(array $file): array {$payload=OMF_Port_Reader::read(json_encode($file));if(is_wp_error($payload)){throw new RuntimeException('読み込み失敗:'.$payload->message);}return OMF_Port_Apply::run(OMF_Port_Plan::build($payload));}

// 書き出し元の環境
function build_source(): void {
  reset_env();
  $GLOBALS['options']=['omf_is_rest_api'=>'1','omf_retention_days'=>'30','omf_recaptcha_score'=>'0.7','omf_recaptcha_field_name'=>'g-recaptcha-response','omf_recaptcha_site_key'=>'SITE','omf_recaptcha_secret_key'=>'SECRET','omf_turnstile_site_key'=>'only-site','omf_google_redirect_uri'=>'https://src.test/cb','omf_delivery_schema'=>'x'];
  add_test_post(101,'page','contact','お問い合わせ','publish');
  add_test_post(102,'page','team','チーム','publish','about/team');
  add_test_post(10,'original_mail_forms','contact-form','お問い合わせ','publish');
  $GLOBALS['meta'][10]=['cf_omf_mail_id'=>'7','cf_omf_slack_webhook_url'=>'https://hooks.example.test/secret','cf_omf_condition_id'=>'101,102,999','cf_omf_condition_post'=>['page'],'cf_omf_admin_title'=>'件名\\テスト','cf_omf_field_schema'=>valid_schema(),'cf_omf_form_mode'=>'builder','other_meta'=>'対象外'];
  $GLOBALS['meta'][101]['cf_omf_select']='contact-form';
  $GLOBALS['meta'][102]['cf_omf_select']='contact-form';
}

build_source();
$plain=export_file(['settings'=>true,'forms'=>'all','secrets'=>false]);
check($plain['format']==='omf-export'&&$plain['version']===1&&$plain['includes_secrets']===false&&$plain['plugin_version']==='1.2.0','書き出しのヘッダを持つ');
check(!isset($plain['settings']['omf_recaptcha_site_key'])&&!isset($plain['settings']['omf_recaptcha_secret_key'])&&isset($plain['settings']['omf_is_rest_api']),'秘密情報は既定で書き出さない');
check(!isset($plain['settings']['omf_google_redirect_uri'])&&!isset($plain['settings']['omf_delivery_schema']),'環境依存・内部状態のオプションは書き出さない');
check(!isset($plain['forms'][0]['meta']['cf_omf_slack_webhook_url']),'Slack Webhook URLは既定で書き出さない');
check(!isset($plain['forms'][0]['meta']['cf_omf_mail_id'])&&!isset($plain['forms'][0]['meta']['cf_omf_condition_id'])&&!isset($plain['forms'][0]['meta']['other_meta']),'cf_omf_mail_id・cf_omf_condition_id・cf_omf_以外のメタは書き出さない');
check($plain['forms'][0]['condition_targets']===[['post_type'=>'page','path'=>'contact'],['post_type'=>'page','path'=>'about/team']],'表示条件のIDを投稿タイプ+パスに変換し、存在しないIDは除く');
check($plain['forms'][0]['linked_pages']===[['post_type'=>'page','path'=>'contact'],['post_type'=>'page','path'=>'about/team']],'連携ページをパスで書き出す');
$secret=export_file(['settings'=>true,'forms'=>'all','secrets'=>true]);
check($secret['includes_secrets']===true&&$secret['settings']['omf_recaptcha_site_key']==='SITE'&&$secret['settings']['omf_recaptcha_secret_key']==='SECRET','秘密情報を含めると認証キーの組を書き出す');
check(!isset($secret['settings']['omf_turnstile_site_key'])&&$secret['forms'][0]['meta']['cf_omf_slack_webhook_url']==='https://hooks.example.test/secret','片方しか無い認証キーは書き出さず、Webhook URLは含める');
check(!isset(export_file(['settings'=>false,'forms'=>[10],'secrets'=>false])['settings'])&&!isset(export_file(['settings'=>true,'forms'=>null,'secrets'=>false])['forms']),'設定・フォームは選んだものだけ書き出す');

// 書き出し先とは記事IDが違う取り込み先
function build_target(): void {
  reset_env();
  add_test_post(501,'page','contact','お問い合わせ','publish');
  add_test_post(502,'page','team','チーム','publish','about/team');
}
build_target();
$result=import_json($secret);
$new=get_page_by_path('contact-form',OBJECT,'original_mail_forms');
check($result['created']===1&&$result['updated']===0&&$new&&$new->ID===900,'同じスラッグが無ければ新規作成する');
check($GLOBALS['meta'][900]['cf_omf_condition_id']==='501,502','表示条件を取り込み先のページIDへ戻す');
check($GLOBALS['meta'][501]['cf_omf_select']==='contact-form'&&$GLOBALS['meta'][502]['cf_omf_select']==='contact-form','連携ページを取り込み先のページへ設定する');
check(!isset($GLOBALS['meta'][900]['cf_omf_mail_id'])&&$GLOBALS['meta'][900]['cf_omf_slack_webhook_url']==='https://hooks.example.test/secret'&&$GLOBALS['meta'][900]['cf_omf_form_mode']==='builder','新規作成ではメールIDを持たず、ファイルのメタを設定する');
check($GLOBALS['options']['omf_recaptcha_secret_key']==='SECRET'&&$GLOBALS['options']['omf_retention_days']==='30','設定を取り込む');

// 同じスラッグは上書き。IDとメールIDを維持し、ファイルに無い秘密情報は残す
build_target();
add_test_post(700,'original_mail_forms','contact-form','古い名前','draft');
$GLOBALS['meta'][700]=['cf_omf_mail_id'=>'5','cf_omf_slack_webhook_url'=>'https://keep.example.test','cf_omf_admin_title'=>'古い件名','cf_omf_old_only'=>'消える','cf_omf_condition_id'=>'777','unrelated_meta'=>'残る'];
$removal_plan=OMF_Port_Plan::build(OMF_Port_Reader::read(json_encode($plain)));
check($removal_plan['forms'][0]['removals']===['cf_omf_old_only'],'上書きで削除されるメタは、ファイルに無い cf_omf_ だけ（メールID・秘密情報・表示条件ID・他プラグインのメタは対象外）');
$view=render_template('omf_port_confirm.php',['omf_port_result'=>null,'omf_port_pending'=>['token'=>'tok','payload'=>OMF_Port_Reader::read(json_encode($plain))]]);
check(str_contains($view,'削除されるメタ：cf_omf_old_only'),'確認画面に削除されるメタのキー名を出す');
$GLOBALS['options']['omf_recaptcha_site_key']='KEEP_SITE'; $GLOBALS['options']['omf_recaptcha_secret_key']='KEEP_SECRET';
$result=import_json($plain);
check($result['updated']===1&&$result['created']===0&&form_count()===1,'同じスラッグのフォームは上書きし、増やさない');
check($GLOBALS['wp_posts'][700]->post_title==='お問い合わせ'&&$GLOBALS['wp_posts'][700]->post_status==='publish'&&$GLOBALS['meta'][700]['cf_omf_admin_title']==='件名\\テスト','上書きでタイトル・ステータス・メタが更新される');
check($GLOBALS['meta'][700]['cf_omf_mail_id']==='5'&&$GLOBALS['wp_posts'][700]->ID===700,'上書きでも記事IDとメールIDは取り込み先のまま');
check(!isset($GLOBALS['meta'][700]['cf_omf_old_only'])&&$GLOBALS['meta'][700]['unrelated_meta']==='残る'&&$GLOBALS['meta'][700]['cf_omf_condition_id']==='501,502','上書きでファイルに無い cf_omf_ メタが消え、他のメタは残る');
check($GLOBALS['meta'][700]['cf_omf_slack_webhook_url']==='https://keep.example.test'&&$GLOBALS['options']['omf_recaptcha_site_key']==='KEEP_SITE'&&$GLOBALS['options']['omf_recaptcha_secret_key']==='KEEP_SECRET','ファイルに無い秘密情報は取り込み先の値を残す');

// 秘密情報を含むファイルにWebhookが無ければ削除する。condition_targetsが無ければ表示条件IDに触れない
build_target();
add_test_post(700,'original_mail_forms','contact-form','古い名前','draft');
$GLOBALS['meta'][700]=['cf_omf_mail_id'=>'5','cf_omf_slack_webhook_url'=>'https://old.example.test','cf_omf_condition_id'=>'777'];
$no_webhook=$secret; unset($no_webhook['forms'][0]['meta']['cf_omf_slack_webhook_url'],$no_webhook['forms'][0]['condition_targets']);
import_json($no_webhook);
check(!isset($GLOBALS['meta'][700]['cf_omf_slack_webhook_url'])&&$GLOBALS['meta'][700]['cf_omf_mail_id']==='5'&&$GLOBALS['meta'][700]['cf_omf_condition_id']==='777','秘密情報を含むファイルにWebhookが無ければ削除し、メールIDと（condition_targets無しの）表示条件IDは残す');

// ID→パス→IDの往復と、見つからないページの警告
build_target(); unset($GLOBALS['wp_posts'][502]);
$payload=OMF_Port_Reader::read(json_encode($plain));
$plan=OMF_Port_Plan::build($payload);
check($plan['forms'][0]['action']==='create'&&$plan['forms'][0]['conditions']['ids']===[501]&&count($plan['warnings'])===2,'見つからないページは確認画面の計画で警告し、見つかったものだけ解決する');
$result=OMF_Port_Apply::run($plan);
check($GLOBALS['meta'][900]['cf_omf_condition_id']==='501'&&count($result['warnings'])===2,'実行結果にも警告が残り、見つかったIDだけ設定する');
check($plan['settings']['changes']['omf_is_rest_api']['new']==='1'&&OMF_Port_Plan::build(OMF_Port_Reader::read(json_encode($secret)))['settings']['changes']['omf_recaptcha_secret_key']['new']==='','確認画面用に秘密情報の値は計画へ出さない');

// 許可外キーの無視と警告、ファイルの拒否
build_target();
$file=$plain; $file['settings']['siteurl']='https://evil.test'; $file['settings']['omf_google_redirect_uri']='https://evil.test/cb'; $file['forms'][0]['meta']['evil_key']='x'; $file['forms'][0]['meta']['cf_omf_mail_id']='99'; $file['forms'][0]['meta']['cf_omf_condition_id']='1,2';
$payload=OMF_Port_Reader::read(json_encode($file));
check(!isset($payload['settings']['siteurl'])&&!isset($payload['settings']['omf_google_redirect_uri'])&&!isset($payload['forms'][0]['meta']['evil_key'])&&!isset($payload['forms'][0]['meta']['cf_omf_mail_id'])&&count($payload['warnings'])===5,'許可外のオプション・メタは無視して警告する');
import_json($file);
check(!isset($GLOBALS['options']['siteurl'])&&!isset($GLOBALS['meta'][900]['cf_omf_mail_id'])&&$GLOBALS['meta'][900]['cf_omf_condition_id']==='501,502','許可外の値は書き込まれない');
$half=$plain; $half['settings']=['omf_recaptcha_site_key'=>'A'];
$payload=OMF_Port_Reader::read(json_encode($half));
check($payload['settings']===[]&&count($payload['warnings'])===1,'サイトキーだけでシークレットが無い組は取り込まない');
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
check(str_contains($view,'contact-form')&&str_contains($view,'新規作成')&&str_contains($view,'見つかりません')&&!str_contains($view,'SECRET')&&str_contains($view,'omf_port_run'),'確認画面に新規／上書き・警告・実行ボタンを出し、秘密情報の値は出さない');
$view=render_template('omf_port_confirm.php',['omf_port_result'=>['created'=>1,'updated'=>0,'settings'=>2,'blocked'=>[['slug'=>'x','errors'=>['理由']]],'warnings'=>['注意']],'omf_port_pending'=>null]);
check(str_contains($view,'取り込み結果')&&str_contains($view,'理由')&&str_contains($view,'注意'),'結果画面に件数・中止理由・警告を出す');
$_GET=[];
$view=render_template('omf_port.php');
check(str_contains($view,'omf_port_export')&&str_contains($view,'omf_port_upload')&&str_contains($view,'秘密情報も含める'),'入口画面に書き出し・取り込みの操作を出す');
echo "エクスポート・インポートのテスト完了\n";
