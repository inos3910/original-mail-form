<?php
//管理画面 送信データCSV出力
$all_forms   = $this->get_forms();
$data_forms  = [];
foreach ((array)$all_forms as $form) {
  $is_save_db = get_post_meta($form->ID, 'cf_omf_save_db', true);
  if (empty($is_save_db)) {
    continue;
  }
  $data_forms[] = $form;
}

//送信データのID
$omf_data_id = filter_input(INPUT_GET, 'omf_data_id');
if (empty($omf_data_id) && !empty($data_forms)) {
  $omf_data_id = $this->get_data_post_type_by_id($data_forms[0]->ID);
}

$today        = wp_date('Y-m-d');
$yesterday    = wp_date('Y-m-d', strtotime('-1 day'));
$one_week     = wp_date('Y-m-d', strtotime('-1 week'));
$one_month    = wp_date('Y-m-d', strtotime('-1 month'));
$three_months = wp_date('Y-m-d', strtotime('-3 months'));
$six_months   = wp_date('Y-m-d', strtotime('-6 months'));
$one_year     = wp_date('Y-m-d', strtotime('-1 year'));

// タイムゾーンを取得
$timezone        = wp_timezone();
// 日本語の曜日リスト
$week_name       = ["日", "月", "火", "水", "木", "金", "土"];
$today_ja        = wp_date("Y/m/d (" . $week_name[wp_date("w", strtotime('now'), $timezone)] . ")", strtotime('now'), $timezone);
$yesterday_ja    = wp_date("Y/m/d (" . $week_name[wp_date("w", strtotime('-1 day'), $timezone)] . ")", strtotime('-1 day'), $timezone);
$one_week_ja     = wp_date("Y/m/d (" . $week_name[wp_date("w", strtotime('-1 week'), $timezone)] . ")", strtotime('-1 week'), $timezone);
$one_month_ja    = wp_date("Y/m/d (" . $week_name[wp_date("w", strtotime('-1 month'), $timezone)] . ")", strtotime('-1 month'), $timezone);
$three_months_ja = wp_date("Y/m/d (" . $week_name[wp_date("w", strtotime('-3 months'), $timezone)] . ")", strtotime('-3 months'), $timezone);
$six_months_ja   = wp_date("Y/m/d (" . $week_name[wp_date("w", strtotime('-6 months'), $timezone)] . ")", strtotime('-6 months'), $timezone);
$one_year_ja     = wp_date("Y/m/d (" . $week_name[wp_date("w", strtotime('-1 year'), $timezone)] . ")", strtotime('-1 year'), $timezone);
?>
<div class="wrap">
  <h1>送信データCSV出力</h1>
  <p>データベースに保存したフォームの送信データをCSV出力します。</p>
  <div class="admin_optional">
    <?php
    if (!empty($data_forms)):
    ?>
      <form method="post" action="" autocomplete="off">
        <?php wp_nonce_field('omf_output_csv_action', 'omf_output_csv_nonce');
        ?>
        <table class="form-table">
          <tr>
            <th scope="row">フォームを選択</th>
            <td>
              <select name="omf_data_id">
                <?php
                foreach ((array)$data_forms as $form) {
                  $data_post_type = $this->get_data_post_type_by_id($form->ID);
                ?>
                  <option value="<?php echo esc_attr($data_post_type) ?>" <?php if ($omf_data_id === $data_post_type) echo esc_attr('selected') ?>><?php echo esc_html(get_the_title($form->ID)) ?></option>
                <?php
                }
                ?>
              </select>
            </td>
          </tr>
          <tr>
            <th>
              <span>出力データを選択</span>
            </th>
            <td>
              <fieldset>
                <legend class="screen-reader-text">
                  <span>出力データを選択</span>
                </legend>
                <?php
                foreach ((array)$data_forms as $form) {
                  $data_post_type = $this->get_data_post_type_by_id($form->ID);
                ?>
                  <span data-omf-data-id="<?php echo esc_attr($data_post_type) ?>" <?php if ($omf_data_id !== $data_post_type) echo esc_attr('inert hidden'); ?>>
                    <div class="omf-data-patterns">
                      <button class="button" type="button" data-omf-all-check>すべてを選択</button>
                      <button class="button" type="button" data-omf-all-uncheck>すべての選択を外す</button>
                      <?php
                      //出力データ選択パターンを取得
                      $current_patterns = apply_filters('omf_output_data_patterns_' . $form->post_name, []);
                      if (!empty($current_patterns)) {
                        foreach ((array)$current_patterns as $key => $value) {
                      ?>
                          <button class="button" type="button" data-omf-pattern="<?php echo esc_attr($key) ?>">パターン<?php echo esc_html($key + 1) ?>を選択</button>
                      <?php
                        }
                      }
                      ?>
                    </div>
                    <!-- /.omf-data-patterns -->
                    <?php
                    $keys = $this->get_all_saved_data_keys($data_post_type);
                    foreach ($keys as $key => $key_named) {
                    ?>
                      <label>
                        <input type="checkbox" name="omf_data_key[]" value="<?php echo esc_attr($key) ?>" checked>
                        <span class="omf_key"><?php echo esc_html($key_named) ?></span>
                      </label>
                      <br>
                    <?php
                    }
                    ?>
                  </span>
                <?php } ?>
              </fieldset>
            </td>
          </tr>
          <tr>
            <th scope="row">期間指定</th>
            <td>
              <fieldset>
                <legend class="screen-reader-text">
                  <span>期間指定</span>
                </legend>
                <label>
                  <input type="radio" name="omf_output_period" value="<?php echo esc_attr($today) ?>" checked>
                  <span class="omf_date">今日</span>
                  <code><?php echo esc_html($today_ja) ?></code>
                </label>
                <br>
                <label>
                  <input type="radio" name="omf_output_period" value="<?php echo esc_attr($yesterday) ?>">
                  <span class="omf_date">昨日</span>
                  <code><?php echo esc_html("{$yesterday_ja} 〜 {$today_ja}") ?></code>
                </label>
                <br>
                <label>
                  <input type="radio" name="omf_output_period" value="<?php echo esc_attr($one_week) ?>">
                  <span class="omf_date">1週間</span>
                  <code><?php echo esc_html("{$one_week_ja} 〜 {$today_ja}") ?></code>
                </label>
                <br>
                <label>
                  <input type="radio" name="omf_output_period" value="<?php echo esc_attr($one_month) ?>">
                  <span class="omf_date">1ヶ月</span>
                  <code><?php echo esc_html("{$one_month_ja} 〜 {$today_ja}") ?></code>
                </label>
                <br>
                <label>
                  <input type="radio" name="omf_output_period" value="<?php echo esc_attr($three_months) ?>">
                  <span class="omf_date">3か月</span>
                  <code><?php echo esc_html("{$three_months_ja} 〜 {$today_ja}") ?></code>
                </label>
                <br>
                <label>
                  <input type="radio" name="omf_output_period" value="<?php echo esc_attr($six_months) ?>">
                  <span class="omf_date">6ヶ月</span>
                  <code><?php echo esc_html("{$six_months_ja} 〜 {$today_ja}") ?></code>
                </label>
                <br>
                <label>
                  <input type="radio" name="omf_output_period" value="<?php echo esc_attr($one_year) ?>">
                  <span class="omf_date">1年</span>
                  <code><?php echo esc_html("{$one_year_ja} 〜 {$today_ja}") ?></code>
                </label>
                <br>
                <label>
                  <input type="radio" name="omf_output_period" value="option">
                  <span class="omf_date">任意で入力</span>
                </label>
              </fieldset>
            </td>
          </tr>
          <tr id="js-omf-data-date-option" hidden inert>
            <th scope="row">任意入力</th>
            <td>
              <fieldset>
                <legend class="screen-reader-text">
                  <span>任意入力</span>
                </legend>
                <label>
                  <input type="date" name="omf_output_start" value="" max="<?php echo esc_attr($today) ?>">
                </label>
                <br>
                <label>
                  <input type="date" name="omf_output_end" value="" max="<?php echo esc_attr($today) ?>">
                </label>
              </fieldset>
            </td>
          </tr>
        </table>
        <?php submit_button('CSVをダウンロード', 'primary', 'output_csv', true); ?>
      </form>
    <?php
    else:
    ?>
      <p>データベース保存設定されているフォームがまだありません。</p>
    <?php
    endif;
    ?>
  </div>
