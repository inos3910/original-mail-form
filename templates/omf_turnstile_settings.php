<?php
//管理画面 Cloudflare Turnstile設定
?>
<div class="wrap">
  <h1>Cloudflare Turnstile設定</h1>
  <div class="admin_optional">
    <form method="post" action="options.php" autocomplete="off">
      <?php
      settings_fields('turnstile-settings-group');
      do_settings_sections('turnstile-settings-group');
      // settings_errors();

      $turnstile_site_key = !empty(get_option('omf_turnstile_site_key')) ? sanitize_text_field(wp_unslash(get_option('omf_turnstile_site_key'))) : '';
      $turnstile_secret_key = !empty(get_option('omf_turnstile_secret_key')) ? sanitize_text_field(wp_unslash(get_option('omf_turnstile_secret_key'))) : '';
      ?>
      <table class="form-table">
        <tr>
          <th scope="row">
            <label for="omf_turnstile_site_key">Cloudflare Turnstile サイトキー</label>
          </th>
          <td>
            <p>
              <input class="regular-text code" type="text" name="omf_turnstile_site_key" id="omf_turnstile_site_key" spellcheck="false" value="<?php echo esc_attr($turnstile_site_key); ?>" autocomplete="off">
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="omf_turnstile_secret_key">Cloudflare Turnstile シークレットキー</label>
          </th>
          <td>
            <p>
              <input class="regular-text code" type="password" name="omf_turnstile_secret_key" id="omf_turnstile_secret_key" spellcheck="false" value="<?php echo esc_attr($turnstile_secret_key); ?>" autocomplete="new-password">
            </p>
          </td>
        </tr>
      </table>
      <?php submit_button(); ?>
    </form>
  </div>
</div>