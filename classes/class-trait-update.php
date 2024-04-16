<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use ZipArchive;

trait OMF_Trait_Update
{
  /**
   * プラグインをmasterブランチに更新
   *
   * @return void
   */
  public function update_plugin_from_github()
  {
    //WP_Filesystem
    require_once(ABSPATH . 'wp-admin/includes/file.php');

    $github_repo_url = 'https://github.com/inos3910/original-mail-form/archive/master.zip';
    $plugin_dir = plugin_dir_path(__FILE__) . '../';

    $response = wp_safe_remote_get($github_repo_url);

    if (is_wp_error($response)) {
      echo '<div class="error"><p>GitHubからファイルを取得する際にエラーが発生しました。</p></div>';
    }

    $zip_content = wp_remote_retrieve_body($response);
    $temp_zip_path = sys_get_temp_dir() . '/github-update.zip';

    if (WP_Filesystem()) {
      global $wp_filesystem;
      $is_saved_tmp = $wp_filesystem->put_contents($temp_zip_path, $zip_content);
      if (!$is_saved_tmp) {
        echo '<div class="error"><p>一時ファイルの保存に失敗しました。</p></div>';
      }
    }

    // ZIPファイルの中身をプラグインディレクトリにコピー
    $zip = new ZipArchive;
    if ($zip->open($temp_zip_path) === true) {
      //除外ファイル
      $excluded_files = ['.gitignore', 'package.json', 'package-lock.json', 'yarn.lock', 'webpack.config.js', 'readme.md'];
      $files_to_extract = [];
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $file_info = $zip->statIndex($i);
        if (!in_array(basename($file_info['name']), $excluded_files)) {
          $files_to_extract[] = $file_info['name'];
        }
      }
      $zip->extractTo($plugin_dir, $files_to_extract);
      $zip->close();
    }

    //一時ファイルを削除
    unlink($temp_zip_path);

    //ファイルの移動
    $target_dir = $plugin_dir . "original-mail-form-master/";
    $this->move_files($target_dir, $plugin_dir);

    // メッセージを表示
    echo '<div class="updated"><p>プラグインが更新されました。</p></div>';
  }

  /**
   * 指定フォルダ内の中身をすべて任意の場所に移動
   *
   * @param string $source 指定フォルダ
   * @param string $destination 任意の場所
   * @return void
   */
  public function move_files(string $source, string $destination)
  {
    if (!is_dir($source)) {
      return;
    }

    if (!is_dir($destination)) {
      mkdir($destination, 0755, true);
    }

    if ($handle = opendir($source)) {
      while (false !== ($entry = readdir($handle))) {
        if ($entry != "." && $entry != "..") {
          $sourcePath = $source . '/' . $entry;
          $destinationPath = $destination . '/' . $entry;

          if (is_dir($sourcePath)) {
            $this->move_files($sourcePath, $destinationPath);
          } else {
            if (file_exists($destinationPath)) {
              // 移動先のファイルが存在する場合は削除
              unlink($destinationPath);
            }
            rename($sourcePath, $destinationPath);
          }
        }
      }

      closedir($handle);
    }

    //ディレクトリ削除
    rmdir($source);
  }
}