</div>
<style>
  .original_mail_forms_page_omf_output_data .omf_date {
    display: inline-block;
    min-width: 6em;
  }

  .original_mail_forms_page_omf_output_data .omf-data-patterns {
    display: flex;
    gap: 1em 0.5em;
    margin-bottom: 1em;
  }

  .original_mail_forms_page_omf_output_data [data-omf-data-id][hidden] {
    display: none;
  }

  .original_mail_forms_page_omf_output_data .active[data-omf-data-id] {
    display: block;
  }
</style>
<script>
  (function() {
    //submitボタン
    const submitButton = document.querySelector('#output_csv');

    //期間指定の変更（任意入力対応）
    const switchPeriod = () => {
      const selectOptions = document.querySelectorAll('input[name="omf_output_period"]');
      const optionElem = document.querySelector('#js-omf-data-date-option');
      if (!selectOptions.length || !optionElem) {
        return;
      }

      for (const option of selectOptions) {
        option.addEventListener('change', (e) => {
          if (document.querySelector('input[name="omf_output_period"]:checked')?.value === "option") {
            optionElem.removeAttribute('hidden');
            optionElem.removeAttribute('inert');
          } else {

            optionElem.setAttribute('hidden', '');
            optionElem.setAttribute('inert', '');
          }
        });
      }
    };

    //出力データ対応（タブ化）
    const selectForm = () => {
      const formSelectElem = document.querySelector('[name="omf_data_id"]');
      const tabContents = document.querySelectorAll('[data-omf-data-id]');
      if (!formSelectElem || !tabContents.length) {
        return;
      }

      formSelectElem.addEventListener('change', (e) => {
        const showId = e.currentTarget.value;

        for (const t of tabContents) {
          if (t.dataset.omfDataId === showId) {
            t.removeAttribute('hidden');
            t.removeAttribute('inert');
            t.classList.add('active');
            const keys = t.querySelectorAll('input[name="omf_data_key[]"]');
            if (keys.length) {
              for (const key of keys) {
                key.disabled = false;
              }
            }
          } else {
            t.setAttribute('hidden', '');
            t.setAttribute('inert', '');
            t.classList.remove('active');
            const keys = t.querySelectorAll('input[name="omf_data_key[]"]');
            if (keys.length) {
              for (const key of keys) {
                key.disabled = true;
              }
            }
          }
        }
      });

      formSelectElem.dispatchEvent(new Event("change", {
        bubbles: true
      }));
    };

    <?php
    $all_patterns = [];
    foreach ((array)$data_forms as $form) {
      //出力データ選択パターンを取得
      $patterns = apply_filters('omf_output_data_patterns_' . $form->post_name, []);
      if (empty($patterns)) {
        continue;
      }
      $data_post_type = $this->get_data_post_type_by_id($form->ID);
      $all_patterns[$data_post_type] = $patterns;
    }
    ?>

    //出力データをパターンで選択
    const selectDataPattern = () => {
      const allPatterns = <?php echo json_encode($all_patterns) ?>;
      const buttons = document.querySelectorAll('[data-omf-pattern]');
      if (!buttons.length) {
        return;
      }

      for (const button of buttons) {
        button.addEventListener('click', (e) => {
          const formSelectElem = document.querySelector('[name="omf_data_id"]');
          if (!formSelectElem || !formSelectElem.value) {
            return;
          }

          const omf_data_id = formSelectElem.value;
          const currentPatterns = allPatterns[omf_data_id];
          const patternKey = e.currentTarget.dataset.omfPattern;
          if (!patternKey) {
            return;
          }

          const selectedPattern = patternKey in currentPatterns ? currentPatterns[patternKey] : '';
          if (!selectedPattern) {
            return;
          }

          const dataKeys = document.querySelectorAll('.active[data-omf-data-id] input[name="omf_data_key[]"]');
          if (!dataKeys.length) {
            return;
          }

          for (const dataKeyElem of dataKeys) {
            const dataKey = dataKeyElem.value;
            dataKeyElem.checked = selectedPattern.includes(dataKey);
            dataKeyElem.dispatchEvent(new Event("change", {
              bubbles: true
            }));
          }


        });
      }
    };

    //出力データを全てチェック
    const selectAllDataKeys = () => {
      const buttons = document.querySelectorAll('button[data-omf-all-check]');
      if (!buttons.length) {
        return;
      }
      for (const button of buttons) {
        button.addEventListener('click', () => {
          const dataKeys = document.querySelectorAll('.active[data-omf-data-id] input[name="omf_data_key[]"]');
          if (!dataKeys.length) {
            return;
          }

          for (const dataKeyElem of dataKeys) {
            dataKeyElem.checked = true;
            dataKeyElem.dispatchEvent(new Event("change", {
              bubbles: true
            }));
          }
        });
      }
    };

    //出力データの全てのチェックを外す
    const unselectAllDataKeys = () => {
      const buttons = document.querySelectorAll('button[data-omf-all-uncheck]');
      if (!buttons.length) {
        return;
      }

      for (const button of buttons) {
        button.addEventListener('click', () => {
          const dataKeys = document.querySelectorAll('.active[data-omf-data-id] input[name="omf_data_key[]"]');
          if (!dataKeys.length) {
            return;
          }

          for (const dataKeyElem of dataKeys) {
            dataKeyElem.checked = false;
            dataKeyElem.dispatchEvent(new Event("change", {
              bubbles: true
            }));
          }
        });
      }
    };

    //submitボタンの有効化・無効化
    const toggleSubmitButton = () => {
      const dataKeyElems = document.querySelectorAll('input[name="omf_data_key[]"]');
      if (!dataKeyElems.length) {
        return;
      }

      for (const dataKeyElem of dataKeyElems) {
        dataKeyElem.addEventListener('change', () => {

          const activeDataKeyElems = document.querySelectorAll('.active[data-omf-data-id] input[name="omf_data_key[]"]');
          if (!activeDataKeyElems.length) {
            return;
          }

          let checked = false;
          for (const el of activeDataKeyElems) {
            if (el.checked) {
              checked = true;
              break;
            }
          }

          submitButton.disabled = !checked;

        });
      }
    };



    document.addEventListener('DOMContentLoaded', () => {
      selectForm();
      switchPeriod();
      selectDataPattern();
      selectAllDataKeys();
      unselectAllDataKeys();
      toggleSubmitButton();
    });
  })();
</script>