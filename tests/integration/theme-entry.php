<?php
use Sharesl\Original\MailForm\OMF;
?>
<main class="fixture-theme-entry" data-omf-step="entry"><h1>テーマの入力画面</h1>
<form method="post" enctype="multipart/form-data" action="<?php echo esc_url($context['action_url']); ?>" data-omf-form="<?php echo esc_attr($context['slug']); ?>" data-omf-step="entry">
<?php
OMF::nonce_field();
foreach (OMF::get_fields(['slug'=>'integration']) as $field) { echo '<div class="fixture-row">' . $field . '</div>'; }
OMF::recaptcha_field(); OMF::turnstile_field();
?>
<?php OMF::render_buttons(['confirm'=>'テーマの次へ','send'=>'テーマの送信'], ['slug'=>'integration']); ?>
</form></main>
