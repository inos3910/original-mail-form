<?php
namespace {
  // サーバー・DBなしの境界テスト。実ファイルは指定したHerd配下だけに作る。
  $root = $argv[1] ?? '';
  $herd = realpath('/Users/Yosuke/Herd');
  if (!$herd || dirname($root) !== $herd || basename($root) !== 'omf-private-storage-check' || file_exists($root)) {
    throw new \RuntimeException('未作成の /Users/Yosuke/Herd/omf-private-storage-check を指定してください。');
  }
  mkdir($root, 0700);
  mkdir($root . '/public', 0700);
  mkdir($root . '/private', 0700);
  mkdir($root . '/temporary', 0700);
  define('ABSPATH', $root . '/public/');
  define('HOUR_IN_SECONDS', 3600);
  $GLOBALS['root'] = $root;
  $GLOBALS['options'] = [];
  $GLOBALS['fake_roots'] = true;
  $GLOBALS['extra_roots'] = [];
  $GLOBALS['can_manage'] = true;
  $GLOBALS['valid_nonce'] = true;
  $GLOBALS['checks'] = 0;
  class WP_Error { public function __construct(public $code, public $message) {} public function get_error_message() { return $this->message; } }
  function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
  function update_option($key, $value, ...$args) { if (!empty($GLOBALS['fail_update'])) { return false; } $GLOBALS['options'][$key] = $value; return true; }
  function add_option($key, $value, ...$args) { if (isset($GLOBALS['options'][$key])) { return false; } $GLOBALS['options'][$key] = $value; return true; }
  function wp_cache_delete(...$args) {}
  function apply_filters($hook, $value, ...$args) { return $hook === 'omf_additional_public_directories' ? $GLOBALS['extra_roots'] : $value; }
  function get_temp_dir() { return $GLOBALS['root'] . '/temporary/'; }
  function sanitize_file_name($value) { return basename($value); }
  function is_wp_error($value) { return $value instanceof WP_Error; }
  function current_user_can($capability) { return $GLOBALS['can_manage']; }
  function wp_die($message, ...$args) { throw new RuntimeException($message); }
  function check_admin_referer($action) { if (!$GLOBALS['valid_nonce']) { throw new RuntimeException('nonce拒否'); } }
  function check($ok, $label) { if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } $GLOBALS['checks']++; echo 'PASS: ' . $label . PHP_EOL; }
  function rejected(callable $operation) { try { $operation(); return false; } catch (RuntimeException $e) { return true; } }
  $GLOBALS['wpdb'] = new class {
    public $options = 'test_options';
    public function prepare($sql, $key, $value) { return [$key, $value]; }
    public function query($query) { [$key, $value] = $query; if (get_option($key) === $value) { unset($GLOBALS['options'][$key]); } }
  };
}
namespace Sharesl\Original\MailForm {
  // 公開領域の境界だけを模擬し、テスト用添付もHerd配下に閉じ込める。
  function file_get_contents($path, ...$args) {
    if ($GLOBALS['fake_roots'] && str_ends_with($path, '/config/valet/config.json')) { return json_encode(['paths' => [ABSPATH]]); }
    return \file_get_contents($path, ...$args);
  }
  function sys_get_temp_dir() { return $GLOBALS['root'] . '/temporary'; }
  class OMF_Legacy_Uploads {
    public static function retained($id) { return $id === ($GLOBALS['retained'] ?? ''); }
    public static function discard($id) {}
  }
}
namespace {
  use Sharesl\Original\MailForm\OMF_Upload_Directory as Directory;
  use Sharesl\Original\MailForm\OMF_Upload_Lock as Lock;
  use Sharesl\Original\MailForm\OMF_Upload_Settings as Settings;
  use Sharesl\Original\MailForm\OMF_Uploads as Uploads;
  foreach (['upload-directory', 'upload-lock', 'upload-settings', 'uploads'] as $file) { require dirname(__DIR__) . '/classes/class-' . $file . '.php'; }
  try {
    $planned = Directory::inspect();
    check(!$planned['exists'] && $planned['error'] === '' && str_contains($planned['path'], 'original-mail-form-private-attachments-public-'), '未作成の保存先を用途が分かる名前で案内');
    $before = $GLOBALS['options'];
    check(Uploads::path(str_repeat('a', 40)) === '', '未作成の添付参照は空文字');
    Uploads::cleanup();
    check(!file_exists($planned['path']) && $GLOBALS['options'] === $before, '状態表示・参照・掃除でフォルダもオプションも作らない');
    foreach ([ABSPATH . 'uploads', $root . '/public', $root . '/public/../private/x', 'relative/path', $root . '/missing/child', '/', $root . "/bad\npath"] as $bad) {
      check(rejected(fn() => Directory::validate($bad)), '公開領域・相対パス・不正パスを拒否: ' . json_encode($bad));
    }
    symlink(ABSPATH, $root . '/public-alias');
    check(rejected(fn() => Directory::validate($root . '/public-alias/secret')), '親シンボリックリンク経由の公開領域を拒否');
    symlink($root . '/private', $root . '/private-alias');
    check(rejected(fn() => Directory::validate($root . '/private-alias')), '保存先自体のシンボリックリンクを拒否');
    $GLOBALS['extra_roots'] = [$root . '/private'];
    check(rejected(fn() => Directory::validate($root . '/private/secret')), '別サイト・別名公開先を追加して拒否');
    $GLOBALS['extra_roots'] = [];
    $GLOBALS['fake_roots'] = false;
    check(rejected(fn() => Directory::validate($root . '/private/secret')), '実際のHerd公開対象配下を拒否');
    $GLOBALS['fake_roots'] = true;
    $target = $root . '/private/attachments';
    check(!is_wp_error(Settings::save($target)) && !file_exists($target), '設定保存だけではフォルダを作らない');
    check(is_wp_error(Settings::save(ABSPATH . 'uploads')) && get_option(Directory::OPTION) === $target, '危険な設定を拒否し以前の値を保持');
    $GLOBALS['can_manage'] = false;
    check(rejected(fn() => Settings::handle()), '管理者権限なしの設定操作を拒否');
    $GLOBALS['can_manage'] = true;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    check(rejected(fn() => Settings::handle()), 'GETによる設定操作を拒否');
    $_SERVER['REQUEST_METHOD'] = 'POST'; $GLOBALS['valid_nonce'] = false;
    check(rejected(fn() => Settings::handle()), 'nonceなしの設定操作を拒否');
    $GLOBALS['valid_nonce'] = true;
    file_put_contents($root . '/sample.txt', 'テスト添付');
    $id = Uploads::import($root . '/sample.txt', 'sample.txt');
    check(file_get_contents(Uploads::path($id)) === 'テスト添付', '初回保存で作成して添付を参照');
    check(is_file($target . '/README.txt') && file_get_contents($target . '/.htaccess') === "# Original Mail Form: 添付ファイルへの直接アクセスを禁止します。\nRequire all denied\n", '用途説明とApacheアクセス拒否を作成');
    check((fileperms($target) & 0777) === 0700 && (fileperms(Uploads::path($id)) & 0777) === 0600, '保存先0700・添付0600に制限');
    check(is_wp_error(Settings::save($root . '/private/next')) && get_option(Directory::OPTION) === $target, '添付がある間は保存先を切り替えない');
    $GLOBALS['retained'] = $id;
    touch(dirname(Uploads::path($id)), time() - 7200); Uploads::cleanup();
    check(Uploads::path($id) !== '', '保存対象の既存添付を掃除で消さない');
    $GLOBALS['retained'] = ''; Uploads::cleanup();
    check(Uploads::path($id) === '' && is_dir($target), '古い添付を掃除して用途説明のある親を維持');
    check(!is_wp_error(Settings::save($root . '/private/next')) && is_dir($target) && !is_dir($root . '/private/next'), '空の保存先だけ切り替え、旧フォルダは自動削除しない');
    $legacy = $root . '/temporary/omf-' . substr(hash('sha256', ABSPATH), 0, 16);
    mkdir($legacy); mkdir($legacy . '/' . str_repeat('b', 40));
    file_put_contents($legacy . '/' . str_repeat('b', 40) . '/old.txt', '既存添付');
    unset($GLOBALS['options'][Directory::OPTION]);
    check(Uploads::path(str_repeat('b', 40)) === $legacy . '/' . str_repeat('b', 40) . '/old.txt' && !is_file($legacy . '/README.txt'), 'オプション導入前の添付を移動せず読み、参照時には説明ファイルも作らない');
    check(is_wp_error(Settings::save($target)), '旧方式の添付がある場合も切り替えを拒否');
    Uploads::remove(str_repeat('b', 40));
    check(!is_wp_error(Settings::save($target)), '旧方式の添付がなくなれば切り替え可能');
    file_put_contents($target . '/.htaccess', '既存独自設定');
    check(rejected(fn() => Uploads::import($root . '/sample.txt', 'sample.txt')) && file_get_contents($target . '/.htaccess') === '既存独自設定', '保護ファイルの競合は上書きせず保存を拒否');
    check(!get_option(Lock::OPTION), '保存失敗後もロックを解放');
    file_put_contents($target . '/.htaccess', "# Original Mail Form: 添付ファイルへの直接アクセスを禁止します。\nRequire all denied\n");
    $GLOBALS['options'][Lock::OPTION] = (time() + 300) . ':別処理';
    check(is_wp_error(Settings::save($target)) && rejected(fn() => Uploads::import($root . '/sample.txt', 'sample.txt')), '設定変更と添付保存の競合を拒否');
    $GLOBALS['options'][Lock::OPTION] = (time() - 1) . ':期限切れ';
    check(!is_wp_error(Settings::save($target)), '異常終了した期限切れロックを回復');
    $GLOBALS['fail_update'] = true;
    check(is_wp_error(Settings::save($root . '/private/new')), '設定保存失敗を成功扱いしない');
    $GLOBALS['fail_update'] = false;
    define('OMF_PRIVATE_UPLOAD_DIR', $target);
    check(is_wp_error(Settings::save($root . '/private/new')) && Directory::selected() === $target, '定数指定を優先し管理画面での変更を拒否');
    echo '非公開添付保存先テスト完了: ' . $GLOBALS['checks'] . "項目\n";
  } finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
  }
}
