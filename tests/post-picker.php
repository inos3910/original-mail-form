<?php
// AJAXの認証・検索範囲を独立したスタブで検証する。
define('ABSPATH', __DIR__);
class Result extends Exception { public function __construct(public $data,public $status=200){parent::__construct();} }
function check_ajax_referer($action,$key){if(empty($GLOBALS['nonce']))throw new Result([],403);}
function current_user_can($cap,$id=null){return $id===null?$GLOBALS['cap']:in_array($id,$GLOBALS['editable'],true);}
function wp_send_json_error($data,$status){throw new Result($data,$status);}
function wp_send_json_success($data){throw new Result($data);}
function sanitize_text_field($v){return strip_tags($v);}
function wp_unslash($v){return $v;}
function get_posts($args){$GLOBALS['args']=$args;return [(object)['ID'=>1,'post_type'=>'page'],(object)['ID'=>2,'post_type'=>'post']];}
function get_the_title($p){return 'テスト'.$p->ID;}
require __DIR__ . '/../classes/class-post-picker.php';
function run($q,$nonce=true,$cap=true){$_GET=['q'=>$q];$GLOBALS['nonce']=$nonce;$GLOBALS['cap']=$cap;$GLOBALS['editable']=[1];try{Sharesl\Original\MailForm\OMF_Post_Picker::search();}catch(Result $r){return $r;}}
function check($ok,$label){if(!$ok)throw new Exception($label);echo "PASS: $label\n";}
check(run('test',false)->status===403,'nonceなしを拒否');
check(run('test',true,false)->status===403,'編集権限なしを拒否');
check(run(['test'])->status===400,'配列入力を拒否');
check(run('')->data===[],'空検索を拒否');
$r=run('テスト');check(count($r->data)===1&&$r->data[0]['id']===1,'編集できない投稿を候補に含めない');
check($GLOBALS['args']['post_type']===['post','page']&&$GLOBALS['args']['search_columns']===['post_title'],'検索対象を投稿・固定ページのタイトルに限定');
run('42');check($GLOBALS['args']['p']===42&&!isset($GLOBALS['args']['s']),'数字はIDで検索');
