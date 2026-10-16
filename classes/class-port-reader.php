<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * エクスポートファイル（JSON）を検証して、取り込み用の配列にする。
 * 許可外のキーは捨てて警告にする。unserialize は使わない。
 */
class OMF_Port_Reader
{
  /**
   * JSON文字列を検証して返す。ファイル全体が受け付けられない時は WP_Error。
   *
   * @param string $json
   * @return array|\WP_Error
   */
  public static function read(string $json): array|\WP_Error
  {
    if (strlen($json) > OMF_Port_Schema::MAX_BYTES) {
      return new \WP_Error('omf_port_size', 'ファイルが大きすぎます（上限2MB）。');
    }
    $data = json_decode($json, true, 32);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
      return new \WP_Error('omf_port_json', 'JSONとして読み込めません。OMFで書き出したファイルを選んでください。');
    }
    if (($data['format'] ?? null) !== OMF_Port_Schema::FORMAT) {
      return new \WP_Error('omf_port_format', 'OMFのエクスポートファイルではありません。');
    }
    if (($data['version'] ?? null) !== OMF_Port_Schema::VERSION) {
      return new \WP_Error('omf_port_version', 'このファイルの形式バージョンには対応していません。');
    }

    $warnings = [];
    $payload = [
      'source' => [
        'url' => is_string($data['source_url'] ?? null) ? sanitize_text_field($data['source_url']) : '',
        'plugin_version' => is_string($data['plugin_version'] ?? null) ? sanitize_text_field($data['plugin_version']) : '',
        'exported_at' => is_string($data['exported_at'] ?? null) ? sanitize_text_field($data['exported_at']) : '',
      ],
      'settings' => null,
      'forms' => null,
      'warnings' => [],
    ];
    if (array_key_exists('settings', $data)) {
      $payload['settings'] = self::settings($data['settings'], $warnings);
    }
    if (array_key_exists('forms', $data)) {
      $payload['forms'] = OMF_Port_Form_Reader::read_all($data['forms'], $warnings);
    }
    $payload['warnings'] = $warnings;
    return $payload;
  }

  /**
   * 設定を許可リストで絞り、値を検証する。秘密情報（認証キー）は取り込まず、警告にする。
   *
   * @param mixed $raw
   * @param array $warnings
   * @return array
   */
  private static function settings(mixed $raw, array &$warnings): array
  {
    if (!is_array($raw) || ($raw !== [] && OMF_Utils::is_list($raw))) {
      $warnings[] = '設定の形式が不正なため、設定は取り込みません。';
      return [];
    }
    $accepted = [];
    foreach ($raw as $key => $value) {
      if (in_array($key, OMF_Port_Schema::secret_options(), true)) {
        $warnings[] = '秘密情報の設定「' . $key . '」は取り込まず、取り込み先の値を残しました。';
        continue;
      }
      if (!in_array($key, OMF_Port_Schema::allowed_options(), true)) {
        $warnings[] = '未対応の設定「' . sanitize_text_field((string) $key) . '」は無視しました。';
        continue;
      }
      $clean = self::option_value($key, $value);
      if ($clean === null) {
        $warnings[] = '設定「' . $key . '」の値が不正なため無視しました。';
        continue;
      }
      $accepted[$key] = $clean;
    }
    return $accepted;
  }

  /**
   * オプション値の検証。不正なら null。
   *
   * @param string $key
   * @param mixed $value
   * @return string|null
   */
  private static function option_value(string $key, mixed $value): ?string
  {
    if (!is_scalar($value)) {
      return null;
    }
    $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    return match ($key) {
      'omf_is_rest_api' => in_array($text, ['0', '1'], true) ? $text : null,
      'omf_retention_days' => preg_match('/^\d{1,5}$/D', $text) ? $text : null,
      'omf_recaptcha_score' => is_numeric($text) && (float) $text >= 0 && (float) $text <= 1 ? $text : null,
      'omf_recaptcha_field_name' => preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $text) ? $text : null,
      default => null,
    };
  }
}
