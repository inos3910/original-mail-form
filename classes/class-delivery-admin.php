<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 配送設定と、メール本文を露出しない送信状況画面。 */
class OMF_Delivery_Admin
{
  const LABELS=['pending'=>'送信待ち','sending'=>'送信中','sent'=>'送信成功','skipped'=>'自動返信なし','failed'=>'送信失敗','unknown'=>'結果不明','cancelled'=>'取消済み'];
  public function __construct()
  {
    add_action('add_meta_boxes_'.OMF_Config::NAME,static function(){ add_meta_box('omf-delivery-settings','送信方法',[self::class,'settings'],OMF_Config::NAME,'normal','default'); });
    add_action('admin_menu',static function(){ add_submenu_page('edit.php?post_type='.OMF_Config::NAME,'送信状況','送信状況','manage_options','omf-deliveries',[self::class,'screen']); });
    add_action('admin_post_omf_delivery_action',[self::class,'action']);
    add_action('admin_enqueue_scripts',static function(){
      $screen=get_current_screen();
      if ($screen && ($screen->post_type===OMF_Config::NAME || str_contains($screen->id,'omf-deliveries'))) {
        wp_enqueue_style('omf-delivery',plugins_url('assets/delivery-admin.css',__DIR__),[],filemtime(dirname(__DIR__).'/assets/delivery-admin.css'));
        wp_enqueue_script('omf-delivery',plugins_url('assets/delivery-admin.js',__DIR__),[],filemtime(dirname(__DIR__).'/assets/delivery-admin.js'),true);
      }
    });
  }
  public static function validate(int $id,array $input): string|\WP_Error
  {
    $mode=$input['omf_delivery_mode'] ?? OMF_Delivery::mode($id);
    if (!is_string($mode) || !isset(OMF_Delivery::MODES[$mode])) { return new \WP_Error('mode','送信方法を選び直してください。'); }
    if ($mode==='parallel') {
      if (!OMF_Delivery_Runner::ready()) { return new \WP_Error('parallel','送信状況画面で並列送信の環境確認を実行してください。'); }
      foreach (['title','mail','to','from','from_name','reply_to'] as $part) {
        $key='cf_omf_admin_'.$part; $value=$input[$key] ?? get_post_meta($id,$key,true);
        if (is_string($value) && str_contains($value,'omf_reply_mail_sended')) { return new \WP_Error('dependency','通知メールが自動返信の結果を参照しているため、並列送信は選べません。'); }
      }
    }
    return $mode;
  }
  public static function settings(\WP_Post $post): void
  {
    $mode=OMF_Delivery::mode($post->ID);
    $help=['serial'=>'自動返信、通知の順に送り、結果を待って完了します。通常はこちら。','parallel'=>'2通を同時に送り、両方の結果を待ちます。環境確認が必要です。','wp_async'=>'受付内容を保存して完了画面を表示し、WordPressが後から送ります。','server_cron'=>'受付内容を保存し、サーバーに設定した定期実行で送ります。'];
    echo '<div class="omf-delivery-ui"><p>送信ボタンを押した後の処理を選びます。</p><div class="omf-delivery-options">';
    foreach (OMF_Delivery::MODES as $value=>$label) {
      $disabled=$value==='parallel' && !OMF_Delivery_Runner::ready();
      echo '<label class="omf-delivery-option"><input type="radio" name="omf_delivery_mode" value="'.esc_attr($value).'"'.checked($mode,$value,false).disabled($disabled && $mode!==$value,true,false).'><span><strong>'.esc_html($label).'</strong><small>'.esc_html($help[$value]).'</small></span></label>';
    }
    echo '</div><div data-delivery-note="wp_async" class="omf-delivery-note"><strong>実行時刻は保証されません</strong><p>アクセスがない場合やWordPressの定期処理が止まっている場合、メールが送られず待機します。送信状況画面で確認してください。</p></div>';
    echo '<div data-delivery-note="server_cron" class="omf-delivery-note"><strong>コピーして1分ごとに登録します</strong><p>「送信状況」の「サーバーcronの設定」で登録用コマンドをコピーし、サーバー管理画面へ貼り付けます。WP-CLIのパス入力は不要です。</p><a href="'.esc_url(admin_url('edit.php?post_type='.OMF_Config::NAME.'&page=omf-deliveries')).'">サーバーcronの設定を開く</a></div>';
    echo '<div data-delivery-note="parallel" class="omf-delivery-note"><p>通知メールに自動返信結果のタグがある場合は利用できません。PHPの同時実行枠とSMTPの同時接続制限も確認してください。</p></div>';
    echo '<p class="omf-delivery-help">非同期・並列では、通常のDB保存がOFFでも配送用データを最長7日間保存します。設定変更前の受付は、受付時の送信方法・宛先・本文で処理します。</p><a href="'.esc_url(admin_url('edit.php?post_type='.OMF_Config::NAME.'&page=omf-deliveries')).'">送信状況・環境確認を開く</a></div>';
  }
  private static function button(string $operation,string $label,int $id=0,string $kind=''): void
  {
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('omf_delivery_action');
    foreach (['action'=>'omf_delivery_action','operation'=>$operation,'receipt'=>$id,'kind'=>$kind] as $name=>$value) { echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'">'; }
    echo '<button class="button" type="submit">'.esc_html($label).'</button></form>';
  }
  public static function screen(): void
  {
    if (!current_user_can('manage_options')) { return; }
    OMF_Delivery_Store::recover(); $rows=OMF_Delivery_Store::rows();
    echo '<div class="wrap omf-delivery-ui"><h1>送信状況</h1><p>非同期・並列の受付を新しい順に50件表示します。「送信成功」はメールサーバーへの引き渡し成功で、受信箱への到着を保証するものではありません。</p>';
    if (isset($_GET['done'])) { echo '<div class="notice notice-info"><p>処理しました。下の状態を確認してください。</p></div>'; }
    echo '<section class="omf-delivery-card"><h2>実行環境</h2><div class="omf-delivery-toolbar">';
    self::button('diagnose','並列送信の環境を確認'); self::button('run','WordPressの送信待ちを今すぐ処理');
    echo '</div><p>並列送信：'.(OMF_Delivery_Runner::ready() ? '環境確認済み（サーバー変更時は再確認）' : '未確認または利用不可').'</p>';
    foreach (['wp_async'=>'WordPress','server_cron'=>'サーバーcron'] as $key=>$name) {
      $last=(int)get_option('omf_delivery_last_'.$key,0);
      echo '<p>'.esc_html($name).'の最終実行：'.($last ? esc_html(wp_date('Y/m/d H:i:s',$last)) : '未検知').($last && $last<time()-300 ? '（5分以上経過）' : '').'</p>';
    }
    echo '</section>';
    OMF_Delivery_Cron::screen();
    echo '<p>結果不明のメールは重複を避けるため再送しません。SMTP側のログと受信先を確認してください。保存期限は受付から7日間です。</p>';
    if (!$rows) { echo '<section class="omf-delivery-card"><h2>まだ受付はありません</h2><p>フォームで非同期または並列を選んで送信すると、ここに表示されます。</p></section>'; }
    foreach ($rows as $row) {
      echo '<section class="omf-delivery-card"><h2>受付 #'.(int)$row['id'].' · '.esc_html(get_the_title((int)$row['form_id'])).'</h2><p>'.esc_html(wp_date('Y/m/d H:i:s',(int)$row['created'])).' / '.esc_html(OMF_Delivery::MODES[$row['mode']] ?? $row['mode']).'</p><div class="omf-delivery-results">';
      foreach (['reply'=>'自動返信','admin'=>'通知メール'] as $kind=>$label) {
        echo '<div><h3>'.esc_html($label).'</h3><strong>'.esc_html(self::LABELS[$row[$kind]] ?? $row[$kind]).'</strong><p>試行 '.(int)$row[$kind.'_attempts'].' / 3 回</p>';
        if ($row[$kind.'_error']) { echo '<p>'.esc_html($row[$kind.'_error']).'</p>'; }
        if ($row[$kind]==='pending' && (int)$row['created']<time()-300) { echo '<p>5分以上待機しています。実行環境を確認してください。</p>'; }
        if ($row[$kind]==='failed') { self::button('retry','失敗したメールを再試行',(int)$row['id'],$kind); }
        echo '</div>';
      }
      echo '</div><p>DB保存・外部連携：'.esc_html(['pending'=>'メールの完了待ち','running'=>'処理中','done'=>'処理完了','unknown'=>'結果不明（連携先を確認）'][$row['effects']] ?? $row['effects']).'</p></section>';
    }
    echo '</div>';
  }
  public static function action(): void
  {
    if (!current_user_can('manage_options')) { wp_die('この操作の権限がありません。', '', ['response'=>403]); }
    check_admin_referer('omf_delivery_action');
    $operation=is_string($_POST['operation'] ?? null) ? $_POST['operation'] : '';
    if ($operation==='diagnose') { OMF_Delivery_Runner::diagnose(); }
    if ($operation==='run') { OMF_Delivery::run('wp_async'); }
    if ($operation==='retry') {
      $id=absint($_POST['receipt'] ?? 0); $kind=is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '';
      if (OMF_Delivery_Store::retry($id,$kind)) {
        $row=OMF_Delivery_Store::get($id);
        if ($row['mode']==='parallel') { OMF_Delivery_Runner::parallel($id); }
        elseif ($row['mode']==='wp_async') { OMF_Delivery_Runner::schedule(); }
      }
    }
    wp_safe_redirect(admin_url('edit.php?post_type='.OMF_Config::NAME.'&page=omf-deliveries&done=1')); exit;
  }
}
