// 旧コード方式の完了画面だけで、原型の履歴操作を維持する。
(() => {
  window.history.pushState(null, null, document.URL);
  window.addEventListener('popstate', () => {
    window.history.pushState(null, null, document.URL);
  });
})();
