<?php
//管理画面 reCAPTCHA設定
?>
<div class="wrap">
  <h1>reCAPTCHA設定</h1>
  <div class="admin_optional">
    <form method="post" action="options.php" autocomplete="off">
      <?php
      settings_fields('recaptcha-settings-group');
      do_settings_sections('recaptcha-settings-group');
      settings_errors();

      $recaptcha_site_key = !empty(get_option('omf_recaptcha_site_key')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_site_key'))) : '';
      $recaptcha_secret_key = !empty(get_option('omf_recaptcha_secret_key')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_secret_key'))) : '';
      $recaptcha_score = !empty(get_option('omf_recaptcha_score')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_score'))) : '';
      $recaptcha_field_name = !empty(get_option('omf_recaptcha_field_name')) ? sanitize_text_field(wp_unslash(get_option('omf_recaptcha_field_name'))) : 'g-recaptcha-response';
      ?>
      <p><a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">reCAPTCHA v3 コンソールでキーを取得 →</a></p>
      <table class="form-table">
        <tr>
          <th scope="row">
            <label for="omf_recaptcha_site_key">reCAPTCHA v3 サイトキー</label>
          </th>
          <td>
            <p>
              <input class="regular-text code" type="text" name="omf_recaptcha_site_key" id="omf_recaptcha_site_key" spellcheck="false" value="<?php echo esc_attr($recaptcha_site_key); ?>" autocomplete="off">
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="omf_recaptcha_secret_key">reCAPTCHA v3 シークレットキー</label>
          </th>
          <td>
            <p>
              <input class="regular-text code" type="password" name="omf_recaptcha_secret_key" id="omf_recaptcha_secret_key" spellcheck="false" value="<?php echo esc_attr($recaptcha_secret_key); ?>" autocomplete="new-password">
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="omf_recaptcha_score">しきい値（0.0 - 1.0）</label>
          </th>
          <td>
            <p>
              <input class="small-text" type="number" pattern="\d*" min="0.0" max="1.0" step="0.1" name="omf_recaptcha_score" id="omf_recaptcha_score" value="<?php echo esc_attr($recaptcha_score) ?>">
            </p>
            <p class="description">大きいほど判定が厳しくなる。デフォルトでは、0.5。</p>
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="omf_recaptcha_field_name">reCAPTCHAフィールド名</label>
          </th>
          <td>
            <p>
              <input class="regular-text code" type="text" name="omf_recaptcha_field_name" id="omf_recaptcha_field_name" value="<?php echo esc_attr($recaptcha_field_name); ?>" autocomplete="off">
            </p>
            <p class="description">フォーム内に出力されるinput要素のname属性を設定。デフォルトは「g-recaptcha-response」</p>
          </td>
        </tr>
      </table>
      <?php submit_button(); ?>
    </form>
  </div>
</div>