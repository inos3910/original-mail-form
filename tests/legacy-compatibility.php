<?php
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Field_Schema as Schema;
use Sharesl\Original\MailForm\OMF_Rest;
function size_format($bytes): string { return (string) $bytes . ' B'; }
class LegacyInput
{
  use Sharesl\Original\MailForm\OMF_Trait_Validation;
  public function get_form(int|string|null $id = null): WP_Post|array { return new WP_Post(); }
  public function input(array $data): array { return $this->restrict_array_values($data); }
  public function errors(): array { return $this->extra_errors; }
}
$GLOBALS['meta'][10]['cf_omf_validation'] = [];
$probe = new LegacyInput();
$data = $probe->input(['message'=>'設定不要','hidden'=>'独自値','zero'=>'0','options'=>['a'=>'A','b'=>'B'],'omf_nonce'=>'valid','send'=>'send']);
check($data === ['message'=>'設定不要','hidden'=>'独自値','zero'=>'0','options'=>['a'=>'A','b'=>'B']], '検証0件でも旧通常入力・hidden・連想添字の選択を維持し制御値を除く');
$GLOBALS['meta'][10]['cf_omf_validation'] = [['target'=>'email','email'=>'1'],['target'=>'choices','required'=>'1','matching_char'=>'A,B']];
$probe = new LegacyInput();
$data = $probe->input(['email'=>'x@example.test','choices'=>['a'=>'A','b'=>'B'],'custom'=>'保持']);
check($probe->validate_mail_form_data($data) === [], '旧方式の連想添字でも全選択値を検証する');
$bad = new LegacyInput(); $bad->input(['email'=>['x@example.test']]);
check(isset($bad->errors()['email']), '旧方式でも単一宛先の配列注入を拒否');
$bad = new LegacyInput(); $bad->input(['custom'=>['nested'=>['x']]]);
check(isset($bad->errors()['custom']), '未登録の旧項目でもネストした入力を拒否');
$bad = new LegacyInput(); $bad->input(['file'=>['name'=>'x.txt','attachment_id'=>'999','size'=>'1']]);
check(isset($bad->errors()['file']), '旧項目へ既存メディアIDを送り込んでも添付として信用しない');
$bad = new LegacyInput(); $bad->input(['file'=>['upload_id'=>str_repeat('a',40),'name'=>'x.txt']]);
check(isset($bad->errors()['file']), '他の非公開ファイルのIDをPOSTしても信用しない');
$data['choices']=['a'=>'C'];
check(isset($probe->validate_mail_form_data($data)['choices']), '旧方式の連想添字でも選択肢改ざんを拒否');
$GLOBALS['meta'][10]['cf_omf_validation'] = [['target'=>'file','required'=>'1','extension'=>['txt'],'file_size'=>'100']];
check((new LegacyInput())->validate_mail_form_data(['file'=>['pending'=>true]]) === [], '旧必須添付の保存前の存在確認を文字列配列として再帰検証しない');
check((new LegacyInput())->validate_mail_form_data(['file'=>['upload_id'=>str_repeat('a',40),'name'=>'x.txt','size'=>101]]) !== [], '旧添付でも実体情報のサイズ上限を検証する');
$GLOBALS['meta'][10][Schema::MODE_KEY] = 'builder';
$GLOBALS['meta'][10][Schema::META_KEY] = ['version'=>1,'fields'=>[['key'=>'message','type'=>'text','label'=>'内容','required'=>true]]];
$data = (new LegacyInput())->input(['message'=>'新方式','unknown'=>'取り込まない']);
check($data === ['message'=>'新方式'], '明示切替後の新方式は定義済み項目だけ受け付ける');
unset($GLOBALS['meta'][10][Schema::MODE_KEY]);
$rest = new OMF_Rest();
$_SERVER['HTTP_X_WP_NONCE'] = 'invalid';
$before = $_SESSION;
$reply = $rest->rest_api_validate(new WP_REST_Request(['message'=>'認証なし']));
check($reply->status === 200 && $reply->data instanceof WP_Error && $_SESSION === $before, '旧RESTの認証NG応答を保ち、認証なしでは検証処理へ進まない');
$reply = $rest->rest_api_send(new WP_REST_Request(['email'=>'x@example.test']));
check($reply->status === 200 && $reply->data instanceof WP_Error && empty($GLOBALS['mail_calls']), '旧RESTの認証NG応答を保ち、認証なしでは実送信しない');
echo "旧方式互換性回帰テスト完了\n";
