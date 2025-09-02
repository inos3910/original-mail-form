<?php
// 管理画面 Google連携設定
?>
<div class="wrap">
  <h1>Google連携設定</h1>
  <div class="admin_optional">
    <form method="post" action="options.php" autocomplete="off">
      <?php
      settings_fields('omf-google-settings-group');
      do_settings_sections('omf-google-settings-group');
      // settings_errors();

      $values        = $this->get_google_settings_values();
      $client_id     = $values['client_id'];
      $client_secret = $values['client_secret'];
      $redirect_uri  = $values['redirect_uri'];
      $access_token  = $values['access_token'];
      $is_credential = !empty($client_id) && !empty($redirect_uri) && !empty($client_secret);
      ?>
      <p>Googleスプレッドシートに保存する機能を有効化したい場合に必要なGoogle連携の設定です。</p>
      <h2>設定方法</h2>
      <p>
        Google Cloud コンソールからクライアントID／クライアントシークレットを取得します。
      </p>
      <ol>
        <li><a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferer">Google Cloud コンソール</a>で新しいプロジェクトを作成</li>
        <li>「APIとサービス」のライブラリから「GoogleSheet API」を検索し有効化</li>
        <li>「認証情報を作成」からOAuthクライアントIDを選択し作成</li>
        <li>クライアントIDとクライアントシークレットが表示されるので、それをこちらの画面に入力・保存</li>
        <li>Google Cloud コンソールの画面に戻り「OAuth同意画面」を設定する</li>
      </ol>
      <p>クライアントIDとクライアントシークレットを保存すると、「OAuth接続する」ボタンが出るのでGoogleアカウントとリンクすると連携が完了します。</p>
      <table class="form-table">
        <tr>
          <th scope="row">
            <label for="omf_google_client_id">クライアントID</label>
          </th>
          <td>
            <input class="regular-text code" type="text" spellcheck="false" id="omf_google_client_id" name="omf_google_client_id" value="<?php echo esc_attr($client_id) ?>" autocomplete="off">
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="omf_google_client_secret">クライアントシークレット</label>
          </th>
          <td>
            <input class="regular-text code" type="password" spellcheck="false" id="omf_google_client_secret" name="omf_google_client_secret" value="<?php echo esc_attr($client_secret) ?>" autocomplete="new-password">
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="omf_google_redirect_uri">リダイレクトURL</label>
          </th>
          <td>
            <input class="regular-text code" type="text" id="omf_google_redirect_uri" name="omf_google_redirect_uri" value="<?php echo esc_attr($redirect_uri) ?>" autocomplete="off" readonly="readonly" onfocus="this.select();">
          </td>
        </tr>
      </table>
      <?php submit_button() ?>
    </form>
    <hr>
    <?php
    if ($is_credential) {
      $google_auth_url = $this->get_google_auth_url($client_id, $redirect_uri);
    ?>
      <h2>OAuth接続</h2>
      <?php
      if (empty($access_token)) {
      ?>
        <p class="omf-status omf-status--ng">
          <strong>接続が無効です</strong>
        </p>
        <a class="button button-large omf-button--1" href="<?php echo esc_url($google_auth_url) ?>">OAuth接続する</a>
      <?php } else {
        $remove_oauth_uri = admin_url('edit.php?post_type=original_mail_forms&page=omf_google_settings&remove_oauth=1');
      ?>
        <p class="omf-status omf-status--ok">
          <strong>接続が有効です</strong>
        </p>
        <a class="button button-large omf-button--2" href="<?php echo esc_url($remove_oauth_uri) ?>">OAuth接続を解除する</a>
      <?php } ?>
    <?php
    }
    ?>
  </div>
</div>