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
  public function encrypt_secret(string $secret, string $name): string
  {
    if (empty($secret)) {
      return '';
    }

    $key = $this->get_encryption_key();
    $iv = $this->get_iv($name);
    $encrypted = openssl_encrypt($secret, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($encrypted);
  }

  /**
   * 復号
   *
   * @param string $encrypted_secret
   * @param string $name
   * @return string
   */
  public function decrypt_secret(string $encrypted_secret, string $name): string
  {
    if (empty($encrypted_secret)) {
      return '';
    }

    $key = $this->get_encryption_key();
    $iv = $this->get_iv($name);
    $decrypted = openssl_decrypt(base64_decode($encrypted_secret), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $decrypted;
  }

  /**
   * IV
   *
   * @param string $name
   * @return string
   */
  public function get_iv(string $name): string
  {
    $iv_name = '_omf_encryption_iv_' . $name;
    $iv = get_option($iv_name);
    if (empty($iv)) {
      $iv = base64_encode(openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC')));
      update_option($iv_name, $iv, 'no');
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
  public function get_encryption_key(): string
  {
    $key = get_option('_omf_encryption_key');
    if (empty($key)) {
      $key = base64_encode(openssl_random_pseudo_bytes(32)); // 256ビットの鍵
      update_option('_omf_encryption_key', $key, 'no');
    } else {
      $key = base64_decode($key);
    }
    return $key;
  }
}
