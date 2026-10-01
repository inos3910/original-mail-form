// 画面の保存値は従来のパスのまま、タイトル検索で入力を補助する。
export function setupScreenPicker() {
  const config = window.omfPostPicker;
  if (!config) return;
  const box = document.getElementById('omf-metabox-screen');
  if (!box) return;
  ['entry', 'confirm', 'complete'].forEach(step => {
    const path = box.querySelector(`[name="cf_omf_screen_${step}"]`);
    if (!path) return;
    const label = box.querySelector(`label[for="${path.id}"]`).textContent;
    path.setAttribute('aria-label', `${label}のパス`);
    const root = document.createElement('div');
    root.className = 'omf-post-picker omf-screen-picker';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'ページタイトルで検索';
    search.setAttribute('aria-label', `${label}のページを検索`);
    search.autocomplete = 'off';
    const results = document.createElement('div');
    results.className = 'omf-post-picker-results';
    results.id = `omf-screen-results-${step}`;
    search.setAttribute('aria-controls', results.id);
    const message = document.createElement('p');
    message.setAttribute('role', 'status');
    message.textContent = '候補を選ぶと下のパスに反映されます。パスの手入力もできます。';
    root.append(search, results, message);
    path.before(root);
    let timer, controller, sequence = 0;
    function cancel() {
      clearTimeout(timer);
      controller?.abort();
      sequence++;
      results.replaceChildren();
    }
    search.addEventListener('input', () => {
      cancel();
      const q = search.value.trim();
      if (!q) { message.textContent = 'ページタイトルを入力してください。'; return; }
      const ticket = sequence;
      message.textContent = '検索しています…';
      timer = setTimeout(async () => {
        controller = new AbortController();
        const requestController = controller;
        const timeout = setTimeout(() => requestController.abort(), 10000);
        try {
          const url = new URL(config.url, location.href);
          url.search = new URLSearchParams({action: 'omf_search_pages', nonce: config.nonce, q}).toString();
          const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin'});
          const data = await response.json();
          if (ticket !== sequence) return;
          if (!response.ok || !data.success) throw new Error('search');
          results.replaceChildren();
          data.data.forEach(item => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = `${item.title} · ${item.type} · ${item.status} · /${item.path}`;
            button.addEventListener('click', () => {
              path.value = item.path;
              path.dispatchEvent(new Event('input', {bubbles: true}));
              cancel();
              search.value = '';
              message.textContent = `「${item.title}」を選択しました。保存すると反映されます。${item.published ? '' : 'ページの公開状態も確認してください。'}`;
              path.focus();
            });
            results.append(button);
          });
          message.textContent = data.data.length ? `${data.data.length}件の候補があります。選択してください。` : '候補がありません。検索語を変えるか、パスを直接入力してください。';
        } catch (error) {
          if (ticket === sequence) message.textContent = '検索できませんでした。再検索するか、パスを直接入力してください。';
        } finally {
          clearTimeout(timeout);
        }
      }, 250);
    });
    search.addEventListener('keydown', e => {
      if (e.isComposing) return;
      if (e.key === 'Enter') e.preventDefault();
      if (e.key === 'Escape') cancel();
      if (e.key === 'ArrowDown') { e.preventDefault(); results.querySelector('button')?.focus(); }
    });
    results.addEventListener('keydown', e => {
      if (e.key === 'Escape') { cancel(); search.focus(); }
      if (!['ArrowDown', 'ArrowUp'].includes(e.key)) return;
      e.preventDefault();
      const buttons = [...results.children];
      const next = buttons.indexOf(document.activeElement) + (e.key === 'ArrowDown' ? 1 : -1);
      if (next < 0) search.focus(); else buttons[Math.min(next, buttons.length - 1)]?.focus();
    });
  });
  const skip = document.querySelector('[name="omf_skip_confirm"]');
  const mode = document.querySelector('[name="omf_builder_mode"]');
  const notice = box.querySelector('[data-omf-confirm-skipped]');
  const refresh = () => { notice.hidden = !(mode?.value === 'builder' && skip?.value === '1'); };
  skip?.addEventListener('change', refresh);
  mode?.addEventListener('change', refresh);
  refresh();
}
