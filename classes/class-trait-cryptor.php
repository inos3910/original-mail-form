<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}


trait OMF_Trait_Cryptor
{
  /**
   * 暗号化
   *
   * @param string $secret
   * @param string $name
   * @return string
   */
  private function encrypt_secret(string $secret, string $name): string
  {
    if (empty($secret)) {
      return '';
    }

    $iv = random_bytes(12);
    $key = hash('sha256', wp_salt('auth'), true);
    $encrypted = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($encrypted === false) { throw new \RuntimeException('トークンの暗号化に失敗しました。'); }
    return 'v2:' . base64_encode($iv . $tag . $encrypted);
  }

  /**
   * 復号
   *
   * @param string $encrypted_secret
   * @param string $name
   * @return string
   */
  private function decrypt_secret(string $encrypted_secret, string $name): string
  {
    if (empty($encrypted_secret)) {
      return '';
    }

    if (str_starts_with($encrypted_secret, 'v2:')) {
      $raw = base64_decode(substr($encrypted_secret, 3), true);
      if ($raw === false || strlen($raw) < 29) { return ''; }
      $value = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
      return is_string($value) ? $value : '';
    }
    $key = $this->get_encryption_key();
    $iv = $this->get_iv($name);
    $decrypted = @openssl_decrypt(base64_decode($encrypted_secret), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if (!is_string($decrypted) || !preg_match('/^[\x21-\x7e]+$/D', $decrypted)) {
      // 旧版の初回保存で使われたbase64文字列の鍵だけを移行する。
      $decrypted = @openssl_decrypt(base64_decode($encrypted_secret), 'AES-256-CBC', base64_encode($key), OPENSSL_RAW_DATA, $iv);
    }
    if (!is_string($decrypted) || !preg_match('/^[\x21-\x7e]+$/D', $decrypted)) { return ''; }
    if ($decrypted !== '' && in_array($name, ['access_token', 'refresh_token'], true)) {
      update_option('_omf_google_' . $name, $this->encrypt_secret($decrypted, $name), false);
    }
    return is_string($decrypted) ? $decrypted : '';
  }

  /**
   * IV
   *
   * @param string $name
   * @return string
   */
  private function get_iv(string $name): string
  {
    $iv_name = '_omf_encryption_iv_' . $name;
    $iv = get_option($iv_name);
    if (empty($iv)) {
      $iv = openssl_random_pseudo_bytes(16);
      update_option($iv_name, base64_encode($iv), 'no');
    } else {
      $iv = base64_decode($iv);
    }
    return $iv;
  }

  /**
   * 暗号化キー
   *
   * @return string
   */
  private function get_encryption_key(): string
  {
    $key = get_option('_omf_encryption_key');
    if (empty($key)) {
      $key = random_bytes(32);
      update_option('_omf_encryption_key', base64_encode($key), false);
    } else {
      $key = base64_decode($key);
    }
    return $key;
  }
}
