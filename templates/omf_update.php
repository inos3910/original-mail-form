<?php
//管理画面 プラグインの更新
?>
<div class="wrap">
  <h1>プラグインの更新</h1>
  <?php
  if (filter_input(INPUT_POST, 'update_omf', FILTER_SANITIZE_NUMBER_INT) === '1') {
    $this->update_plugin_from_github();
  }
  ?>
  <div class="admin_optional">
    <form method="post" action="">
      <p>Github上で管理している最新のmasterブランチのファイルに更新します。</p>
      <p><a href="https://github.com/inos3910/original-mail-form" target="_blank" rel="noopener">GitHubリポジトリはこちら →</a></p>
      <button class="button" type="submit" name="update_omf" value="1">更新開始</button>
    </form>
  </div>
</div>