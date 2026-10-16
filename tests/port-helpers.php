<?php
require __DIR__ . '/bootstrap.php';
use Sharesl\Original\MailForm\OMF_Port_Apply;
use Sharesl\Original\MailForm\OMF_Port_Export;
use Sharesl\Original\MailForm\OMF_Port_Plan;
use Sharesl\Original\MailForm\OMF_Port_Reader;

// エクスポート・インポート用の最小スタブ。投稿はメモリ上の $GLOBALS['wp_posts'] に持つ。
class TestPost extends WP_Post { public $post_title = ''; public $uri = ''; }
function wp_slash($v){return $v;}
function absint($v){return abs((int)$v);}
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
  $GLOBALS['meta'][10]=['cf_omf_mail_id'=>'7','cf_omf_slack_webhook_url'=>'https://hooks.example.test/secret','cf_omf_condition_id'=>'101,102,999','cf_omf_condition_post'=>['page'],'cf_omf_admin_title'=>'件名\\テスト','cf_omf_field_schema'=>valid_schema(),'cf_omf_form_mode'=>'builder','other_meta'=>'対象外','cf_omf_admin_to'=>'admin@example.test','cf_omf_screen_complete'=>'55','cf_omf_validation'=>[['target'=>'name']],'cf_omf_front_validation'=>'1','cf_omf_save_db'=>'1','cf_omf_custom_extra'=>'x','cf_omf_data_memo'=>'メモ'];
  $GLOBALS['meta'][101]['cf_omf_select']='contact-form';
  $GLOBALS['meta'][102]['cf_omf_select']='contact-form';
}

// 書き出し先とは記事IDが違う取り込み先
function build_target(): void {
  reset_env();
  add_test_post(501,'page','contact','お問い合わせ','publish');
  add_test_post(502,'page','team','チーム','publish','about/team');
}
