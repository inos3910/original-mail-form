<?php
// 独自レイアウトでも項目順と送信経路をプラグインに委ねる試験。
echo '<div class="fixture-custom-fields" data-step="' . esc_attr($omf_step) . '">';
foreach ($omf_fields as $field) {
  if ($field['key'] === 'message') { echo '<div class="fixture-message">'; }
  \Sharesl\Original\MailForm\OMF::render_field($field, $omf_values[$field['key']], (array) ($omf_errors[$field['key']] ?? []), $omf_step === 'confirm', $omf_form_id);
  if ($field['key'] === 'message') { echo '</div>'; }
}
echo '</div>';
