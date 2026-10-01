<?php
require __DIR__.'/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Delivery_Cron as Cron;
function rest_url($path=''){return ($GLOBALS['cron_url'] ?? 'https://example.test/wp-json/').$path;}
function wp_get_environment_type(){return $GLOBALS['environment'] ?? 'production';}
function add_option($key,$value,...$args){if(isset($GLOBALS['options'][$key]))return false;return update_option($key,$value);}
function check_ajax_referer(...$args){if(!($GLOBALS['ajax_nonce']??false))throw new RuntimeException('nonce拒否');}
function wp_send_json_error($data,$status=200){throw new RuntimeException('JSON拒否:'.$status);}
function wp_send_json_success($data){$GLOBALS['ajax_result']=$data;throw new RuntimeException('JSON成功');}
function denied($callback){try{$callback();return false;}catch(RuntimeException $e){return true;}}
$cron=new Cron();
check(is_wp_error(Cron::command('command')) && !get_option(Cron::KEY),'管理権限なしでは秘密もコマンドも生成しない');
$GLOBALS['can_edit']=true;
check(is_wp_error(Cron::command('invalid')) && !get_option(Cron::KEY),'不正操作ではキーを生成しない');
$command=Cron::command('command');$key=get_option(Cron::KEY);
check(is_string($command) && preg_match('/^[0-9a-f]{64}$/',$key) && str_contains($command,'--request POST') && str_contains($command,'--header') && !str_contains($command,ABSPATH) && !str_contains($command,'WP-CLI'),'生成コマンドは設置パス不要でPOSTの認証ヘッダーを使う');
check(Cron::command('crontab')==='* * * * * '.$command && get_option(Cron::KEY)===$key,'コピー形式を切り替えてもキーは共通で定期実行の時刻を付ける');
check(str_contains(Cron::command('probe_command'),"--data 'probe=1'"),'接続確認にはメールを送らない操作を指定');
$GLOBALS['cron_url']='http://example.test/wp-json/';
check(is_wp_error(Cron::command('command')),'公開HTTPの登録用コマンドを生成しない');
$GLOBALS['cron_url']='https://example.test/%E6%97%A5/';
check(str_contains(Cron::command('command'),'\\%E6') && !str_contains(Cron::command('probe_command'),'\\%'),'cronのパーセント解釈を避け、手動接続確認のURLは変更しない');
$_POST=['kind'=>'renew'];
check(denied(fn()=>$cron->setup()) && get_option(Cron::KEY)===$key,'nonceなしの再発行を拒否しキーを保つ');
$GLOBALS['ajax_nonce']=true;$GLOBALS['can_edit']=false;
check(denied(fn()=>$cron->setup()) && get_option(Cron::KEY)===$key,'権限なしの再発行を拒否しキーを保つ');
$GLOBALS['can_edit']=true;update_option('omf_delivery_cron_probe_at',123);
denied(fn()=>$cron->setup());
check(get_option(Cron::KEY)!==$key && !get_option('omf_delivery_cron_probe_at') && !isset($GLOBALS['ajax_result']['command']),'権限とnonceのある再発行で旧キーと接続確認履歴を無効にする');
echo "cron設定回帰テスト完了\n";
