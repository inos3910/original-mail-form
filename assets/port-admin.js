// 書き出し画面：範囲に応じて選択欄を出し、要約とボタンの状態を更新する。JS が無くても全入力が見えて送信できる。
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-omf-port-export]');
  if (!form) return;
  const panel = form.querySelector('[data-omf-port-panel]');
  const summary = form.querySelector('[data-omf-port-summary]');
  const button = form.querySelector('[type="submit"]');
  const total = Number(form.dataset.total || 0);
  const checks = (name) => Array.from(form.querySelectorAll('input[name="' + name + '"]'));
  const checked = (name) => checks(name).filter((input) => input.checked);
  const scope = () => (form.querySelector('input[name="scope"]:checked') || {}).value;
  const all = form.querySelector('[data-omf-port-check-all]');

  if (all) {
    all.closest('[data-omf-port-all]').hidden = false;
    all.addEventListener('change', () => {
      checks(all.dataset.omfPortCheckAll).forEach((input) => { input.checked = all.checked; });
    });
  }

  function message() {
    const mode = scope();
    if (mode === 'all') return { text: 'すべてのフォーム（' + total + '件）と共通設定を書き出します。' };
    if (mode === 'settings') return { text: '共通設定だけを書き出します。' };
    if (mode !== 'forms') return { text: '書き出す範囲を選んでください。', blocked: true };
    const forms = checked('form_ids[]');
    const parts = checked('parts[]');
    if (forms.length === 0) return { text: '書き出すフォームを選んでください。', blocked: true };
    if (parts.length === 0) return { text: '書き出す項目を1つ以上選んでください。', blocked: true };
    const names = parts.length === checks('parts[]').length ? 'すべての項目' : parts.map((input) => input.dataset.label).join('・');
    const common = checked('include_settings').length ? 'と共通設定' : '';
    return { text: 'フォーム' + forms.length + '件（' + names + '）' + common + 'を書き出します。' };
  }

  function update() {
    panel.hidden = scope() !== 'forms';
    if (all) {
      const count = checked('form_ids[]').length;
      all.checked = count > 0 && count === checks('form_ids[]').length;
      all.indeterminate = count > 0 && !all.checked;
    }
    const result = message();
    summary.textContent = result.text;
    summary.classList.toggle('is-warning', Boolean(result.blocked));
    button.disabled = Boolean(result.blocked);
  }

  form.addEventListener('change', update);
  update();
});
