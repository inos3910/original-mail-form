<?php
// 管理画面 エクスポート：「フォームを選ぶ」の時に使うフォーム・項目・共通設定の選択（omf_port.php から読み込む）
use Sharesl\Original\MailForm\OMF_Port_Schema;

/** @var \WP_Post[] $forms omf_port.php で取得したフォーム一覧 */
?>
<div class="omf-port-card__body omf-port-selection" data-omf-port-panel>
  <fieldset class="omf-port-group">
    <legend class="omf-port-group__label">書き出すフォーム</legend>
    <?php if ($forms === []) : ?>
      <p class="omf-port-help">書き出せるフォームがありません。</p>
    <?php else : ?>
      <div class="omf-port-options">
        <label class="omf-port-option omf-port-option--all" data-omf-port-all hidden>
          <input type="checkbox" data-omf-port-check-all="form_ids[]">
          <span class="omf-port-option__text">
            <span class="omf-port-option__title">すべてのフォーム（<?php echo esc_html((string) count($forms)); ?>件）</span>
          </span>
        </label>
        <?php foreach ($forms as $form) : ?>
          <label class="omf-port-option">
            <input type="checkbox" name="form_ids[]" value="<?php echo esc_attr((string) $form->ID); ?>">
            <span class="omf-port-option__text">
              <span class="omf-port-option__title"><?php echo esc_html($form->post_title !== '' ? $form->post_title : '（タイトルなし）'); ?><code><?php echo esc_html($form->post_name); ?></code></span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </fieldset>

  <fieldset class="omf-port-group">
    <legend class="omf-port-group__label">書き出す項目</legend>
    <div class="omf-port-options">
      <?php foreach (OMF_Port_Schema::PARTS as $key => $part) : ?>
        <label class="omf-port-option">
          <input type="checkbox" name="parts[]" value="<?php echo esc_attr($key); ?>" data-label="<?php echo esc_attr($part['label']); ?>" checked>
          <span class="omf-port-option__text">
            <span class="omf-port-option__title"><?php echo esc_html($part['label']); ?></span>
            <span class="omf-port-option__desc"><?php echo esc_html($part['description']); ?></span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
    <p class="omf-port-help">選んだ項目は、選んだすべてのフォームに共通です。</p>
  </fieldset>

  <fieldset class="omf-port-group">
    <legend class="omf-port-group__label">共通設定</legend>
    <div class="omf-port-options">
      <label class="omf-port-option">
        <input type="checkbox" name="include_settings" value="1">
        <span class="omf-port-option__text">
          <span class="omf-port-option__title">共通設定も書き出す</span>
          <span class="omf-port-option__desc">フォームだけを移す場合は不要です。</span>
        </span>
      </label>
    </div>
  </fieldset>
</div>
