<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 保存先の選定・検証。参照時にはファイルやオプションを作らない。 */
class OMF_Upload_Directory
{
  const OPTION = 'omf_private_upload_directory';

  public static function public_roots(): array
  {
    $roots = [ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? '', $_SERVER['CONTEXT_DOCUMENT_ROOT'] ?? ''];
    // Herd/Valetは親ディレクトリ全体とリンク先もサイトとして配信する。
    $configs = [dirname(PHP_BINARY, 2) . '/config/valet'];
    if (defined('VALET_HOME_PATH')) { $configs[] = VALET_HOME_PATH; }
    if (!empty($_SERVER['HERD_HOME'])) { $configs[] = $_SERVER['HERD_HOME'] . '/config/valet'; }
    foreach (array_unique($configs) as $config) {
      if (!is_readable($config . '/config.json')) { continue; }
      $settings = json_decode(file_get_contents($config . '/config.json'), true);
      foreach ($settings['paths'] ?? [] as $path) {
        if (!is_string($path)) { continue; }
        $roots[] = $path;
        foreach (glob(rtrim($path, '/\\') . '/*') ?: [] as $site) {
          if (is_link($site) && is_dir($site)) { $roots[] = $site; }
        }
      }
    }
    return array_filter(array_unique(array_merge($roots, (array) apply_filters('omf_additional_public_directories', []))));
  }

  public static function validate(string $path): string
  {
    if ($path === '' || str_contains($path, "\0") || preg_match('~[\r\n]~', $path) || !preg_match('~^(?:/|[A-Za-z]:[/\\\\])~', $path) || preg_match('~(?:^|[/\\\\])\.{1,2}(?:[/\\\\]|$)~', $path)) {
      throw new \RuntimeException('保存先は「.」「..」を含まない絶対パスで指定してください。');
    }
    $path = rtrim($path, '/\\');
    $parent = realpath(dirname($path));
    if ($path === '' || !$parent || !is_dir($parent) || is_link($path) || (file_exists($path) && !is_dir($path))) {
      throw new \RuntimeException('保存先の親フォルダが存在しないか、保存先がフォルダ以外・シンボリックリンクです。');
    }
    $resolved = is_dir($path) ? realpath($path) : $parent . '/' . basename($path);
    foreach (self::public_roots() as $root) {
      $root = realpath($root);
      if ($root && ($resolved === $root || str_starts_with($resolved . '/', rtrim($root, '/\\') . '/'))) {
        throw new \RuntimeException('公開領域内の保存先は使用できません。サイトの配信対象外の場所を指定してください。');
      }
    }
    return $resolved;
  }

  private static function new_candidates(): array
  {
    $site = sanitize_file_name(basename(rtrim(ABSPATH, '/\\')));
    $name = '/original-mail-form-private-attachments-' . $site . '-' . substr(hash('sha256', ABSPATH), 0, 16);
    return array_unique(array_map(static fn($dir) => rtrim($dir, '/\\') . $name, array_filter([dirname(rtrim(ABSPATH, '/\\')), get_temp_dir(), ini_get('upload_tmp_dir'), sys_get_temp_dir()])));
  }

  public static function suggested(): string
  {
    foreach (self::new_candidates() as $path) {
      try {
        $path = self::validate($path);
        if (is_writable(is_dir($path) ? $path : dirname($path))) { return $path; }
      } catch (\RuntimeException $e) { continue; }
    }
    throw new \RuntimeException('公開領域外の保存先を選べません。詳細設定で保存先を指定してください。');
  }

  public static function selected(): string
  {
    if (defined('OMF_PRIVATE_UPLOAD_DIR')) { return (string) OMF_PRIVATE_UPLOAD_DIR; }
    $saved = get_option(self::OPTION, '');
    if (is_string($saved) && $saved !== '') { return $saved; }
    $name = '/omf-' . substr(hash('sha256', ABSPATH), 0, 16);
    $old = array_map(static fn($dir) => rtrim($dir, '/\\') . $name, array_filter([get_temp_dir(), ini_get('upload_tmp_dir'), sys_get_temp_dir(), dirname(rtrim(ABSPATH, '/\\'))]));
    // オプション導入前の添付を優先。危険な保存先でも黙って別の場所へ切り替えない。
    foreach (array_unique($old) as $path) {
      if (is_dir($path) && (glob($path . '/*', GLOB_ONLYDIR) ?: []) !== []) { return $path; }
    }
    return self::suggested();
  }

  public static function inspect(): array
  {
    $path = '';
    try {
      $path = self::selected();
      $path = self::validate($path);
      return ['path' => $path, 'exists' => is_dir($path), 'error' => ''];
    } catch (\RuntimeException $e) {
      return ['path' => $path, 'exists' => is_dir($path), 'error' => $e->getMessage()];
    }
  }

  public static function resolve(bool $create = false): string
  {
    $base = self::validate(self::selected());
    if (!$create) { return is_dir($base) ? $base : ''; }
    if ((!is_dir($base) && !mkdir($base, 0700)) || !is_writable($base)) {
      throw new \RuntimeException('添付保存先を作成できません。書き込み権限を確認してください。');
    }
    if (!chmod($base, 0700)) { throw new \RuntimeException('添付保存先の権限を制限できません。'); }
    self::protect($base);
    if (!defined('OMF_PRIVATE_UPLOAD_DIR')) {
      update_option(self::OPTION, $base, false);
      if (get_option(self::OPTION) !== $base) { throw new \RuntimeException('添付保存先の設定を保存できません。'); }
    }
    return $base;
  }

  private static function protect(string $base): void
  {
    $files = [
      '.htaccess' => "# Original Mail Form: 添付ファイルへの直接アクセスを禁止します。\nRequire all denied\n",
      'README.txt' => "Original Mail Form の非公開添付ファイル保存先です。\nサイト識別子: " . substr(hash('sha256', ABSPATH), 0, 16) . "\n一時添付・配送待ち・保存設定により保持する添付を管理します。\n管理画面の「メールフォーム → 設定 → 一般 → 添付ファイルの保存先」で確認できます。\n使用中のファイルを移動・削除しないでください。\nWeb配信対象外に配置してください。.htaccess はApache向けの追加対策です。Nginxでは別途サーバー設定が必要です。\n",
    ];
    foreach ($files as $name => $content) {
      $path = $base . '/' . $name;
      if (is_link($path) || (file_exists($path) && (!is_file($path) || file_get_contents($path) !== $content))) {
        throw new \RuntimeException('添付保存先の保護・説明ファイルが競合しています。管理者が内容を確認してください。');
      }
      if (!file_exists($path) && file_put_contents($path, $content, LOCK_EX) === false) {
        throw new \RuntimeException('添付保存先の保護・説明ファイルを作成できません。');
      }
      if (!chmod($path, 0600)) { throw new \RuntimeException('添付保存先の保護ファイルの権限を制限できません。'); }
    }
  }
}
