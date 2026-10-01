<?php
require __DIR__.'/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Utils;
use Sharesl\Original\MailForm\OMF_Page;
use Sharesl\Original\MailForm\OMF_Rest;
use Sharesl\Original\MailForm\OMF_Admin;
class Probe {
  use Sharesl\Original\MailForm\OMF_Trait_Validation, Sharesl\Original\MailForm\OMF_Trait_Send;
  public function get_form(int|string|null $id=null): WP_Post|array {return new WP_Post();}
  public function input($data){return $this->restrict_array_values($data);}
  public function errors(){return $this->extra_errors;}
  public function validate_input(&$data){return $this->validate_submission($data);}
  private function is_valid_nonce(){return true;}
}
$GLOBALS['meta'][10]['cf_omf_validation']=[['target'=>'choices','required'=>'1','matching_char'=>'A,B'],['target'=>'email','email'=>'1'],['target'=>'zero','required'=>'1']];
$p=new Probe(); $data=$p->input(['choices'=>['A','B'],'email'=>'test@example.test','zero'=>'0','injected'=>'drop']);
check($data['choices']===['A','B'] && !isset($data['injected']),'正当な複数選択と未定義項目の除外');
check($p->validate_input($data)===[],'複数選択と文字列0の検証');
$bad=new Probe();$bad->input(['email'=>['test@example.test','other@example.test']]);check(isset($bad->errors()['email']),'メール配列を拒否');
$bad=new Probe();$bad->input(['choices'=>['nested'=>['A']]]);check(isset($bad->errors()['choices']),'ネストした入力を拒否');
$bad=new Probe();$bad->input(['email'=>null]);check(isset($bad->errors()['email']),'RESTのnullを拒否');
check(call_private($p,'validate_matching_char','C','A,B')!=='','選択肢の改ざんを拒否');
check(OMF_Utils::custom_escape('0')==='0' && OMF_Utils::custom_escape(null)==='','0とnullの取り扱い');
check(call_private($p,'replace_form_mail_tags','{a}/{b}',['a'=>'{b}','b'=>'値'])==='{b}/値','タグの再帰的な置換を防ぐ');
check(call_private($p,'replace_form_mail_tags','{choices}',$data)==='A、B','複数選択の本文');
check(call_private($p,'resolve_mail_to_address','staff@example.test,{email}',$data)==='staff@example.test,test@example.test','固定宛先とタグの併用');
check(call_private($p,'resolve_mail_to_address','{email}',['email'=>'a@example.test,b@example.test'])==='','タグから複数宛先の注入を拒否');
$info=['tag_to_text'=>$data,'mail_to'=>'{email}','form_title'=>'件名','mail_template'=>'本文','from_name'=>'','mail_from'=>'','mail_reply_to'=>''];
check(call_private($p,'create_reply_mail',$info,['/dummy'])['attachments']===[],'自動返信への添付を禁止');
$GLOBALS['meta'][10]['cf_omf_turnstile']='1'; $_SESSION['omf_token']='token';
check($p->validate_mail_form_data($data)!==[],'CAPTCHA未通過を拒否');
$GLOBALS['options']['omf_turnstile_secret_key']='dummy'; $_POST['cf-turnstile-response']='dummy';
$GLOBALS['captcha_response']=['code'=>200,'body'=>json_encode(['success'=>true,'hostname'=>'example.test'])];
check($p->validate_mail_form_data($data)===[],'CAPTCHA通過記録');
unset($_POST['cf-turnstile-response']);$calls=$GLOBALS['captcha_calls'];
check($p->validate_mail_form_data($data)===[] && $GLOBALS['captcha_calls']===$calls,'確認画面で同一データの通過記録を使用');
$changed=$data;$changed['email']='changed@example.test';check($p->validate_mail_form_data($changed)!==[],'CAPTCHA通過後のデータ改ざんを拒否');
$GLOBALS['meta'][10]['cf_omf_validation']=[];check($p->validate_mail_form_data([])!==[],'ルール0件でもCAPTCHA必須');
$GLOBALS['meta'][10]['cf_omf_turnstile']='';
class Crypt {use Sharesl\Original\MailForm\OMF_Trait_Cryptor; public function encrypt($s){return $this->encrypt_secret($s,'access_token');} public function decrypt($s){return $this->decrypt_secret($s,'access_token');}}
$c=new Crypt();$enc=$c->encrypt('dummy-token');check($c->decrypt($enc)==='dummy-token','初回の暗号化復号');check($enc!==$c->encrypt('dummy-token'),'暗号化ごとに乱数IV');
$raw=base64_decode(substr($enc,3));$raw[30]=chr(ord($raw[30])^1);check($c->decrypt('v2:'.base64_encode($raw))==='','暗号文改ざんを検出');
$page=new OMF_Page();check(call_private($page,'is_valid_token')===false,'トークンなしでも500にしない');
$rest=new OMF_Rest();$response=call_private($rest,'rest_response',fn()=>['valid'=>false],new WP_REST_Request());check($response->status===400,'REST入力エラー400');
$error=new WP_Error('forbidden','拒否',['status'=>403]);check(call_private($rest,'rest_response',fn()=>$error,new WP_REST_Request())===$error,'REST認証エラーを保持');
$admin=new OMF_Admin();$_POST=['cf_omf_admin_to'=>'changed@example.test'];$before=$GLOBALS['meta'];$admin->save_omf_custom_field(10);check($before===$GLOBALS['meta'],'権限なしのメタ保存を拒否');
$GLOBALS['can_edit']=true;$admin->save_omf_custom_field(10);check($before===$GLOBALS['meta'],'nonceなしのメタ保存を拒否');
$GLOBALS['can_edit']=false;$_POST=['omf_disconnect'=>'1'];$GLOBALS['options']['_omf_google_access_token']='dummy';call_private($admin,'disconnect_oauth_redirect');check(get_option('_omf_google_access_token')==='dummy','未認証のGoogle解除を拒否');
class Delivery {
 use Sharesl\Original\MailForm\OMF_Trait_Submission;
 public $reply_calls=0;public $admin_calls=0;public $admin_ok=false;
 public function run($d){return $this->deliver_submission($d,10,20);}
 public function get_form($id){return new WP_Post();}
 private function convert_attachments($d){return ['tags'=>$d,'attachment_paths'=>[],'attachment_ids'=>[]];}
 private function is_disable_reply_mail($id){return false;}
 private function send_reply_mail(...$args){$this->reply_calls++;return true;}
  private function send_admin_mail(...$args){$this->admin_calls++;return $this->admin_ok;}
  private function update_saved_reply_result(...$args): void {}
}
$d=new Delivery();check(!$d->run(['email'=>'x@example.test'])['is_sended'],'通知失敗を完了にしない');$d->admin_ok=true;check($d->run(['email'=>'x@example.test'])['is_sended'] && $d->reply_calls===1 && $d->admin_calls===2,'部分成功の再試行で返信を重複送信しない');
// 確認画面は入力項目をhiddenで再送しないテーマでもセッション値を保持する。
$GLOBALS['meta'][20]['cf_omf_select']='test';
$GLOBALS['meta'][10]['cf_omf_condition_post']=['page'];
$GLOBALS['meta'][10]['cf_omf_screen_entry']='entry';
$GLOBALS['meta'][10]['cf_omf_screen_confirm']='confirm';
$GLOBALS['meta'][10]['cf_omf_screen_complete']='complete';
$GLOBALS['pages']=['entry'=>20,'confirm'=>21,'complete'=>22];
$GLOBALS['meta'][21]['cf_omf_select']='test';
$GLOBALS['page_id']=21;
call_private($page,'update_session_names','test');
$_SESSION['omf_test_data']=['message'=>'本文を保持']; $_POST=['send'=>'send'];
check($page->get_post_values()['message']==='本文を保持','確認画面のセッション本文を保持');
$GLOBALS['meta'][10]['cf_omf_screen_confirm']='';
check(!$page->is_page('confirm'),'確認画面なしでも警告なし');
$before=session_status();$page->init_sessions();check(session_status()===$before,'一般ページでセッションを開始しない');
$headers=call_private($p,'create_admin_mail',array_merge($info,['mail_reply_to'=>'reply@example.test']),[]);
check(in_array('Reply-To: reply@example.test',$headers['headers'],true),'From空欄でもReply-Toを設定');
for ($attempt = 0; $attempt < 100; $attempt++) {
$legacy_key=random_bytes(32);$iv=random_bytes(16);
$GLOBALS['options']['_omf_encryption_key']=base64_encode($legacy_key);
$GLOBALS['options']['_omf_encryption_iv_access_token']=base64_encode($iv);
$legacy=base64_encode(openssl_encrypt('legacy-token','AES-256-CBC',base64_encode($legacy_key),OPENSSL_RAW_DATA,$iv));
// 旧版の初回キー形式を使った失敗試行のOpenSSL警告だけ抑制する。
if ($c->decrypt($legacy) !== 'legacy-token' || !str_starts_with(get_option('_omf_google_access_token'), 'v2:')) { throw new RuntimeException('旧トークン移行に失敗'); }
}
check(true, '旧版の初回トークン移行（100種類の鍵）');
echo "回帰テスト完了\n";
