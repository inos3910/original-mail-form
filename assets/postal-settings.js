document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-omf-postal-update]');
  if (!form) return;
  form.addEventListener('submit', () => {
    const button = form.querySelector('button');
    button.disabled = true;
    button.textContent = '最新データを確認中…';
    form.querySelector('[role="status"]').textContent = '処理が終わるまで、この画面でお待ちください。';
  });
});
