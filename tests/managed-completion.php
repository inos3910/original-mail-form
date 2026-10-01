<?php
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Managed_Form as Managed;
use Sharesl\Original\MailForm\OMF_Field_Schema as Schema;
use Sharesl\Original\MailForm\OMF_Field_Renderer as Field;
function esc_attr($value){return htmlspecialchars($value, ENT_QUOTES);}
function get_permalink($id){return 'https://example.test/contact/';}
$schema=Schema::normalize(['version'=>1,'fields'=>[['key'=>'email','label'=>'メール','type'=>'email','required'=>true]]]);
$GLOBALS['meta'][10][Schema::META_KEY]=$schema;
$GLOBALS['meta'][10][Schema::MODE_KEY]='builder';
$GLOBALS['meta'][10]['cf_omf_render_enabled']='1';
$GLOBALS['global_omf']=new class {
  public function get_instance($name){return new class {
    public function get_form($id=null){return new WP_Post();}
    public function get_form_page_paths($id){return ['entry'=>'entry','confirm'=>'confirm','complete'=>'complete'];}
    public function get_active_form_pages($id){$out=[];foreach(['entry'=>20,'confirm'=>21,'complete'=>22] as $step=>$pid){$p=new WP_Post();$p->ID=$pid;$p->post_status='publish';$out[$step]=$p;}return $out;}
    public function get_post_values(){return [];}
    public function get_errors(){return [];}
    public function nonce_field(){echo '<input name="omf_token" value="test">';}
  };}
};
$prefix=Sharesl\Original\MailForm\OMF_Embed_Context::prefix('test');
Managed::complete_for_current_request(new WP_Post(), ['email'=>'complete@example.test']);
ob_start(); Managed::render(['slug'=>'test']); $html=ob_get_clean();
check(str_contains($html,'お問い合わせありがとうございます。'), '未設定の完了メッセージは既定文面');
check(!str_contains($html, '<a ') && !str_contains($html, '<h2') && !str_contains($html, '<section') && !str_contains($html, 'omf_token'), '完了の自動描画はメッセージだけを出力しレイアウト・リンク・トークンを生成しない');
ob_start(); $context=Managed::context(['slug'=>'test']); $output=ob_get_clean();
check($output === '' && $context['values']['email'] === 'complete@example.test' && $context['step'] === 'complete' && $context['complete_message'] === 'お問い合わせありがとうございます。', 'テンプレート用APIはHTMLを出力せず完了の退避値とメッセージを渡す');
$GLOBALS['meta'][10]['cf_omf_complete_message']="受付しました。\n<script>alert(1)</script>";
Managed::complete_for_current_request(new WP_Post(), ['email'=>'complete@example.test']);
ob_start(); Managed::render(['slug'=>'test']); $html=ob_get_clean();
check(str_contains($html,'受付しました。<br') && str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>'), '完了メッセージは改行して安全に表示');
$attrs=Field::attributes($schema['fields'][0], '', ['エラー'], 10);
check(str_contains($attrs,'name="omf_fields[email]"') && str_contains($attrs,'data-omf-validation=') && str_contains($attrs,' required') && str_contains($attrs,'aria-invalid="true"'), '独自HTML用属性も標準の検証とエラーを保持');
$radio=Schema::normalize(['version'=>1,'fields'=>[['key'=>'method','label'=>'連絡方法','type'=>'radio','description'=>'説明','choices'=>[['value'=>'mail','label'=>'メール']]]]])['fields'][0];
$attrs=Field::attributes($radio,'mail',['エラー'],10,'omf-10-method-0');
check(str_contains($attrs,'id="omf-10-method-0"') && str_contains($attrs,'aria-describedby="omf-10-method-help omf-10-method-error"'), '選択肢ごとのIDでも説明とエラーはグループを参照');
ob_start(); $complete_fields=Managed::fields(['slug'=>'test']); $output=ob_get_clean();
check($complete_fields === [] && $output === '', '完了の項目取得は空配列でセッション・入力HTMLを生成しない');
$html=Field::html($radio,'mail',['選択エラー'],false,10);
check(str_contains($html,'data-omf-validation=') && str_contains($html,'id="omf-10-method-0"') && str_contains($html,' checked') && str_contains($html,'aria-invalid="true"'), '返却HTMLの選択項目も共通の値・検証・ID・エラー属性を保持');
$unsafe=Schema::normalize(['version'=>1,'fields'=>[['key'=>'unsafe','type'=>'text','label'=>'<script>ラベル</script>']]])['fields'][0];
ob_start(); $html=Field::html($unsafe,'"><script>値</script>',[],false,10); $output=ob_get_clean();
check($output === '' && !str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;') && str_contains($html,'omf-managed-field--text'), '項目HTML取得は直接出力せずラベルと値をエスケープする');
echo "完了画面・独自入力属性テスト完了\n";
