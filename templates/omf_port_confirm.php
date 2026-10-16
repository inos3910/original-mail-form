<?php
// 管理画面 設定ページ（エクスポート・インポート）の確認画面と結果画面
// $omf_port_result（結果）または $omf_port_pending（確認待ちの内容）のどちらかが渡される。
use Sharesl\Original\MailForm\OMF_Port_Admin;
use Sharesl\Original\MailForm\OMF_Port_Plan;

$action_labels = ['create' => '新規作成', 'update' => '上書き', 'blocked' => '取り込めません'];
?>
<div class="admin_optional">
<?php if ($omf_port_result !== null) : ?>
  <h2>取り込み結果</h2>
  <ul>
    <li>設定：<?php echo esc_html((string) $omf_port_result['settings']); ?>件を更新</li>
    <li>フォーム：新規作成 <?php echo esc_html((string) $omf_port_result['created']); ?>件、上書き <?php echo esc_html((string) $omf_port_result['updated']); ?>件</li>
  </ul>
  <?php if ($omf_port_result['blocked'] !== []) : ?>
    <div class="notice notice-error inline"><p>次のフォームは取り込みを中止しました。</p>
      <ul style="list-style:disc;margin-left:24px;">
        <?php foreach ($omf_port_result['blocked'] as $blocked) : ?>
          <li><?php echo esc_html($blocked['slug']); ?>：<?php echo esc_html(implode(' / ', $blocked['errors'])); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <?php if ($omf_port_result['warnings'] !== []) : ?>
    <div class="notice notice-warning inline"><p>確認してください。</p>
      <ul style="list-style:disc;margin-left:24px;">
        <?php foreach ($omf_port_result['warnings'] as $warning) : ?>
          <li><?php echo esc_html($warning); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <p><a class="button" href="<?php echo esc_url(OMF_Port_Admin::tab_url()); ?>">エクスポート・インポートに戻る</a></p>
<?php else :
  $plan = OMF_Port_Plan::build($omf_port_pending['payload']);
  $source = $plan['source'];
  $executable = count(array_filter($plan['forms'], static fn($f) => $f['action'] !== 'blocked')) > 0 || ($plan['settings'] !== null && $plan['settings']['changes'] !== []);
  ?>
  <h2>取り込み内容の確認</h2>
  <p>まだ取り込んでいません。内容を確認して「取り込みを実行」を押してください。
    <?php if ($source['url'] !== '' || $source['exported_at'] !== '') : ?>
      （書き出し元：<?php echo esc_html($source['url']); ?> / <?php echo esc_html($source['exported_at']); ?>）
    <?php endif; ?>
  </p>

  <h3>設定</h3>
  <?php if ($plan['settings'] === null) : ?>
    <p>ファイルに設定は含まれていません。現在の設定は変更しません。</p>
  <?php elseif ($plan['settings']['changes'] === []) : ?>
    <p>変更はありません。</p>
  <?php else : ?>
    <table class="widefat striped" style="max-width:720px;">
      <thead><tr><th>項目</th><th>現在の値</th><th>取り込み後</th></tr></thead>
      <tbody>
        <?php foreach ($plan['settings']['changes'] as $key => $change) : ?>
          <tr>
            <td><?php echo esc_html($key); ?></td>
            <?php if ($change['secret']) : ?>
              <td colspan="2">秘密情報のため値は表示しません（上書きされます）</td>
            <?php else : ?>
              <td><?php echo esc_html($change['old']); ?></td><td><?php echo esc_html($change['new']); ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <?php if ($plan['settings'] !== null && $plan['settings']['has_secrets']) : ?>
    <p><strong>このファイルには認証キーなどの秘密情報が含まれています。</strong>取り込み後はファイルを削除してください。</p>
  <?php endif; ?>

  <h3>フォーム</h3>
  <?php if ($plan['forms'] === []) : ?>
    <p>ファイルにフォームは含まれていません。</p>
  <?php else : ?>
    <table class="widefat striped" style="max-width:720px;">
      <thead><tr><th>スラッグ</th><th>フォーム名</th><th>取り込み方</th><th>備考</th></tr></thead>
      <tbody>
        <?php foreach ($plan['forms'] as $entry) : ?>
          <tr>
            <td><?php echo esc_html($entry['slug']); ?></td>
            <td><?php echo esc_html($entry['title']); ?></td>
            <td><?php echo esc_html($action_labels[$entry['action']]); ?></td>
            <td>
              <?php foreach ($entry['errors'] as $error) : ?><div><?php echo esc_html($error); ?></div><?php endforeach; ?>
              <?php if ($entry['has_secrets']) : ?><div>秘密情報（Slack Webhook URL）を含む</div><?php endif; ?>
              <?php if ($entry['action'] === 'update') : ?><div>ID <?php echo esc_html((string) $entry['id']); ?> を上書き（送信データはそのまま）</div><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($plan['warnings'] !== []) : ?>
    <div class="notice notice-warning inline" style="margin-top:16px;"><p>警告</p>
      <ul style="list-style:disc;margin-left:24px;">
        <?php foreach ($plan['warnings'] as $warning) : ?>
          <li><?php echo esc_html($warning); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div style="display:flex;gap:8px;margin-top:16px;">
    <?php if ($executable) : ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="omf_port_run">
        <input type="hidden" name="omf_port_token" value="<?php echo esc_attr($omf_port_pending['token']); ?>">
        <?php wp_nonce_field('omf_port_run'); ?>
        <?php submit_button('取り込みを実行', 'primary', 'submit', false); ?>
      </form>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="omf_port_cancel">
      <?php wp_nonce_field('omf_port_cancel'); ?>
      <?php submit_button('取りやめる', 'secondary', 'submit', false); ?>
    </form>
  </div>
<?php endif; ?>
</div>
