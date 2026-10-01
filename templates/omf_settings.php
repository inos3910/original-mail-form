<?php
//管理画面 設定ページ
?>
<div class="wrap">
  <h1>設定</h1>
  <div class="admin_optional">
    <form method="post" action="options.php" autocomplete="off">
      <?php
      settings_fields('omf-settings-group');
      do_settings_sections('omf-settings-group');
      settings_errors();
      $is_rest_api = get_option('omf_is_rest_api') === '1';
      ?>
      <table class="form-table">
        <tr>
          <th scope="row">REST API</th>
          <td>
            <label>
              <input type="checkbox" name="omf_is_rest_api" value="1" <?php if ($is_rest_api) echo 'checked'; ?>>
              有効化
            </label>
          </td>
        </tr>
        <tr><th scope="row"><label for="omf_retention_days">送信データの保存期間（日）</label></th><td>
          <input type="number" min="0" id="omf_retention_days" name="omf_retention_days" value="<?php echo esc_attr((string) get_option('omf_retention_days', 0)); ?>">
          <p class="description">0は無期限。1以上を保存すると期間を過ぎたDB保存データを順次削除します。メールボックスや外部連携先のデータは削除しません。</p>
        </td></tr>
      </table>
      <?php submit_button(); ?>
    </form>
  </div>
  <?php
  $postal = \Sharesl\Original\MailForm\OMF_Postal_Updater::current();
  $notice = get_transient('omf_postal_notice_' . get_current_user_id());
  if (is_array($notice)) {
    delete_transient('omf_postal_notice_' . get_current_user_id());
    echo '<div class="notice ' . (!empty($notice['error']) ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($notice['message']) . '</p></div>';
  }
  ?>
  <section class="omf-postal-card" aria-labelledby="omf-postal-title">
    <h2 id="omf-postal-title">郵便番号からの住所自動入力</h2>
    <p>日本郵便の最新データを取得して、このサイトの住所データを更新します。フォームの項目や入力内容は変更しません。</p>
    <dl>
      <dt><?php echo isset($postal['downloaded_at']) ? 'データ取得日時' : '同梱データの更新日'; ?></dt>
      <dd><?php echo esc_html($postal['downloaded_at'] ?? $postal['date'] ?? '不明'); ?></dd>
      <dt>登録郵便番号</dt><dd><?php echo esc_html(number_format_i18n($postal['postal_codes'] ?? 0)); ?>件</dd>
      <dt>最新データの確認日時</dt><dd><?php echo esc_html(get_option('omf_postal_checked_at', '未確認')); ?></dd>
    </dl>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-omf-postal-update>
      <input type="hidden" name="action" value="omf_update_postal">
      <?php wp_nonce_field('omf_update_postal'); ?>
      <button type="submit" class="button button-primary">郵便番号データを更新</button>
      <p role="status" aria-live="polite"></p>
    </form>
    <p class="description">更新ボタンを押したときだけ日本郵便に接続します。失敗しても現在のデータを使い続けられます。</p>
  </section>
</div>