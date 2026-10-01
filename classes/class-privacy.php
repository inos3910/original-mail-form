<?php
namespace Sharesl\Original\MailForm;
if (!defined('ABSPATH')) { exit; }

/** WordPress標準の個人データ出力・消去と保存期間に対応する。 */
class OMF_Privacy
{
  public function __construct()
  {
    add_filter('wp_privacy_personal_data_exporters', function ($items) {
      $items['original-mail-form'] = ['exporter_friendly_name' => 'お問い合わせ', 'callback' => [$this, 'export']];
      return $items;
    });
    add_filter('wp_privacy_personal_data_erasers', function ($items) {
      $items['original-mail-form'] = ['eraser_friendly_name' => 'お問い合わせ', 'callback' => [$this, 'erase']];
      return $items;
    });
    add_action('delete_omf_old_temp_files', [$this, 'expire']);
  }

  private function ids(string $email, int $page): array
  {
    global $wpdb;
    $clauses = []; $args = [];
    foreach (get_posts(['post_type' => OMF_Config::NAME, 'post_status' => 'any', 'posts_per_page' => -1]) as $form) {
      $rules = OMF_Field_Schema::rules($form->ID);
      if (is_wp_error($rules)) { continue; }
      foreach ($rules as $rule) {
        // 通知先など運用者のメールアドレスを本人照合に使わない。
        if (!is_array($rule) || empty($rule['email']) || empty($rule['target'])) { continue; }
        $clauses[] = '(p.post_type=%s AND m.meta_key=%s)';
        $args[] = OMF_Config::DBDATA . $form->ID;
        $args[] = $rule['target'];
      }
    }
    if ($clauses === []) { return []; }
    $where = implode(' OR ', $clauses);
    return $wpdb->get_col($wpdb->prepare(
      "SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE ($where) AND m.meta_value=%s ORDER BY p.ID LIMIT 50 OFFSET %d",
      ...array_merge($args, [$email, (max(1, $page) - 1) * 50])
    ));
  }

  public function export(string $email, int $page = 1): array
  {
    $ids = $this->ids($email, $page); $data = [];
    foreach ($ids as $id) {
      $fields = [];
      foreach (get_post_meta($id) as $name => $values) {
        if (str_starts_with($name, '_')) { continue; }
        foreach ($values as $value) {
          $value = maybe_unserialize($value);
          $fields[] = ['name' => $name, 'value' => is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE)];
        }
      }
      $data[] = ['group_id' => 'original-mail-form', 'group_label' => 'お問い合わせ', 'item_id' => 'omf-' . $id, 'data' => $fields];
    }
    if ($page === 1 && get_option('omf_delivery_schema') === '1') {
      foreach (OMF_Delivery_Store::personal($email) as $row) {
        $fields=[];
        foreach (OMF_Delivery_Store::unpack($row)['admin_info']['tag_to_text'] ?? [] as $key=>$value) {
          $fields[]=['name'=>$key,'value'=>is_scalar($value) ? (string)$value : wp_json_encode($value)];
        }
        $data[]=['group_id'=>'omf-delivery','group_label'=>'配送待ちのお問い合わせ','item_id'=>'omf-delivery-'.$row['id'],'data'=>$fields];
      }
    }
    return ['data' => $data, 'done' => count($ids) < 50];
  }

  public function erase(string $email, int $page = 1): array
  {
    $ids = $this->ids($email, 1); $removed = false; $retained = false;
    foreach ($ids as $id) {
      if (wp_delete_post($id, true)) { $removed = true; } else { $retained = true; }
    }
    if (get_option('omf_delivery_schema') === '1') {
      foreach (OMF_Delivery_Store::personal($email) as $row) {
        if (OMF_Delivery_Store::purge((int)$row['id'])) { $removed=true; } else { $retained=true; }
      }
    }
    return ['items_removed' => $removed, 'items_retained' => $retained, 'messages' => $retained ? ['削除できないお問い合わせがあります。管理者が確認してください。'] : [], 'done' => $retained || count($ids) < 50];
  }

  public function expire(): void
  {
    $days = (int) get_option('omf_retention_days', 0);
    if ($days <= 0) { return; }
    $types = array_values(array_filter(get_post_types(), static fn ($type) => str_starts_with($type, OMF_Config::DBDATA)));
    if ($types === []) { return; }
    $ids = get_posts(['post_type' => $types, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 100,
      'date_query' => [['column' => 'post_date_gmt', 'before' => gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)]]]);
    foreach ($ids as $id) { wp_delete_post($id, true); }
  }
}
