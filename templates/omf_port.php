<?php
// 管理画面 設定ページ（エクスポート・インポート）
use Sharesl\Original\MailForm\OMF_Config;
use Sharesl\Original\MailForm\OMF_Port_Admin;
use Sharesl\Original\MailForm\OMF_Port_Schema;

$omf_port_scopes = [
  'all' => ['丸ごとすべて', '全フォームの全項目と、プラグイン共通の設定を書き出します。'],
  'forms' => ['フォームを選ぶ', '書き出すフォームと項目（メール文面だけ、など）を選びます。'],
  'settings' => ['共通設定だけ', 'フォームは含めず、プラグイン共通の設定だけを書き出します。'],
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
<div class="admin_optional omf-port">
  <h2>エクスポート</h2>
  <p>書き出したファイルは、別サイトの「インポート」で取り込めます。送信データ、メールIDの連番、秘密情報（reCAPTCHA・Turnstile・Google連携のキー、Slack の Webhook URL）は含まれません。</p>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-omf-port-export data-total="<?php echo esc_attr((string) count($forms)); ?>">
    <input type="hidden" name="action" value="omf_port_export">
    <?php wp_nonce_field('omf_port_export'); ?>

    <fieldset class="omf-port-section">
      <legend>1. 書き出す範囲</legend>
      <div class="omf-port-cards">
        <?php foreach ($omf_port_scopes as $value => [$title, $text]) : ?>
          <label class="card omf-port-card">
            <input type="radio" name="scope" value="<?php echo esc_attr($value); ?>"<?php echo $value === 'all' ? ' checked' : ''; ?>>
            <span class="omf-port-card-title"><?php echo esc_html($title); ?></span>
            <span class="description"><?php echo esc_html($text); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="description">「共通設定」は、「一般」「reCAPTCHA」タブの設定（REST API の利用、送信データの保存期間、reCAPTCHA の判定スコア・項目名）です。</p>
    </fieldset>

    <div class="omf-port-section omf-port-panel" data-omf-port-panel>
      <h3>2. フォームと項目</h3>
      <p class="description">「フォームを選ぶ」を選んだ時に使われます。選んだ項目は、チェックしたすべてのフォームに共通です。</p>
      <div class="omf-port-grid">
        <fieldset class="omf-port-box">
          <legend>書き出すフォーム</legend>
          <?php if ($forms === []) : ?>
            <p class="description">書き出せるフォームがありません。</p>
          <?php else : ?>
            <div class="omf-port-bulk" data-omf-port-bulk hidden>
              <button type="button" class="button-link" data-omf-port-check="form_ids[]" data-checked="1">全選択</button>
              <button type="button" class="button-link" data-omf-port-check="form_ids[]" data-checked="0">全解除</button>
            </div>
            <ul class="omf-port-list">
              <?php foreach ($forms as $form) : ?>
                <li>
                  <label>
                    <input type="checkbox" name="form_ids[]" value="<?php echo esc_attr((string) $form->ID); ?>">
                    <span><?php echo esc_html($form->post_title !== '' ? $form->post_title : '（タイトルなし）'); ?></span>
                    <code><?php echo esc_html($form->post_name); ?></code>
                  </label>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </fieldset>
        <fieldset class="omf-port-box">
          <legend>書き出す項目</legend>
          <ul class="omf-port-list">
            <?php foreach (OMF_Port_Schema::PARTS as $key => $part) : ?>
              <li>
                <label>
                  <input type="checkbox" name="parts[]" value="<?php echo esc_attr($key); ?>" checked>
                  <span class="omf-port-part-name"><?php echo esc_html($part['label']); ?></span>
                </label>
                <p class="description"><?php echo esc_html($part['description']); ?></p>
              </li>
            <?php endforeach; ?>
          </ul>
        </fieldset>
      </div>
      <p><label><input type="checkbox" name="include_settings" value="1"> プラグイン共通の設定も含める</label></p>
    </div>

    <p class="omf-port-summary" role="status" aria-live="polite" data-omf-port-summary>全フォーム（<?php echo esc_html((string) count($forms)); ?>件）と共通設定を書き出します。</p>
    <?php submit_button('JSONをダウンロード', 'primary', 'submit', false); ?>
  </form>

  <h2 class="omf-port-heading">インポート</h2>
  <ol class="omf-port-steps">
    <li>書き出したJSONファイル（2MBまで）を選ぶ</li>
    <li>確認画面で、取り込む内容を確認する（この時点ではまだ何も変更されません）</li>
    <li>問題なければ「取り込みを実行」を押す</li>
  </ol>
  <p class="description">同じスラッグのフォームがある場合は、ファイルに含まれる項目だけを上書きします（送信データはそのまま残ります）。表示条件や連携ページは、ページのパスで取り込み先のページと照合します。</p>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
    <input type="hidden" name="action" value="omf_port_upload">
    <?php wp_nonce_field('omf_port_upload'); ?>
    <p>
      <label for="omf-port-file">1. ファイルを選ぶ</label><br>
      <input type="file" id="omf-port-file" name="omf_port_file" accept=".json,application/json" required>
    </p>
    <?php submit_button('2. 内容を確認する', 'secondary', 'submit', false); ?>
  </form>
</div>
