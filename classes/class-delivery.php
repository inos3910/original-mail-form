<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 永続受付とワーカーの共通経路。通常の同期直列は旧経路を維持する。 */
class OMF_Delivery
{
  const MODES=['serial'=>'同期・直列（標準）','parallel'=>'同期・並列','wp_async'=>'WordPressで非同期','server_cron'=>'サーバーcronで非同期'];
  public static function mode(int $id): string
  { $mode=get_post_meta($id,'cf_omf_delivery_mode',true); return isset(self::MODES[$mode]) ? $mode : 'serial'; }
  public static function accept(array $data,int $form_id,int $post_id,array $converted): array
  {
    $mode=self::mode($form_id);
    if ($mode === 'parallel' && is_wp_error(OMF_Delivery_Admin::validate($form_id, ['omf_delivery_mode'=>'parallel']))) { return self::response(null, false); }
    $payload=(new OMF_Delivery_Mailer)->prepare($converted['tags'],$form_id,$converted['attachment_paths'],$converted['attachment_ids']);
    $payload['post_id']=$post_id;
    $rules=OMF_Field_Schema::rules($form_id);
    $payload['email_keys']=is_wp_error($rules) ? [] : array_values(array_map(static fn($rule)=>$rule['target'],array_filter($rules,static fn($rule)=>is_array($rule) && !empty($rule['email']) && isset($rule['target']))));
    $token=(string)($_SESSION[OMF_Embed_Context::token_key()] ?? '');
    if ($token==='') { return self::response(null,false); }
    $key=hash_hmac('sha256',$form_id.'|'.$token.'|'.serialize($data),wp_salt('auth'));
    try { $id=OMF_Delivery_Store::create($key,$form_id,$mode,$payload); } catch (\Throwable $e) { return self::response(null,false); }
    if (!$id) { return self::response(null,false); }
    $session_key='omf_delivery_receipt_'.$form_id;
    if (($_SESSION[$session_key] ?? 0)!==$id) {
      $_SESSION[$session_key]=$id;
      try { do_action('omf_submission_accepted',$id,$form_id,$mode); } catch (\Throwable $e) { do_action('omf_extension_error', 'omf_submission_accepted', $id); }
    }
    if ($mode==='wp_async') { OMF_Delivery_Runner::schedule(); }
    if ($mode==='parallel') { OMF_Delivery_Runner::parallel($id); }
    $row=OMF_Delivery_Store::get($id);
    $ok=$mode!=='parallel' || ($row && in_array($row['reply'],['sent','skipped'],true) && $row['admin']==='sent');
    return self::response($row,$ok);
  }
  private static function response(?array $row,bool $ok): array
  { return ['is_sended'=>$ok,'is_sended_reply'=>$row && in_array($row['reply'],['sent','skipped'],true),'is_sended_admin'=>$row && $row['admin']==='sent','accepted'=>$ok,'receipt_id'=>(int)($row['id'] ?? 0)]; }
  public static function work(int $id,string $kind): void
  {
    global $wpdb;
    for ($slot=0; $slot<2; $slot++) {
      $lock='omf_' . substr(hash('sha256', $wpdb->prefix . ABSPATH), 0, 20) . '_' . $slot;
      if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) { continue; }
      try { self::work_locked($id, $kind); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
      return;
    }
  }
  private static function work_locked(int $id,string $kind): void
  {
    if (!in_array($kind,['reply','admin'],true)) { return; }
    $row=OMF_Delivery_Store::get($id); if (!$row) { return; }
    if ($kind==='admin' && $row['mode']!=='parallel' && in_array($row['reply'],['pending','sending','unknown'],true)) { return; }
    if (!OMF_Delivery_Store::claim($id,$kind)) { self::finish($id); return; }
    $payload=OMF_Delivery_Store::unpack($row);
    if (!$payload) { OMF_Delivery_Store::result($id,$kind,'failed','保存内容を復元できません'); return; }
    $mailer=new OMF_Delivery_Mailer;
    try {
      $ok=$mailer->send($payload,$row,$kind);
      if (!OMF_Delivery_Store::result($id,$kind,$ok ? 'sent' : 'failed',$ok ? '' : 'メール送信処理が失敗しました')) { return; }
      if ($ok) { $mailer->sent_hook($payload,OMF_Delivery_Store::get($id),$kind); }
    } catch (\Throwable $e) {
      // SMTP受理済みの可能性がある例外は自動再送しない。秘密を含み得る例外文は保存しない。
      OMF_Delivery_Store::result($id,$kind,'unknown','送信処理が中断されました');
    }
    self::finish($id);
  }
  public static function finish(int $id): void
  {
    global $wpdb; $lock=OMF_Delivery_Store::effect_lock($id);
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock))!==1) { return; }
    try { self::finish_locked($id); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
  }
  private static function finish_locked(int $id): void
  {
    global $wpdb; $row=OMF_Delivery_Store::get($id);
    if (!$row || !in_array($row['reply'],['sent','skipped'],true) || $row['admin']!=='sent') { return; }
    $table=OMF_Delivery_Store::table();
    if ($wpdb->query($wpdb->prepare("UPDATE $table SET effects='running' WHERE id=%d AND effects='pending'",$id))!==1) { return; }
    $payload=OMF_Delivery_Store::unpack($row);
    try {
      if (!$payload) { throw new \RuntimeException(); }
      (new OMF_Delivery_Mailer)->finish($payload,$row);
      foreach ($payload['attachment_ids'] as $upload) { OMF_Uploads::remove($upload); }
      $wpdb->update($table,['effects'=>'done','payload'=>''],['id'=>$id]);
    } catch (\Throwable $e) { $wpdb->update($table,['effects'=>'unknown'],['id'=>$id]); }
  }
  public static function run(string $mode): void
  {
    if (!in_array($mode,['wp_async','server_cron'],true)) { return; }
    OMF_Delivery_Store::install(); OMF_Delivery_Store::cleanup();
    update_option('omf_delivery_last_'.$mode,time(),false);
    $start=microtime(true);
    foreach (OMF_Delivery_Store::rows($mode,10) as $row) {
      self::work((int)$row['id'],'reply'); self::work((int)$row['id'],'admin');
      if (microtime(true)-$start>20) { break; }
    }
  }
}
