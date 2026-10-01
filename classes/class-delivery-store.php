<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** CAS更新で別プロセス間の重複配送を防ぐ。本文は認証付き暗号で保管する。 */
class OMF_Delivery_Store
{
  use OMF_Trait_Cryptor;
  public static function table(): string { global $wpdb; return $wpdb->prefix . 'omf_deliveries'; }
  public static function install(): void
  {
    if (get_option('omf_delivery_schema') === '1') { return; }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = self::table(); $collate = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $table (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      request_key varchar(64) NOT NULL,
      form_id bigint unsigned NOT NULL,
      mode varchar(20) NOT NULL,
      payload longtext NOT NULL,
      reply varchar(20) NOT NULL DEFAULT 'pending',
      admin varchar(20) NOT NULL DEFAULT 'pending',
      reply_attempts int NOT NULL DEFAULT 0,
      admin_attempts int NOT NULL DEFAULT 0,
      reply_started bigint NOT NULL DEFAULT 0,
      admin_started bigint NOT NULL DEFAULT 0,
      reply_next bigint NOT NULL DEFAULT 0,
      admin_next bigint NOT NULL DEFAULT 0,
      reply_error varchar(100) NOT NULL DEFAULT '',
      admin_error varchar(100) NOT NULL DEFAULT '',
      effects varchar(20) NOT NULL DEFAULT 'pending',
      created bigint NOT NULL,
      expires bigint NOT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY request_key (request_key),
      KEY mode (mode)
    ) $collate;");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) { update_option('omf_delivery_schema', '1', false); }
  }
  public static function get(int $id): ?array
  { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id=%d', $id), ARRAY_A); }
  public static function unpack(array $row): array
  {
    $value = (new self)->decrypt_secret($row['payload'], 'delivery');
    return json_decode($value, true) ?: [];
  }
  public static function create(string $key, int $form_id, string $mode, array $payload): int
  {
    self::install(); global $wpdb; $table = self::table();
    $old = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE request_key=%s", $key));
    if ($old) { return $old; }
    if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE payload<>''") >= 1000) { return 0; }
    $secret = (new self)->encrypt_secret(wp_json_encode($payload), 'delivery');
    $ok = $wpdb->insert($table, ['request_key'=>$key, 'form_id'=>$form_id, 'mode'=>$mode, 'payload'=>$secret,
      'reply'=>empty($payload['disabled']) ? 'pending' : 'skipped', 'created'=>time(), 'expires'=>time()+7*DAY_IN_SECONDS]);
    if ($ok) { return (int) $wpdb->insert_id; }
    return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE request_key=%s", $key));
  }
  public static function claim(int $id, string $kind): bool
  {
    if (!in_array($kind, ['reply','admin'], true)) { return false; }
    global $wpdb; $table=self::table(); $now=time();
    return $wpdb->query($wpdb->prepare("UPDATE $table SET $kind='sending', {$kind}_started=%d, {$kind}_attempts={$kind}_attempts+1 WHERE id=%d AND $kind IN ('pending','failed') AND {$kind}_attempts<3 AND {$kind}_next<=%d AND expires>%d", $now,$id,$now,$now)) === 1;
  }
  public static function result(int $id, string $kind, string $state, string $error=''): bool
  {
    if (!in_array($kind,['reply','admin'],true) || !in_array($state,['sent','failed','unknown'],true)) { return false; }
    global $wpdb; $row=self::get($id); $wait=($row[$kind.'_attempts'] ?? 1)>1 ? 300 : 60;
    return $wpdb->query($wpdb->prepare('UPDATE '.self::table()." SET $kind=%s, {$kind}_error=%s, {$kind}_next=%d WHERE id=%d AND $kind='sending'",$state,$error,time()+$wait,$id)) === 1;
  }
  public static function effect_lock(int $id): string { return 'omf_effect_' . substr(hash('sha256', self::table().ABSPATH),0,16) . '_' . $id; }
  public static function recover(): void
  {
    global $wpdb; $table=self::table();
    foreach ($wpdb->get_col("SELECT id FROM $table WHERE effects='running'") as $id) {
      if ((int)$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',self::effect_lock((int)$id)))===1) { $wpdb->update($table,['effects'=>'unknown'],['id'=>$id,'effects'=>'running']); }
    }
    // 動作中のワーカーが存在する間は、経過時間だけで中断扱いにしない。
    for ($slot=0;$slot<2;$slot++) {
      $lock='omf_' . substr(hash('sha256', $wpdb->prefix . ABSPATH),0,20) . '_' . $slot;
      if ((int)$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$lock))!==1) { return; }
    }
    foreach (['reply','admin'] as $kind) {
      $wpdb->query($wpdb->prepare("UPDATE $table SET $kind='unknown', {$kind}_error='処理中断・結果未確認' WHERE $kind='sending' AND {$kind}_started<%d", time()-120));
    }
  }
  public static function retry(int $id, string $kind): bool
  {
    if (!in_array($kind,['reply','admin'],true)) { return false; }
    global $wpdb;
    return $wpdb->query($wpdb->prepare('UPDATE '.self::table()." SET $kind='pending', {$kind}_attempts=0, {$kind}_next=0, {$kind}_error='' WHERE id=%d AND $kind='failed' AND expires>%d",$id,time()))===1;
  }
  public static function rows(?string $mode=null, int $limit=50): array
  {
    global $wpdb; $table=self::table();
    $sql=$mode===null ? "SELECT * FROM $table ORDER BY id DESC LIMIT %d" : "SELECT * FROM $table WHERE mode=%s AND expires>".time()." AND ((reply IN ('pending','failed') AND reply_attempts<3) OR (admin IN ('pending','failed') AND admin_attempts<3 AND reply NOT IN ('pending','sending','unknown')) OR (reply IN ('sent','skipped') AND admin='sent' AND effects='pending')) ORDER BY id ASC LIMIT %d";
    return $wpdb->get_results($mode===null ? $wpdb->prepare($sql,$limit) : $wpdb->prepare($sql,$mode,$limit),ARRAY_A);
  }
  public static function protected_upload(string $id): bool
  {
    global $wpdb;
    $rows=$wpdb->get_results($wpdb->prepare('SELECT payload FROM '.self::table().' WHERE expires>%d AND payload<>%s',time(),''),ARRAY_A);
    foreach ($rows as $row) { if (in_array($id,self::unpack($row)['attachment_ids'] ?? [],true)) { return true; } }
    return false;
  }
  public static function purge(int $id): bool
  {
    global $wpdb; $table=self::table(); $row=self::get($id);
    if (!$row || $row['reply']==='sending' || $row['admin']==='sending' || $row['effects']==='running') { return false; }
    // 先に受付をロックし、消去とワーカーの競合を止める。
    $ok=$wpdb->query($wpdb->prepare("UPDATE $table SET reply='cancelled',admin='cancelled',effects='cancelled',expires=0 WHERE id=%d AND reply<>'sending' AND admin<>'sending' AND effects<>'running'",$id));
    if ($ok!==1) { return false; }
    foreach (self::unpack($row)['attachment_ids'] ?? [] as $upload) { OMF_Uploads::remove($upload); }
    return $wpdb->delete($table,['id'=>$id])!==false;
  }
  /** 入力のemail型項目だけで本人照合する。運用者宛先を照合に使わない。 */
  public static function personal(string $email): array
  {
    global $wpdb; $matches=[];
    foreach ($wpdb->get_results('SELECT * FROM '.self::table()." WHERE payload<>''",ARRAY_A) as $row) {
      $payload=self::unpack($row);
      foreach ($payload['email_keys'] ?? [] as $key) {
        $value=$payload['admin_info']['tag_to_text'][$key] ?? null;
        if (is_string($value) && strcasecmp($value,$email)===0) { $matches[]=$row; break; }
      }
    }
    return $matches;
  }
  public static function cleanup(): void
  {
    self::recover(); global $wpdb;
    $rows=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.self::table().' WHERE expires<=%d LIMIT 100',time()));
    foreach ($rows as $id) { self::purge((int)$id); }
  }
}
