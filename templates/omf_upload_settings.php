<?php
if (!defined('ABSPATH')) { exit; }
$storage = \Sharesl\Original\MailForm\OMF_Upload_Directory::inspect();
$fixed = defined('OMF_PRIVATE_UPLOAD_DIR');
?>
<section class="omf-upload-settings" aria-labelledby="omf-upload-title">
  <h2 id="omf-upload-title">添付ファイルの保存先</h2>
  <p>フォームの添付ファイルを、Webから直接配信しない専用フォルダに保管します。添付を保存するまでフォルダは作成しません。</p>
  <dl>
    <dt>状態</dt>
    <dd><?php echo esc_html($storage['error'] !== '' ? '使用不可：設定を確認してください' : ($storage['exists'] ? '作成済み' : '未作成（添付の初回保存時に作成）')); ?></dd>
    <dt>フォルダ名<?php echo $storage['exists'] ? '' : '（予定）'; ?></dt>
    <dd><code><?php echo esc_html($storage['path'] !== '' ? basename(str_replace('\\', '/', rtrim($storage['path'], '/\\'))) : '未設定'); ?></code></dd>
  </dl>
  <?php if ($storage['error'] !== '') : ?>
    <p class="omf-upload-settings__error" role="alert"><?php echo esc_html($storage['error']); ?></p>
  <?php endif; ?>
  <p class="description">掃除や参照ではフォルダを作成しません。配送待ち・保存対象の添付が残っている場合は、フォルダを移動・削除しないでください。</p>
  <p class="description">自動選定された一時領域は、サーバー側の掃除で削除される可能性があります。添付を長期間保持する場合は、詳細設定で永続的な専用フォルダを指定してください。</p>
  <details>
    <summary>保存先の詳細設定</summary>
    <p>サーバー管理者向けの設定です。公開ディレクトリ、別サイト、別名URLからも配信されない場所を指定してください。サーバーのすべての公開設定をプラグインだけで確認することはできません。</p>
    <p>Apache向けのアクセス拒否ファイルも作成します。Nginxではこのファイルは効かないため、Web配信対象外への配置とサーバー側の設定が必要です。</p>
    <?php if ($fixed) : ?>
      <dl>
        <dt>保存先の絶対パス</dt>
        <dd><code><?php echo esc_html($storage['path'] ?: '未設定'); ?></code></dd>
      </dl>
      <p>保存先はサーバー設定で固定されています。変更はサーバー管理者に依頼してください。</p>
    <?php else : ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="omf_save_upload_directory">
        <?php wp_nonce_field('omf_save_upload_directory'); ?>
        <label for="omf-upload-directory">保存先の絶対パス</label>
        <input type="text" id="omf-upload-directory" name="omf_upload_directory" value="<?php echo esc_attr($storage['path']); ?>" class="large-text code" aria-describedby="omf-upload-directory-help" autocomplete="off" spellcheck="false">
        <p id="omf-upload-directory-help" class="description">親フォルダを事前に用意してください。空欄で保存すると自動選定します。設定の保存だけではフォルダを作成しません。添付が残っている間は別の保存先へ切り替えできません。</p>
        <?php submit_button('添付ファイルの保存先を設定', 'secondary', 'omf_upload_submit'); ?>
      </form>
    <?php endif; ?>
  </details>
</section>
