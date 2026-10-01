<?php
use Sharesl\Original\MailForm\OMF;
?>
<div class="fixture-theme-complete" data-omf-step="complete"><h1>テーマの完了画面</h1>
<p><?php echo nl2br(esc_html($context['complete_message'])); ?></p>
<p class="fixture-api-value"><?php echo esc_html(OMF::get_post_values()['message'] ?? ''); ?></p>
<?php OMF::render_buttons(['home'=>'トップページへ戻る'], ['slug'=>'integration']); ?>
</div>
<?php OMF::nonce_field(); OMF::get_omf_token(); ?>
