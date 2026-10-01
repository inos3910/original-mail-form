<?php
echo '<p class="fixture-complete" data-omf-step="complete">独自の完了文言です。</p>';
echo '<p class="fixture-complete-value">' . esc_html($omf_values['message'] ?? '') . '</p>';
echo '<p class="fixture-api-value">' . esc_html(\Sharesl\Original\MailForm\OMF::get_post_values()['message'] ?? '') . '</p>';
echo '<p class="fixture-api-step">' . esc_html(\Sharesl\Original\MailForm\OMF::form_step(['slug'=>'integration'])) . '</p>';
\Sharesl\Original\MailForm\OMF::nonce_field();
\Sharesl\Original\MailForm\OMF::get_omf_token();
