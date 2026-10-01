<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** サーバーcronからWeb環境で起動する。登録用の秘密は管理者だけへ渡す。 */
class OMF_Delivery_Cron
{
  const KEY = 'omf_delivery_cron_key';
  public function __construct()
  {
    add_action('rest_api_init', [$this, 'routes']);
    add_action('wp_ajax_omf_cron_setup', [$this, 'setup']);
  }
  public function routes(): void
  {
    register_rest_route('omf/v1', '/delivery-cron', ['methods'=>'POST', 'callback'=>[$this,'endpoint'], 'permission_callback'=>[$this,'authorize']]);
  }
  private static function secure(): bool
  {
    return is_ssl() || (wp_get_environment_type()==='local' && in_array(wp_parse_url(rest_url(), PHP_URL_HOST), ['localhost','127.0.0.1','::1'], true));
  }
  public function authorize(\WP_REST_Request $request): bool
  {
    $key = get_option(self::KEY, '');
    return self::secure() && is_string($key) && preg_match('/\A[0-9a-f]{64}\z/', $key) === 1 && hash_equals($key, (string)$request->get_header('X-OMF-Cron-Key'));
  }
  public function endpoint(\WP_REST_Request $request): array|\WP_Error
  {
    if ($request->get_param('probe') === '1') {
      update_option('omf_delivery_cron_probe_at', time(), false);
      return ['ok'=>true, 'probe'=>true];
    }
    try { OMF_Delivery::run('server_cron'); }
    catch (\Throwable $e) { return new \WP_Error('omf_cron_failed', '定期処理を完了できませんでした。送信状況を確認してください。', ['status'=>500]); }
    return ['ok'=>true];
  }
  public static function command(string $kind): string|\WP_Error
  {
    if (!current_user_can('manage_options')) { return new \WP_Error('forbidden', 'この操作の権限がありません。'); }
    if (!in_array($kind, ['command','crontab','probe_command'], true)) { return new \WP_Error('operation', '操作を選び直してください。'); }
    $url = rest_url('omf/v1/delivery-cron');
    if (wp_parse_url($url, PHP_URL_SCHEME)!=='https' && !(wp_get_environment_type()==='local' && in_array(wp_parse_url($url, PHP_URL_HOST), ['localhost','127.0.0.1','::1'], true))) {
      return new \WP_Error('https', 'この設定にはHTTPSのサイトURLが必要です。サイトのSSL設定を確認してください。');
    }
    $key = get_option(self::KEY, '');
    if (!$key) { add_option(self::KEY, bin2hex(random_bytes(32)), '', false); $key = get_option(self::KEY, ''); }
    if (!is_string($key) || preg_match('/\A[0-9a-f]{64}\z/', $key)!==1) { return new \WP_Error('key', '登録用キーを再発行してください。'); }
    $command = 'curl --fail --silent --show-error --max-time 55 --request POST --header '.escapeshellarg('X-OMF-Cron-Key: '.$key).' '.escapeshellarg($url);
    if ($kind==='probe_command') { $command .= " --data 'probe=1'"; }
    $command .= ' --output /dev/null';
    // cronは引用符内の%も改行扱いするため、URLのエンコード文字を保護する。
    if ($kind!=='probe_command') { $command = str_replace('%', '\\%', $command); }
    return ($kind==='crontab' ? '* * * * * ' : '').$command;
  }
  public function setup(): void
  {
    if (!current_user_can('manage_options')) { wp_send_json_error(['message'=>'この操作の権限がありません。'], 403); }
    check_ajax_referer('omf_cron_setup', 'nonce');
    $kind = is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '';
    if ($kind==='renew') {
      update_option(self::KEY, bin2hex(random_bytes(32)), false);
      delete_option('omf_delivery_cron_probe_at');
      wp_send_json_success(['message'=>'登録用キーを再発行しました。コマンドをコピーし直し、サーバーの設定を更新してください。']);
    }
    $command = self::command($kind);
    if (is_wp_error($command)) { wp_send_json_error(['message'=>$command->get_error_message()], 400); }
    wp_send_json_success(['command'=>$command]);
  }
  public static function screen(): void
  {
    if (!current_user_can('manage_options')) { return; }
    echo '<section class="omf-delivery-card omf-cron-setup" data-omf-cron-nonce="'.esc_attr(wp_create_nonce('omf_cron_setup')).'"><h2>サーバーcronの設定</h2><p>WP-CLIやWordPressの設置パスを調べずに設定できます。サーバー管理画面の「cron」「定期実行」を開き、以下を登録してください。</p>';
    echo '<ol><li>下の「登録用コマンドをコピー」を押します。</li><li>サーバー管理画面の実行間隔を「1分ごと」にします。</li><li>「コマンド」欄へ貼り付けて保存します。</li></ol>';
    echo '<p>分・時・日・月・曜日を別々に入力する画面では、すべて <code>*</code> にします。crontabへ1行で登録する場合は「crontab形式をコピー」を使います。</p><div class="omf-delivery-toolbar"><button type="button" class="button button-primary" data-omf-cron-copy="command">登録用コマンドをコピー</button><button type="button" class="button" data-omf-cron-copy="crontab">crontab形式をコピー</button></div>';
    echo '<h3>設定後の確認</h3><p>「サーバーcronの最終実行」が更新されることを確認します。更新されない場合は「接続確認用コマンドをコピー」を使い、サーバーのコマンド実行画面かターミナルで1回実行します。この確認コマンドはメールを送りません。</p><button type="button" class="button" data-omf-cron-copy="probe_command">接続確認用コマンドをコピー</button>';
    $probe = (int)get_option('omf_delivery_cron_probe_at', 0);
    echo '<p>接続確認の最終成功：<span data-omf-cron-probe>'.($probe ? esc_html(wp_date('Y/m/d H:i:s', $probe)) : '未確認').'</span>（定期実行・メール到着の確認とは別です）</p><p class="omf-cron-feedback" role="status" aria-live="polite"></p>';
    echo '<details><summary>設定できない場合・登録用キーの管理</summary><p>コマンドにはこのサイト専用の登録用キーが含まれます。公開・共有しないでください。サーバーにcurlがない場合、curlのパスを指定する必要がある場合、サイトにアクセス制限がある場合は、サーバー管理者へ確認してください。</p><p>キーを再発行すると、現在登録しているコマンドは使えなくなります。サーバー側の登録内容も更新してください。</p><button type="button" class="button" data-omf-cron-renew>登録用キーを再発行</button></details></section>';
  }
}
