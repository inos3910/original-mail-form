// 書き出し画面：範囲に応じてパネルを出し、要約とボタンの状態を更新する。JS が無くても全入力が見えて送信できる。
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-omf-port-export]');
  if (!form) return;
  const panel = form.querySelector('[data-omf-port-panel]');
  const summary = form.querySelector('[data-omf-port-summary]');
  const button = form.querySelector('[type="submit"]');
  const total = Number(form.dataset.total || 0);
  const checks = (name) => Array.from(form.querySelectorAll('input[name="' + name + '"]'));
  const scope = () => (form.querySelector('input[name="scope"]:checked') || {}).value;

  form.querySelectorAll('[data-omf-port-bulk]').forEach((box) => { box.hidden = false; });
  form.querySelectorAll('[data-omf-port-check]').forEach((link) => {
    link.addEventListener('click', () => {
      checks(link.dataset.omfPortCheck).forEach((input) => { input.checked = link.dataset.checked === '1'; });
      update();
    });
  });

  function message() {
    const mode = scope();
    if (mode === 'all') return { text: '全フォーム（' + total + '件）と共通設定を書き出します。' };
    if (mode === 'settings') return { text: '共通設定だけを書き出します。' };
    if (mode !== 'forms') return { text: '書き出す範囲を選んでください。', blocked: true };
    const forms = checks('form_ids[]').filter((input) => input.checked);
    const parts = checks('parts[]').filter((input) => input.checked);
    if (forms.length === 0) return { text: '書き出すフォームを選んでください。', blocked: true };
    if (parts.length === 0) return { text: '書き出す項目を1つ以上選んでください。', blocked: true };
    const names = parts.map((input) => input.closest('label').querySelector('.omf-port-part-name').textContent.trim()).join('・');
    const common = form.querySelector('input[name="include_settings"]').checked ? 'と共通設定' : '';
    return { text: 'フォーム' + forms.length + '件（' + names + '）' + common + 'を書き出します。' };
  }

  function update() {
    panel.hidden = scope() !== 'forms';
    const result = message();
    summary.textContent = result.text;
    summary.classList.toggle('is-warning', Boolean(result.blocked));
    button.disabled = Boolean(result.blocked);
  }

  form.addEventListener('change', update);
  update();
});
