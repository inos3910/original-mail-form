<?php
//管理画面 送信データ
?>
<div class="wrap">
  <h1>送信データ</h1>
  <div class="admin_optional">
    <?php
    $mail_forms = $this->get_forms();
    if (!empty($mail_forms)) {
    ?>
      <table class="wp-list-table widefat fixed striped" cellspacing="0">
        <thead>
          <th>フォーム名</th>
          <th>DB保存件数</th>
          <th>更新日</th>
          <th>作成日</th>
          <th>CSV出力</th>
        </thead>
        <?php
        foreach ((array)$mail_forms as $form) {
          $is_use_db = get_post_meta($form->ID, 'cf_omf_save_db', true) === '1';
          if (!$is_use_db) {
            continue;
          }

          $data_post_type = $this->get_data_post_type_by_id($form->ID);
          if (empty($data_post_type)) {
            continue;
          }

          $data_list = get_posts([
            'posts_per_page'  => -1,
            'post_type'       => $data_post_type,
            'post_status'     => 'publish'
          ]);

          //件数
          $data_count = count($data_list);
          //更新日
          $latest_post_date = '';
          //作成日
          $publish_post_date = '';

          if (!empty($data_list)) {
            // 更新日時でソート
            usort($data_list, function ($a, $b) {
              return $b->post_modified <=> $a->post_modified;
            });
            $latest_post = $data_list[0];
            $latest_post_date = get_the_modified_date('Y年n月j日', $latest_post);

            // 公開日でソート
            usort($data_list, function ($a, $b) {
              return $b->post_date <=> $a->post_date;
            });
            $publish_post = $data_list[0];
            $publish_post_date = get_the_date('Y年n月j日', $publish_post);
          }

          $output_data_url = current_user_can('editor') ? "admin.php?page=omf_output_data&omf_data_id={$data_post_type}" : "edit.php?post_type=original_mail_forms&page=omf_output_data&omf_data_id={$data_post_type}";
        ?>
          <tr>
            <td><a href="<?php echo esc_url(admin_url("edit.php?post_type={$data_post_type}")) ?>"><?php echo esc_html(get_the_title($form->ID)) ?></a></td>
            <td><?php echo esc_html($data_count) ?>件</td>
            <td><?php echo esc_html($latest_post_date) ?></td>
            <td><?php echo esc_html($publish_post_date) ?></td>
            <td><a href="<?php echo esc_url(admin_url($output_data_url)) ?>">CSV出力</a></td>
          </tr>
        <?php
        }
        ?>
      </table>
    <?php
    }
    ?>
  </div>
</div>