<?php
// WordPress・DB・メール配送を起動しない回帰テスト用の境界スタブ。
define('OBJECT', 'OBJECT');
define('ABSPATH', sys_get_temp_dir() . '/omf-test-public/');
define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400); define('MB_IN_BYTES', 1048576);
$GLOBALS['options'] = []; $GLOBALS['meta'] = []; $GLOBALS['hooks'] = []; $_SESSION = []; $_POST = []; $_FILES = [];
class WP_Post { public $ID = 10; public $post_name = 'test'; public $post_type = 'original_mail_forms'; public $post_status = 'publish'; }
class WP_Error { public function __construct(public $code='',public $message='',public $data=[]) {} public function get_error_code(){return $this->code;} }
class WP_REST_Request { public function __construct(private array $params=[]) {} public function get_params(){return $this->params;} }
class WP_REST_Response { public function __construct(public $data, public $status=200){} }
function add_action($hook,$fn,...$args){$GLOBALS['hooks'][$hook][]=$fn;}
function add_filter($hook,$fn,...$args){add_action($hook,$fn,...$args);}
function apply_filters($hook,$value,...$args){return $value;}
function do_action($hook,...$args){}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;return true;}
function delete_option($key){unset($GLOBALS['options'][$key]);return true;}
function get_transient($key){return get_option('transient_'.$key,false);}
function set_transient($key,$value,$ttl){return update_option('transient_'.$key,$value);}
function delete_transient($key){delete_option('transient_'.$key);}
function get_post_meta($id,$key='',$single=false){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['meta'][$id][$key]=$value;}
function get_the_ID(){return $GLOBALS['page_id']??20;}
function get_page_by_path($path,...$args){$p=new WP_Post();if(isset($GLOBALS['pages'][$path])){$p->ID=$GLOBALS['pages'][$path];$p->post_name=$path;}return $p;}
function get_post_type($id){return $GLOBALS['post_type']??'original_mail_forms';}
function get_post($id){return null;}
function is_admin(){return false;}
function is_favicon(){return false;}
function is_robots(){return false;}
function is_feed(){return false;}
function get_queried_object_id(){return get_the_ID();}
function get_current_user_id(){return 1;}
function wp_salt($scheme){return 'テスト専用のダミー鍵';}
function is_email($value){return is_string($value)?filter_var($value,FILTER_VALIDATE_EMAIL):false;}
function sanitize_text_field($value){return trim(strip_tags($value));}
function sanitize_textarea_field($value){return trim(strip_tags($value));}
function sanitize_file_name($value){return basename($value);}
function wp_unslash($value){return is_array($value)?array_map('wp_unslash',$value):stripslashes($value);}
function home_url($path=''){return 'https://example.test'.$path;}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function wp_remote_post($url,$args){$GLOBALS['captcha_calls']=($GLOBALS['captcha_calls']??0)+1;return $GLOBALS['captcha_response']??new WP_Error();}
function wp_remote_retrieve_response_code($r){return $r['code']??0;}
function wp_remote_retrieve_body($r){return $r['body']??'';}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_verify_nonce($value,$action){return $value==='valid';}
function current_user_can(...$args){return $GLOBALS['can_edit']??false;}
function wp_is_post_revision($id){return false;}
function plugin_dir_path($path){return dirname($path).'/';}
function register_activation_hook(...$args){}
function wp_json_encode($v,...$args){return json_encode($v,...$args);}
function is_ssl(){return true;}
function __($s){return $s;}
function check_admin_referer(...$args){if(!($GLOBALS['admin_nonce']??false)){throw new RuntimeException('nonce拒否');}}
function wp_die($s,...$args){throw new RuntimeException($s);}
function is_singular(){return $GLOBALS['singular']??false;}
function esc_html($s){return htmlspecialchars($s);}
function esc_url($s){return $s;}
function get_bloginfo($s){return 'テスト';}
function gethostbyaddr_stub($s){throw new RuntimeException('DNSを呼ばない');}
function wp_mail(...$args){$GLOBALS['mail_calls'][]=$args;return true;}
function get_temp_dir(){return sys_get_temp_dir().'/';}
function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0700,true);}
function check($ok,$name){if(!$ok){throw new RuntimeException('FAIL: '.$name);}echo 'PASS: '.$name.PHP_EOL;}
function call_private($object,$name,...$args){$method=new ReflectionMethod($object,$name);$method->setAccessible(true);return $method->invoke($object,...$args);}
spl_autoload_register(function($class){$prefix='Sharesl\\Original\\MailForm\\OMF_';if(str_starts_with($class,$prefix)){require dirname(__DIR__).'/classes/class-'.str_replace('_','-',strtolower(substr($class,strlen($prefix)))).'.php';}});
set_error_handler(function($severity,$message,$file,$line){if(error_reporting()&$severity){throw new ErrorException($message,0,$severity,$file,$line);}return false;});
