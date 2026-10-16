<?php
// 管理画面 設定ページ（エクスポート・インポート）
use Sharesl\Original\MailForm\OMF_Config;
use Sharesl\Original\MailForm\OMF_Port_Admin;
use Sharesl\Original\MailForm\OMF_Port_Schema;

$notice = OMF_Port_Admin::take(OMF_Port_Admin::NOTICE);
if ($notice !== null) {
  echo '<div class="notice ' . (!empty($notice['error']) ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($notice['message']) . '</p></div>';
}
$step = sanitize_key($_GET['step'] ?? '');
$omf_port_result = $step === 'result' ? OMF_Port_Admin::take(OMF_Port_Admin::RESULT) : null;
$omf_port_pending = $step === 'confirm' ? OMF_Port_Admin::pending() : null;
if ($omf_port_result !== null || $omf_port_pending !== null) {
  require plugin_dir_path(__DIR__) . 'templates/omf_port_confirm.php';
  return;
}
$forms = get_posts([
  'post_type' => OMF_Config::NAME,
  'post_status' => OMF_Port_Schema::FORM_STATUSES,
  'numberposts' => -1,
  'orderby' => 'title',
  'order' => 'ASC',
]);
?>
<div class="admin_optional">
  <h2>エクスポート</h2>
  <p>設定とフォームをJSONファイルに書き出します。別のサイトの同じプラグインで取り込めます。送信データ（保存されたお問い合わせ内容）は含まれません。</p>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="omf_port_export">
    <?php wp_nonce_field('omf_port_export'); ?>
    <table class="form-table">
      <tr>
        <th scope="row">設定</th>
        <td>
          <label><input type="checkbox" name="include_settings" value="1" checked> 設定を含める</label>
          <p class="description">REST API・保存期間・reCAPTCHAのスコアとフィールド名を書き出します。</p>
        </td>
      </tr>
      <tr>
        <th scope="row">フォーム</th>
        <td>
          <fieldset>
            <label><input type="radio" name="forms_mode" value="all" checked> すべてのフォーム</label><br>
            <label><input type="radio" name="forms_mode" value="selected"> 選んだフォームだけ</label><br>
            <label><input type="radio" name="forms_mode" value="none"> フォームは含めない</label>
            <?php if ($forms !== []) : ?>
              <ul style="margin:8px 0 0 24px;">
                <?php foreach ($forms as $form) : ?>
                  <li><label><input type="checkbox" name="form_ids[]" value="<?php echo esc_attr((string) $form->ID); ?>"> <?php echo esc_html($form->post_title !== '' ? $form->post_title : '（タイトルなし）'); ?>（<?php echo esc_html($form->post_name); ?>）</label></li>
                <?php endforeach; ?>
              </ul>
              <p class="description">「選んだフォームだけ」を選んだ時に、チェックしたフォームを書き出します。</p>
            <?php else : ?>
              <p class="description">書き出せるフォームがありません。</p>
            <?php endif; ?>
          </fieldset>
        </td>
      </tr>
      <tr>
        <th scope="row">秘密情報</th>
        <td>
          <label><input type="checkbox" name="include_secrets" value="1"> 秘密情報も含める</label>
          <p class="description">reCAPTCHA・Turnstile・Google連携のキー／シークレットと、Slack通知のWebhook URLを含めます。<strong>含めたファイルは第三者に渡らないよう厳重に管理し、取り込み後は削除してください。</strong>チェックしない場合、取り込み先の既存の値はそのまま残ります。</p>
        </td>
      </tr>
    </table>
    <?php submit_button('JSONをダウンロード', 'primary', 'submit', false); ?>
  </form>

  <h2 style="margin-top:32px;">インポート</h2>
  <p>書き出したJSONファイル（2MBまで）を選ぶと、取り込む前に内容を確認できます。同じスラッグのフォームがある場合は上書きします。</p>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
    <input type="hidden" name="action" value="omf_port_upload">
    <?php wp_nonce_field('omf_port_upload'); ?>
    <input type="file" name="omf_port_file" accept=".json,application/json" required>
    <?php submit_button('内容を確認する', 'secondary', 'submit', false); ?>
  </form>
</div>
