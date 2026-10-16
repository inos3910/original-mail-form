// サーバー・DB・ファイルを作らず、通信結果に対する画面の反応を検証する。
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/admin-csv.js'), 'utf8');

async function test() {
  let submit;
  let response;
  let downloads = 0;
  let downloadedName = '';
  const notices = [];
  const button = { disabled: false };
  const screen = {
    querySelectorAll: () => [...notices],
    querySelector: () => ({ after: notice => notices.push(notice) }),
  };
  const form = {
    action: '/admin.php?page=omf_data&tab=csv',
    addEventListener: (name, callback) => { submit = callback; },
    querySelector: () => button,
    closest: () => screen,
  };
  const document = {
    querySelector: () => form,
    body: { append() {} },
    createElement: () => ({
      dataset: {}, children: [],
      setAttribute() {}, scrollIntoView() {},
      append(child) { this.children.push(child); },
      click() { downloads++; downloadedName = this.download; },
      remove() { const index = notices.indexOf(this); if (index >= 0) notices.splice(index, 1); },
    }),
  };
  vm.runInNewContext(source, {
    document, location: { href: form.action },
    FormData: class { set() {} },
    fetch: async () => { assert.equal(button.disabled, true); if (response instanceof Error) throw response; return response; },
    URL: { createObjectURL: () => 'blob:download', revokeObjectURL() {} },
    setTimeout() {},
  });
  const trigger = () => submit({ preventDefault() {} });
  response = { ok: true, headers: { get: key => key === 'Content-Type' ? 'text/csv; charset=UTF-8' : 'attachment; filename="test.csv"' }, blob: async () => ({}) };
  await trigger();
  assert.equal(downloads, 1);
  assert.equal(downloadedName, 'test.csv');
  assert.equal(notices.length, 1);
  assert.equal(notices[0].className, 'notice notice-success');
  assert.equal(button.disabled, false);
  const filename = 'お問い合わせ_export_2025-10-08_to_2026-10-08.csv';
  response = { ok: true, headers: { get: key => key === 'Content-Type' ? 'text/csv; charset=UTF-8' : `attachment; filename="form-data.csv"; filename*=UTF-8''${encodeURIComponent(filename)}` }, blob: async () => ({}) };
  await trigger();
  assert.equal(downloadedName, filename);
  assert.equal(downloads, 2);
  assert.equal(notices.length, 1);
  assert.equal(notices[0].className, 'notice notice-success');
  response = { ok: false, headers: { get: () => 'application/json' }, json: async () => ({ success: false, data: { message: 'データがありません。' } }) };
  await trigger();
  assert.equal(notices.length, 1);
  assert.equal(notices[0].className, 'notice notice-error');
  assert.match(notices[0].children[0].textContent, /データがありません/);
  assert.equal(downloads, 2);
  response = new Error('通信失敗');
  await trigger();
  assert.equal(notices.length, 1);
  assert.equal(notices[0].className, 'notice notice-error');
  assert.equal(button.disabled, false);
  response = { ok: true, headers: { get: () => 'text/html' } };
  await trigger();
  assert.equal(notices.length, 1);
  assert.equal(notices[0].className, 'notice notice-error');
  assert.equal(downloads, 2);
  console.log('PASS: 日本語と期間を含むCSV名をdownload属性へ復元（ASCII名も維持）');
  console.log('PASS: CSV成功・業務エラー・通信失敗・ログイン画面への遷移を通知し、重複表示と失敗時のダウンロードを防ぐ');
}
test().catch(error => { console.error(error); process.exitCode = 1; });
