<?php
// 管理画面 設定ページ（エクスポート・インポート）の結果画面（omf_port_confirm.php から読み込む）
use Sharesl\Original\MailForm\OMF_Port_Admin;

?>
<div class="omf-port">
  <section class="omf-port-card" aria-labelledby="omf-port-result-title">
    <div class="omf-port-card__head">
      <h2 class="omf-port-card__title" id="omf-port-result-title">取り込み結果</h2>
      <p class="omf-port-card__lead">取り込みが終わりました。</p>
    </div>
    <div class="omf-port-card__body">
      <dl class="omf-port-facts">
        <dt>共通設定</dt><dd><?php echo esc_html((string) $omf_port_result['settings']); ?>件を更新</dd>
        <dt>フォーム</dt><dd>新規作成 <?php echo esc_html((string) $omf_port_result['created']); ?>件、上書き <?php echo esc_html((string) $omf_port_result['updated']); ?>件</dd>
      </dl>
      <?php if ($omf_port_result['blocked'] !== []) : ?>
        <div class="notice notice-error inline">
          <p><strong>次のフォームは取り込みを中止しました</strong></p>
          <ul>
            <?php foreach ($omf_port_result['blocked'] as $blocked) : ?>
              <li><code><?php echo esc_html($blocked['slug']); ?></code>：<?php echo esc_html(implode(' / ', $blocked['errors'])); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($omf_port_result['warnings'] !== []) : ?>
        <div class="notice notice-warning inline">
          <p><strong>確認してください</strong></p>
          <ul>
            <?php foreach ($omf_port_result['warnings'] as $warning) : ?>
              <li><?php echo esc_html($warning); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
    <div class="omf-port-card__foot">
      <div class="omf-port-card__actions">
        <a class="button" href="<?php echo esc_url(OMF_Port_Admin::tab_url()); ?>">エクスポート・インポートに戻る</a>
      </div>
    </div>
  </section>
</div>
