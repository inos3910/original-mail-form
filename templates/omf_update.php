<?php if (!defined('ABSPATH')) { exit; } ?>
  <?php if (($_POST['update_omf'] ?? '') === '1') { $this->update_plugin_from_github(); } ?>
  <form method="post">
    <?php wp_nonce_field('omf_update_plugin'); ?>
    <p>GitHub上で管理している最新のmasterブランチのファイルに更新します。</p>
    <p><a href="https://github.com/inos3910/original-mail-form" target="_blank" rel="noopener">GitHubリポジトリはこちら →</a></p>
    <button class="button" type="submit" name="update_omf" value="1">更新開始</button>
  </form>
