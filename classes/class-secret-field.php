<?php

namespace Sharesl\Original\MailForm;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * 秘密情報の入力欄。保存済みの値は出力せず、状態と「削除する」だけを表示する。
 */
class OMF_Secret_Field
{
  /**
   * 入力欄・状態表示・削除チェックを出力する。
   *
   * @param string $name   input の name と id
   * @param array  $status OMF_Secrets の *_status() の戻り値
   * @param string $type   input の type（password / text）
   */
  public static function render(string $name, array $status, string $type = 'password'): void
  {
    $status_id = $name . '_status';
    $delete_name = OMF_Secrets::delete_field_name($name);
    $is_unreadable = $status['state'] === OMF_Secrets::STATE_UNREADABLE;
  ?>
    <p id="<?php echo esc_attr($status_id) ?>" class="<?php echo $is_unreadable ? 'omf-status omf-status--ng' : 'description' ?>">
      <?php echo esc_html(self::status_text($status)) ?>
    </p>
    <p>
      <input class="regular-text code widefat" type="<?php echo esc_attr($type) ?>" name="<?php echo esc_attr($name) ?>" id="<?php echo esc_attr($name) ?>" spellcheck="false" value="" placeholder="変更する場合だけ入力" aria-describedby="<?php echo esc_attr($status_id) ?>" autocomplete="<?php echo $type === 'password' ? 'new-password' : 'off' ?>">
    </p>
    <?php if ($status['state'] !== OMF_Secrets::STATE_UNSET) { ?>
      <p>
        <label for="<?php echo esc_attr($delete_name) ?>">
          <input type="checkbox" name="<?php echo esc_attr($delete_name) ?>" id="<?php echo esc_attr($delete_name) ?>" value="1">
          削除する
        </label>
      </p>
    <?php } ?>
  <?php
  }

  /** フォーム編集画面のメタボックス用（ラベル付きの行）。 */
  public static function render_meta_box(string $title, string $name, array $status, string $description = ''): void
  {
  ?>
    <div class="omf-metabox">
      <div class="omf-metabox__item">
        <table>
          <tbody>
            <th>
              <label for="<?php echo esc_attr($name) ?>"><?php echo esc_html($title) ?></label>
            </th>
            <td>
              <?php self::render($name, $status, 'text') ?>
              <?php if ($description !== '') { ?>
                <p class="description"><?php echo wp_kses_post($description) ?></p>
              <?php } ?>
            </td>
          </tbody>
        </table>
      </div>
    </div>
  <?php
  }

  public static function status_text(array $status): string
  {
    return match ($status['state']) {
      OMF_Secrets::STATE_SET => $status['last4'] !== '' ? '設定済み（末尾4文字：' . $status['last4'] . '）' : '設定済み',
      OMF_Secrets::STATE_UNREADABLE => '保存済みの値を読み取れません（サイトの認証用キーが変わった可能性があります）。再入力してください。',
      default => '未設定',
    };
  }
}
