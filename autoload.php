<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

use ReflectionClass;

class Autoload
{
  /**
   * インスタンス化したClassを入れる配列
   *
   * @var array
   */
  private array $instances = [];

  public function __construct()
  {
    $is_init = $this->init();
    if ($is_init) {
      $this->create_instances();
    }
  }

  /**
   * オートロード
   *
   * @return boolean
   */
  private function init(): bool
  {
    return spl_autoload_register(function ($class_name) {
      $prefix = __NAMESPACE__ . "\OMF_";
      $prefix_length = strlen($prefix);
      $class_name_without_prefix = substr($class_name, $prefix_length);
      $is_class = substr($class_name, 0, $prefix_length) == $prefix;
      if (!$is_class) {
        return;
      }

      $file_path = plugin_dir_path(__FILE__) . '/classes/class-' . str_replace('_', '-', strtolower($class_name_without_prefix)) . '.php';

      if (file_exists($file_path)) {
        require_once $file_path;
      }
    });
  }

  /**
   * インスタンス化
   *
   * @return void
   */
  private function create_instances(): void
  {
    foreach (glob(plugin_dir_path(__FILE__) . 'classes/class-*.php') as $file_path) {
      $key = $this->get_class_file_key_name($file_path);
      if (empty($key)) {
        continue;
      }

      $class_name = $this->get_class_name($file_path);
      if (empty($class_name)) {
        continue;
      }

      //クラスが存在する場合はインスタンス化して変数に保存
      $reflector = class_exists($class_name) ? new ReflectionClass($class_name) : null;
      if (!empty($reflector) && !$reflector->isTrait() && !$reflector->isAbstract()) {
        $this->instances[$key] = $reflector->newInstance();
      }
    }
  }

  /**
   * Classファイルのパスからキーを取得
   *
   * @param string $file_path
   * @return string|null
   */
  private function get_class_file_key_name(string $file_path): string|null
  {
    $basename  = basename($file_path, '.php');
    $regex = '/^class\-(.+)$/';
    $is_key = preg_match($regex, $basename, $matches);
    if (!$is_key) {
      return null;
    }

    return $matches[1];
  }

  /**
   * ClassファイルのパスからClass名を取得
   *
   * @param string $file_path
   * @return string
   */
  private function get_class_name(string $file_path): string
  {
    $basename  = basename($file_path, '.php');
    $class_name = preg_replace('/^class\-(.+)$/', __NAMESPACE__ . '\OMF_$1', $basename);
    $class_name = str_replace('-', '_', $class_name);
    $class_name = ucwords($class_name, "_");
    return $class_name;
  }

  /**
   * インスタンス化したクラスを配列で取得
   *
   * @return array
   */
  public function get_instances(): array
  {
    return $this->instances;
  }
}

$autoload = new Autoload();
return $autoload->get_instances();
