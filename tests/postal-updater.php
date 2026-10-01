<?php
/** 外部接続なしで公式ZIP変換と更新の切り替え・失敗を確認する。 */
define('ABSPATH', __DIR__); define('MB_IN_BYTES', 1048576); define('DAY_IN_SECONDS', 86400);
class WP_Error { public function __construct(public string $code, public string $message) {} public function get_error_message() { return $this->message; } }
$root = sys_get_temp_dir() . '/omf-postal-test-' . bin2hex(random_bytes(6)); mkdir($root);
$GLOBALS['options'] = []; $GLOBALS['mode'] = 'success';
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function add_option($key, $value, ...$rest) { if (isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key] = $value; return true; }
function update_option($key, $value, ...$rest) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function wp_upload_dir() { return ['basedir' => $GLOBALS['root'], 'baseurl' => 'https://site.test/uploads', 'error' => false]; }
function plugins_url($path, $file) { return 'https://site.test/plugin/' . $path; }
function wp_tempnam($name) { return tempnam($GLOBALS['root'], 'zip'); }
function wp_safe_remote_get($url, $args) {
  if ($GLOBALS['mode'] === 'network') return new WP_Error('network', '通信失敗');
  if ($GLOBALS['mode'] === 'bad') file_put_contents($args['filename'], 'invalid zip');
  else copy($GLOBALS['zip'], $args['filename']);
  return ['code' => 200];
}
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function current_time($format) { return '2026-09-30 12:00:00'; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function wp_mkdir_p($directory) { return $GLOBALS['mode'] === 'write' ? false : mkdir($directory, 0777, true); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function current_user_can($cap) { return $GLOBALS['allowed'] ?? false; }
function wp_die($message, ...$rest) { throw new RuntimeException($message); }
function check_admin_referer($action) { throw new RuntimeException('nonce拒否'); }
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
require __DIR__ . '/../classes/class-postal-data.php';
require __DIR__ . '/../classes/class-postal-updater.php';
use Sharesl\Original\MailForm\OMF_Postal_Updater as Updater;
$GLOBALS['root'] = $root; $GLOBALS['zip'] = $argv[1] ?? '';
try {
  check(is_file($GLOBALS['zip']), '公式ZIPを指定');
  // 同梱版と異なるハッシュとして初回取得の経路を確認する。
  $GLOBALS['options'][Updater::OPTION] = ['generation' => str_repeat('a', 32), 'sha256' => 'old'];
  mkdir($root . '/omf-postal/' . str_repeat('a', 32), 0777, true);
  file_put_contents($root . '/omf-postal/' . str_repeat('a', 32) . '/manifest.json', '{}');
  check(!is_wp_error(Updater::update()), '公式全国CSVを完全に生成して公開');
  $saved = get_option(Updater::OPTION); $base = $root . '/omf-postal/' . $saved['generation'];
  $data = json_decode(file_get_contents($base . '/100.json'), true);
  check($data['1000001'][0] === ['東京都', '千代田区', '千代田'], '生成後の住所データを確認');
  check($saved['postal_codes'] >= 100000 && str_contains(Updater::current()['base_url'], $saved['generation']), '全国件数と描画用参照先の切り替え');
  check(Updater::update() === '確認しました。郵便番号データはすでに最新です。' && get_option(Updater::OPTION) === $saved, '同じデータの再取得では再生成しない');
  $GLOBALS['mode'] = 'network';
  check(is_wp_error(Updater::update()) && get_option(Updater::OPTION) === $saved, '通信失敗時も旧参照先を保持');
  $GLOBALS['mode'] = 'bad';
  check(is_wp_error(Updater::update()) && get_option(Updater::OPTION) === $saved, '不正ZIPで旧参照先を保持');
  check(count(glob($root . '/omf-postal/*', GLOB_ONLYDIR)) === 2 && !get_option('omf_postal_update_lock'), '失敗した生成物とロックを片付ける');
  add_option('omf_postal_update_lock', time());
  check(is_wp_error(Updater::update()), '二重実行を拒否');
  $GLOBALS['allowed'] = false; $_SERVER['REQUEST_METHOD'] = 'POST';
  try { Updater::handle(); throw new LogicException('拒否されていません'); }
  catch (RuntimeException $e) { check($e->getMessage() === '更新する権限がありません。', '管理者権限なしの更新を拒否'); }
  $GLOBALS['allowed'] = true;
  try { Updater::handle(); throw new LogicException('拒否されていません'); }
  catch (RuntimeException $e) { check($e->getMessage() === 'nonce拒否', 'nonce不正の更新を拒否'); }
  $small = $root . '/small.zip'; $archive = new ZipArchive(); $archive->open($small, ZipArchive::CREATE);
  $archive->addFromString('utf_ken_all.csv', '"13101","100","1000001","トウキョウト","チヨダク","チヨダ","東京都","千代田区","千代田","0","0","0","0","0","0"' . "\n"); $archive->close();
  $GLOBALS['zip'] = $small; $GLOBALS['mode'] = 'success'; delete_option('omf_postal_update_lock');
  check(is_wp_error(Updater::update()) && get_option(Updater::OPTION) === $saved, '一部だけのCSVでは参照先を切り替えない');
  $GLOBALS['mode'] = 'write';
  check(is_wp_error(Updater::update()) && get_option(Updater::OPTION) === $saved, '保存先を書き込めない場合も旧データを保持');
} finally {
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($root);
}
