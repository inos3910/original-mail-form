<?php
// 管理画面 設定ページ（エクスポート・インポート）の確認画面
// $omf_port_result（結果）または $omf_port_pending（確認待ちの内容）のどちらかが渡される。結果は omf_port_result.php で表示する。
use Sharesl\Original\MailForm\OMF_Port_Plan;
use Sharesl\Original\MailForm\OMF_Port_Schema;

if ($omf_port_result !== null) {
  require plugin_dir_path(__DIR__) . 'templates/omf_port_result.php';
  return;
}

$action_labels = ['create' => '新規作成', 'update' => '上書き', 'blocked' => '取り込めません'];
$plan = OMF_Port_Plan::build($omf_port_pending['payload']);
$source = $plan['source'];
$executable = count(array_filter($plan['forms'], static fn($f) => $f['action'] !== 'blocked')) > 0 || ($plan['settings'] !== null && $plan['settings']['changes'] !== []);
$exported_at = $source['exported_at'] !== '' && strtotime($source['exported_at']) !== false ? wp_date('Y年n月j日 H:i', strtotime($source['exported_at'])) : $source['exported_at'];
?>
<div class="omf-port">
  <section class="omf-port-card" aria-labelledby="omf-port-confirm-title">
    <div class="omf-port-card__head">
      <h2 class="omf-port-card__title" id="omf-port-confirm-title">取り込み内容の確認</h2>
      <p class="omf-port-card__lead">まだ取り込んでいません。内容を確認して「取り込みを実行」を押してください。</p>
    </div>
    <div class="omf-port-card__body">
      <?php if ($source['url'] !== '' || $exported_at !== '') : ?>
        <dl class="omf-port-facts">
          <dt>書き出し元</dt><dd><?php echo esc_html($source['url'] !== '' ? $source['url'] : '―'); ?></dd>
          <dt>書き出し日時</dt><dd><?php echo esc_html($exported_at !== '' ? $exported_at : '―'); ?></dd>
        </dl>
      <?php endif; ?>

      <div class="omf-port-group">
        <h3 class="omf-port-group__label">共通設定</h3>
        <?php if ($plan['settings'] === null) : ?>
          <p class="omf-port-help">ファイルに共通設定は含まれていません。このサイトの設定は変更しません。</p>
        <?php elseif ($plan['settings']['changes'] === []) : ?>
          <p class="omf-port-help">このサイトの設定と同じため、変更はありません。</p>
        <?php else : ?>
          <table class="widefat striped omf-port-table">
            <thead><tr><th scope="col">項目</th><th scope="col">現在の値</th><th scope="col">取り込み後</th></tr></thead>
            <tbody>
              <?php foreach ($plan['settings']['changes'] as $key => $change) : ?>
                <tr><td><code><?php echo esc_html($key); ?></code></td><td><?php echo esc_html($change['old']); ?></td><td><?php echo esc_html($change['new']); ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="omf-port-group">
        <h3 class="omf-port-group__label">フォーム</h3>
        <?php if ($plan['forms'] === []) : ?>
          <p class="omf-port-help">ファイルにフォームは含まれていません。</p>
        <?php else : ?>
          <ul class="omf-port-items">
            <?php foreach ($plan['forms'] as $entry) : ?>
              <li class="omf-port-item">
                <div class="omf-port-item__head">
                  <span class="omf-port-item__name"><?php echo esc_html($entry['title'] !== '' ? $entry['title'] : '（タイトルなし）'); ?><code><?php echo esc_html($entry['slug']); ?></code></span>
                  <span class="omf-port-badge omf-port-badge--<?php echo esc_attr($entry['action']); ?>"><?php echo esc_html($action_labels[$entry['action']]); ?></span>
                </div>
                <?php if ($entry['action'] !== 'blocked') : ?>
                  <p class="omf-port-item__note">取り込む項目：<?php echo esc_html(OMF_Port_Schema::part_labels($entry['parts'])); ?></p>
                <?php endif; ?>
                <?php if ($entry['action'] === 'update') : ?>
                  <p class="omf-port-item__note">このサイトのフォーム（ID <?php echo esc_html((string) $entry['id']); ?>）を上書きします。送信データと、含まれない項目は変更しません。</p>
                <?php endif; ?>
                <?php if ($entry['removals'] !== []) : ?>
                  <p class="omf-port-item__note">ファイルに無いため削除する設定：<?php echo esc_html(implode('、', $entry['removals'])); ?></p>
                <?php endif; ?>
                <?php foreach ($entry['errors'] as $error) : ?>
                  <p class="omf-port-item__note is-error"><?php echo esc_html($error); ?></p>
                <?php endforeach; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <?php if ($plan['warnings'] !== []) : ?>
        <div class="notice notice-warning inline">
          <p><strong>確認してください</strong></p>
          <ul>
            <?php foreach ($plan['warnings'] as $warning) : ?>
              <li><?php echo esc_html($warning); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
    <div class="omf-port-card__foot">
      <p class="omf-port-summary"><?php echo esc_html($executable ? '取りやめると、アップロードしたファイルの内容は破棄されます。' : '取り込める内容がありません。'); ?></p>
      <div class="omf-port-card__actions">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="omf_port_cancel">
          <?php wp_nonce_field('omf_port_cancel'); ?>
          <?php submit_button('取りやめる', 'secondary', 'submit', false); ?>
        </form>
        <?php if ($executable) : ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="omf_port_run">
            <input type="hidden" name="omf_port_token" value="<?php echo esc_attr($omf_port_pending['token']); ?>">
            <?php wp_nonce_field('omf_port_run'); ?>
            <?php submit_button('取り込みを実行', 'primary', 'submit', false); ?>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>
</div>
