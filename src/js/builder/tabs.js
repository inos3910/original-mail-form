// 入力要素を残したまま用途ごとに表示を切り替え、従来の保存処理を保つ。
const TABS = ['fields', 'mail', 'settings'];

export function setupEditorTabs(root, mode) {
  const box = root.closest('.postbox');
  const form = root.closest('form');
  const tabs = document.createElement('div');
  tabs.className = 'omf-editor-tabs';
  tabs.setAttribute('role', 'tablist');
  tabs.setAttribute('aria-label', 'フォームの設定');
  const groups = { fields: ['omf-builder', 'omf-metabox-validation', 'omf-metabox-screen'], mail: ['omf-metabox-reply_mail', 'omf-metabox-admin_mail'], settings: ['omf-delivery-settings', 'omf-metabox-condition', 'omf-metabox-recaptcha', 'omf-metabox-turnstile', 'omf-metabox-save_db', 'omf-metabox-mail_id', 'omf-metabox-slack_notify', 'omf-metabox-google_sheets', 'slugdiv'] };
  const panels = {};
  const postId = new URLSearchParams(location.search).get('post') || 'new';
  const storageKey = `omf-editor-tab-${postId}`;
  const requested = new URLSearchParams(location.search).get('omf_tab') || sessionStorage.getItem(storageKey);
  let active = TABS.includes(requested) ? requested : 'fields';
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = 'omf_editor_tab';
  form.append(input);
  // メタボックスの並びを用途ごとに集約。閉じた箱も設定選択時に読める状態にする。
  for (const [key, ids] of Object.entries(groups)) {
    const panel = document.createElement('div');
    panel.id = `omf-editor-${key}`;
    panel.className = 'omf-editor-panel';
    panel.setAttribute('role', 'tabpanel');
    panel.setAttribute('aria-labelledby', `omf-tab-${key}`);
    panels[key] = panel;
    ids.forEach(id => { const item = document.getElementById(id); if (item) panel.append(item); });
  }
  const target = document.getElementById('normal-sortables');
  if (!target || !box) return () => {};
  target.prepend(tabs, ...Object.values(panels));
  form.classList.add('omf-editor-screen');
  function persist() {
    sessionStorage.setItem(storageKey, active);
    input.value = active;
    const url = new URL(location.href);
    url.searchParams.set('omf_tab', active);
    history.replaceState(null, '', url);
  }
  function refresh() {
    Object.entries(panels).forEach(([key, panel]) => panel.hidden = key !== active);
    tabs.querySelectorAll('button').forEach(b => { b.setAttribute('aria-selected', String(b.dataset.tab === active)); b.tabIndex = b.dataset.tab === active ? 0 : -1; });
    ['omf-metabox-validation', 'omf-metabox-screen'].forEach(id => { const el = document.getElementById(id); if (el) el.hidden = mode() === 'builder'; });
    persist();
  }
  Object.entries({ fields: 'フォーム', mail: 'メール', settings: 'その他の設定' }).forEach(([key, label]) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    b.id = `omf-tab-${key}`;
    b.dataset.tab = key;
    b.setAttribute('role', 'tab');
    b.setAttribute('aria-controls', panels[key].id);
    b.addEventListener('click', () => { active = key; refresh(); });
    tabs.append(b);
    b.addEventListener('keydown', e => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
      e.preventDefault();
      const buttons = [...tabs.children];
      const i = buttons.indexOf(b);
      const next = e.key === 'Home' ? 0 : e.key === 'End' ? buttons.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
      buttons[next].click();
      buttons[next].focus();
    });
  });
  // 隠れている設定にブラウザの検証エラーがある場合は該当タブを開く。
  form.addEventListener('invalid', e => { const entry = Object.entries(panels).find(([, panel]) => panel.contains(e.target)); if (entry) { active = entry[0]; refresh(); } }, true);
  refresh();
  return refresh;
}
