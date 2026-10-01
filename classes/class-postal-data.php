<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** 公式CSVを少ないメモリで分割し、公開前に全国データを検証する。 */
class OMF_Postal_Data
{
  public static function build(string $zip_file, string $directory): array
  {
    $zip = new \ZipArchive();
    if ($zip->open($zip_file) !== true) { throw new \RuntimeException('ZIPを開けません。'); }
    $stream = false;
    try {
      $entry = $zip->statName('utf_ken_all.csv');
      if (!$entry || $entry['size'] > 50 * MB_IN_BYTES) { throw new \RuntimeException('CSVの形式が不正です。'); }
      $stream = $zip->getStream('utf_ken_all.csv');
      if (!$stream) { throw new \RuntimeException('CSVを読めません。'); }
      $buffers = []; $size = 0; $rows = 0;
      while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if (count($row) !== 15 || !preg_match('/^[0-9]{7}$/D', $row[2]) || $row[6] === '' || $row[7] === '') {
          throw new \RuntimeException('CSVの内容が不正です。');
        }
        $town = $row[8];
        if (str_contains($town, '以下に掲載がない場合') || str_contains($town, 'の次に番地がくる場合') || $town === '一円') { $town = ''; }
        else { $town = explode('（', $town)[0]; }
        $line = json_encode([$row[2], [$row[6], $row[7], $town]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $prefix = substr($row[2], 0, 3);
        $buffers[$prefix] = ($buffers[$prefix] ?? '') . $line; $size += strlen($line); $rows++;
        if ($rows > 200000) { throw new \RuntimeException('CSVの件数が不正です。'); }
        if ($size >= MB_IN_BYTES) { self::flush($buffers, $directory); $size = 0; }
      }
      self::flush($buffers, $directory);
      $count = 0; $files = glob($directory . '/*.part');
      foreach ($files as $file) {
        $entries = []; $input = @fopen($file, 'rb');
        if (!$input) { throw new \RuntimeException('作業データを読めません。'); }
        try {
          while (($line = fgets($input)) !== false) {
            [$code, $address] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!in_array($address, $entries[$code] ?? [], true)) { $entries[$code][] = $address; }
          }
        } finally { fclose($input); }
        $count += count($entries);
        $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents(substr($file, 0, -5) . '.json', $json) !== strlen($json)) { throw new \RuntimeException('データを保存できません。'); }
        @unlink($file);
      }
      if ($count < 100000 || count($files) < 900) { throw new \RuntimeException('全国データが揃っていません。'); }
      return ['postal_codes' => $count, 'files' => count($files)];
    } finally {
      if (is_resource($stream)) { fclose($stream); }
      $zip->close();
    }
  }

  private static function flush(array &$buffers, string $directory): void
  {
    foreach ($buffers as $prefix => $data) {
      if (@file_put_contents($directory . '/' . $prefix . '.part', $data, FILE_APPEND) !== strlen($data)) { throw new \RuntimeException('データを書き込めません。'); }
    }
    $buffers = [];
  }
}
