<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 非同期の起動と、署名つきループバックによる最大2通の並列処理。 */
class OMF_Delivery_Runner
{
  public function __construct()
  {
    add_action('init',static function(){ OMF_Delivery_Store::install(); if (OMF_Delivery_Store::rows('wp_async',1)) { self::schedule(false); } });
    add_action('omf_delivery_tick',static function(){ OMF_Delivery::run('wp_async'); self::schedule(false); });
    add_action('delete_omf_old_temp_files',[OMF_Delivery_Store::class,'cleanup']);
    add_action('rest_api_init',[$this,'routes']);
    if (defined('WP_CLI') && WP_CLI) {
      \WP_CLI::add_command('omf delivery run',static function(){ OMF_Delivery::run('server_cron'); \WP_CLI::success('送信待ちを処理しました。'); });
    }
  }
  public static function schedule(bool $immediate=true): void
  {
    if (!OMF_Delivery_Store::rows('wp_async',1)) { return; }
    if (!wp_next_scheduled('omf_delivery_tick')) { wp_schedule_single_event(time()+($immediate ? 0 : 60),'omf_delivery_tick'); }
    if ($immediate) { add_action('shutdown',static function(){ spawn_cron(); }); }
  }
  public function routes(): void
  {
    register_rest_route('omf/v1','/delivery-worker',['methods'=>'POST','callback'=>[$this,'endpoint'],'permission_callback'=>[$this,'authorize']]);
  }
  public function authorize(\WP_REST_Request $request): bool
  {
    $id=(int)$request['id']; $kind=(string)$request['kind']; $expires=(int)$request['expires'];
    if (!in_array($kind,['reply','admin','probe'],true) || $expires<time() || $expires>time()+120) { return false; }
    return hash_equals(self::signature($id,$kind,$expires),(string)$request->get_header('X-OMF-Signature'));
  }
  private static function signature(int $id,string $kind,int $expires): string
  { return hash_hmac('sha256',$id.'|'.$kind.'|'.$expires,wp_salt('auth')); }
  public function endpoint(\WP_REST_Request $request): array
  {
    if ($request['kind']==='probe') { usleep(300000); return ['ok'=>true]; }
    $id=(int)$request['id']; $row=OMF_Delivery_Store::get($id);
    if (!$row || $row['mode']!=='parallel') { return ['ok'=>false]; }
    OMF_Delivery::work($id,(string)$request['kind']);
    return ['ok'=>true];
  }
  private static function requests(int $id,array $kinds): array
  {
    $multi=curl_multi_init(); $handles=[];
    foreach ($kinds as $kind) {
      $expires=time()+90;
      $h=curl_init(rest_url('omf/v1/delivery-worker'));
      curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(compact('id','kind','expires')),
        CURLOPT_HTTPHEADER=>['X-OMF-Signature: '.self::signature($id,$kind,$expires)],CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_FOLLOWLOCATION=>false]);
      curl_multi_add_handle($multi,$h); $handles[]=$h;
    }
    do { $code=curl_multi_exec($multi,$running); if ($running) { curl_multi_select($multi,0.1); } } while ($running && $code===CURLM_OK);
    $results=[];
    foreach ($handles as $h) { $body=json_decode(curl_multi_getcontent($h),true); $results[]=curl_getinfo($h,CURLINFO_HTTP_CODE)===200 && ($body['ok'] ?? false)===true; curl_multi_remove_handle($multi,$h); curl_close($h); }
    curl_multi_close($multi); return $results;
  }
  public static function diagnose(): bool
  {
    if (!function_exists('curl_multi_init')) { return false; }
    $start=microtime(true); $results=self::requests(0,['probe','probe']);
    $ok=$results===[true,true] && microtime(true)-$start<0.58;
    update_option('omf_parallel_diagnostic',['ok'=>$ok,'at'=>time(),'url'=>rest_url('omf/v1/delivery-worker')],false); return $ok;
  }
  public static function ready(): bool
  { $d=get_option('omf_parallel_diagnostic',[]); return function_exists('curl_multi_init') && !empty($d['ok']) && ($d['url'] ?? '')===rest_url('omf/v1/delivery-worker'); }
  public static function parallel(int $id): void
  {
    if (!self::ready()) { return; }
    // 別ワーカーへCookieを渡さず、現在のセッションは親だけが保持する。
    self::requests($id,['reply','admin']); OMF_Delivery::finish($id);
  }
}
