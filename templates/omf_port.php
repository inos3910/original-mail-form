<?php
// 管理画面 設定ページ（エクスポート・インポート）
use Sharesl\Original\MailForm\OMF_Config;
use Sharesl\Original\MailForm\OMF_Port_Admin;
use Sharesl\Original\MailForm\OMF_Port_Schema;

$omf_port_scopes = [
  'all' => ['丸ごとすべて', 'すべてのフォームの全項目と、共通設定を書き出します。'],
  'forms' => ['フォームを選ぶ', '書き出すフォームと、その中の項目（メール文面だけ、など）を選びます。'],
  'settings' => ['共通設定だけ', 'フォームは含めず、共通設定だけを書き出します。'],
];

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
<div class="omf-port">
  <section class="omf-port-card" aria-labelledby="omf-port-export-title">
    <div class="omf-port-card__head">
      <h2 class="omf-port-card__title" id="omf-port-export-title">エクスポート</h2>
      <p class="omf-port-card__lead">このサイトのフォームと設定を JSON ファイルに書き出します。別のサイトの「インポート」で取り込めます。</p>
      <p class="omf-port-help">送信データ、メールIDの連番、秘密情報（reCAPTCHA・Turnstile・Google連携のキー、Slack の Webhook URL）は書き出しません。</p>
    </div>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-omf-port-export data-total="<?php echo esc_attr((string) count($forms)); ?>">
      <input type="hidden" name="action" value="omf_port_export">
      <?php wp_nonce_field('omf_port_export'); ?>
      <div class="omf-port-card__body">
        <fieldset class="omf-port-group">
          <legend class="omf-port-group__label">書き出す範囲</legend>
          <div class="omf-port-options">
            <?php foreach ($omf_port_scopes as $value => [$title, $text]) : ?>
              <label class="omf-port-option">
                <input type="radio" name="scope" value="<?php echo esc_attr($value); ?>"<?php echo $value === 'all' ? ' checked' : ''; ?>>
                <span class="omf-port-option__text">
                  <span class="omf-port-option__title"><?php echo esc_html($title); ?></span>
                  <span class="omf-port-option__desc"><?php echo esc_html($text); ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="omf-port-help">共通設定は、「一般」「reCAPTCHA」タブの設定（REST API の利用、送信データの保存期間、reCAPTCHA の判定スコア・項目名）です。</p>
        </fieldset>

        <?php require plugin_dir_path(__DIR__) . 'templates/omf_port_select.php'; ?>
      </div>
      <div class="omf-port-card__foot">
        <p class="omf-port-summary" role="status" aria-live="polite" data-omf-port-summary>すべてのフォーム（<?php echo esc_html((string) count($forms)); ?>件）と共通設定を書き出します。</p>
        <div class="omf-port-card__actions">
          <?php submit_button('JSONをダウンロード', 'primary', 'submit', false); ?>
        </div>
      </div>
    </form>
  </section>

  <section class="omf-port-card" aria-labelledby="omf-port-import-title">
    <div class="omf-port-card__head">
      <h2 class="omf-port-card__title" id="omf-port-import-title">インポート</h2>
      <p class="omf-port-card__lead">エクスポートした JSON ファイルを取り込みます。次の確認画面で内容を見てから「取り込みを実行」を押すまで、このサイトは何も変わりません。</p>
    </div>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
      <input type="hidden" name="action" value="omf_port_upload">
      <?php wp_nonce_field('omf_port_upload'); ?>
      <div class="omf-port-card__body">
        <div class="omf-port-field">
          <label class="omf-port-group__label" for="omf-port-file">JSON ファイル</label>
          <input type="file" id="omf-port-file" name="omf_port_file" accept=".json,application/json" required aria-describedby="omf-port-file-help">
          <p class="omf-port-help" id="omf-port-file-help">2MB まで。このプラグインの「エクスポート」で書き出したファイルを選んでください。</p>
        </div>
        <div class="omf-port-group">
          <h3 class="omf-port-group__label">取り込みのルール</h3>
          <dl class="omf-port-facts">
            <dt>同じスラッグのフォームがある</dt>
            <dd>ファイルに含まれる項目だけを上書きします。送信データはそのまま残ります。</dd>
            <dt>同じスラッグのフォームがない</dt>
            <dd>新しいフォームとして作成します。</dd>
            <dt>表示条件・連携ページ</dt>
            <dd>ページのパスで、このサイトのページと照合します。</dd>
          </dl>
        </div>
      </div>
      <div class="omf-port-card__foot">
        <p class="omf-port-summary">次の画面で、取り込む内容を確認します。</p>
        <div class="omf-port-card__actions">
          <?php submit_button('内容を確認する', 'primary', 'submit', false); ?>
        </div>
      </div>
    </form>
  </section>
</div>
