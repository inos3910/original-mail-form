<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

trait OMF_Trait_Update
{
  /**
   * プラグインをmasterブランチに更新
   *
   * @return void
   */
  private function update_plugin_from_github()
  {
    //WP_Filesystem
    require_once(ABSPATH . 'wp-admin/includes/file.php');

    $github_repo_url = 'https://github.com/inos3910/original-mail-form/archive/master.zip';
    $plugin_dir = plugin_dir_path(__DIR__);

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

    $target_dir = $plugin_dir . "original-mail-form-master/";
    // ファイル差分を取得し、削除されるべきファイルを削除
    $files_to_delete = $this->get_file_diff($plugin_dir, $target_dir);
    foreach ($files_to_delete as $file) {
      if (file_exists($file)) {
        unlink($file);
      }
    }
    //ファイルの移動
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
  private function move_files(string $source, string $destination)
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

  /**
   * 2つのディレクトリ間のファイル差分を取得
   *
   * @param string $source_dir 元のディレクトリ
   * @param string $target_dir 比較対象のディレクトリ
   * @return array 削除されるべきファイルのパス
   */
  private function get_file_diff(string $source_dir, string $target_dir): array
  {
    $files_to_delete = [];

    // 元のディレクトリ内のファイルを取得
    $source_files = $this->get_files_recursive($source_dir);

    // 比較対象のディレクトリ内のファイルを取得
    $target_files = $this->get_files_recursive($target_dir);

    // 元のディレクトリにあるが、比較対象のディレクトリにないファイルを取得
    $diff = array_diff($source_files, $target_files);

    foreach ($diff as $file) {
      $files_to_delete[] = $file;
    }

    return $files_to_delete;
  }

  /**
   * ディレクトリ内のファイルを再帰的に取得する
   *
   * @param string $dir ディレクトリパス
   * @return array ファイルのパスの配列
   */
  private function get_files_recursive(string $dir): array
  {
    $files = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($items as $item) {
      if ($item->isFile()) {
        $files[] = $item->getPathname();
      }
    }

    return $files;
  }
}
