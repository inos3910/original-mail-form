// ダウンロード後も画面を維持し、実行した操作の通知だけを表示する。
const csvForm = document.querySelector('.omf-output-data form');
if (csvForm) {
  csvForm.addEventListener('submit', async event => {
    event.preventDefault();
    const button = csvForm.querySelector('#output_csv');
    const data = new FormData(csvForm);
    data.set('output_csv', '1');
    data.set('omf_csv_async', '1');
    button.disabled = true;
    const screen = csvForm.closest('.omf-admin-screen');
    screen.querySelectorAll('[data-omf-action-notice]').forEach(notice => notice.remove());
    let message = 'CSVを出力できませんでした。画面を更新して再度お試しください。';
    let success = false;
    try {
      const response = await fetch(csvForm.action || location.href, { method: 'POST', credentials: 'same-origin', body: data });
      const type = response.headers.get('Content-Type') || '';
      if (!response.ok || !type.includes('text/csv')) {
        if (type.includes('application/json')) {
          const result = await response.json();
          message = `CSVを出力できませんでした。${result.data?.message || ''}`;
        }
        throw new Error();
      }
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      const disposition = response.headers.get('Content-Disposition') || '';
      const encodedName = disposition.match(/filename\*\s*=\s*UTF-8'[^']*'([^;]+)/i)?.[1];
      link.download = encodedName
        ? decodeURIComponent(encodedName.trim())
        : disposition.match(/filename="([^"]+)"/)?.[1] || 'form-data.csv';
      document.body.append(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 60000);
      success = true;
      message = 'CSVを出力しました。ダウンロードしたファイルを確認してください。';
    } catch {
      // 通信・権限・nonceの失敗も同じ通知領域へ表示する。
    } finally {
      button.disabled = false;
      const notice = document.createElement('div');
      notice.dataset.omfActionNotice = 'true';
      notice.className = `notice ${success ? 'notice-success' : 'notice-error'}`;
      notice.setAttribute('role', success ? 'status' : 'alert');
      const text = document.createElement('p');
      text.textContent = message;
      notice.append(text);
      screen.querySelector('h1').after(notice);
      notice.scrollIntoView({ block: 'nearest' });
    }
  });
}
