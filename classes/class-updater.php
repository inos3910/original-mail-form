<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 公開GitHub ReleaseをWordPress標準の更新画面へ通知する。 */
class OMF_Updater
{
  public function __construct()
  {
    add_filter('update_plugins_github.com', [$this, 'check'], 10, 4);
  }

  public function check($update, array $plugin_data, string $plugin_file, array $locales)
  {
    if ($plugin_file !== plugin_basename(dirname(__DIR__) . '/original-mail-form.php')) { return $update; }
    $release = get_transient('omf_latest_release');
    if ($release === false) {
      $response = wp_remote_get('https://api.github.com/repos/inos3910/original-mail-form/releases/latest', ['timeout' => 10, 'headers' => ['Accept' => 'application/vnd.github+json']]);
      $release = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 ? json_decode(wp_remote_retrieve_body($response), true) : [];
      $release = is_array($release) ? $release : [];
      set_transient('omf_latest_release', $release, $release === [] ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);
    }
    if (!empty($release['draft']) || !empty($release['prerelease'])) { return $update; }
    $version = ltrim((string) ($release['tag_name'] ?? ''), 'v');
    if (!preg_match('/^\d+\.\d+\.\d+$/D', $version) || !version_compare($version, $plugin_data['Version'], '>')) { return $update; }
    // ビルド済みの配布ZIPがないリリースは更新として提供しない。
    foreach ($release['assets'] ?? [] as $asset) {
      $url = $asset['browser_download_url'] ?? '';
      if (($asset['name'] ?? '') === 'original-mail-form.zip' && str_starts_with($url, 'https://github.com/inos3910/original-mail-form/releases/download/')) {
        return ['id' => $plugin_data['UpdateURI'], 'slug' => 'original-mail-form', 'version' => $version, 'url' => 'https://github.com/inos3910/original-mail-form', 'package' => $url];
      }
    }
    return $update;
  }
}
