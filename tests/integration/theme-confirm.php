<?php
use Sharesl\Original\MailForm\OMF;
?>
<article class="fixture-theme-confirm" data-omf-step="confirm"><h1>テーマの確認画面</h1>
<form method="post" action="<?php echo esc_url($context['action_url']); ?>" data-omf-form="<?php echo esc_attr($context['slug']); ?>" data-omf-step="confirm">
<?php OMF::nonce_field(); foreach (OMF::get_fields(['slug'=>'integration']) as $field) { echo '<div class="fixture-row">' . $field . '</div>'; } ?>
<?php OMF::render_buttons(['back'=>'テーマの修正','send'=>'テーマの送信'], ['slug'=>'integration']); ?>
</form></article>
