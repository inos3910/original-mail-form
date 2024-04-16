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
      </table>
      <?php submit_button(); ?>
    </form>
  </div>
</div>