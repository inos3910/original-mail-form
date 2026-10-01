// 選択した連絡方法などに応じて必須表示を切り替える。最終判定はPHP側でも行う。
const omfPendingForms = new Map();
document.querySelectorAll('form[data-omf-form]').forEach(form => {
  const targets = form.querySelectorAll('[data-omf-required-if-key]');
  const sync = () => {
    targets.forEach(target => {
      const source = form.elements.namedItem(`omf_fields[${target.dataset.omfRequiredIfKey}]`);
      const required = source && source.value === target.dataset.omfRequiredIfValue;
      target.required = Boolean(required);
      const marker = target.parentElement?.closest('[data-omf-field]')?.querySelector('.omf-managed-required--conditional');
      if (marker) marker.hidden = !required;
    });
  };
  form.addEventListener('change', sync);
  sync();

  const status = document.createElement('p');
  status.className = 'omf-submit-status';
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');
  status.hidden = true;
  // inertで操作を止めても、進行状況は支援技術へ伝えられる位置に置く。
  form.after(status);
  let pending = null;
  const restore = (message = '') => {
    if (!pending) return;
    clearTimeout(pending.lockTimer);
    clearTimeout(pending.timer);
    pending.controls.forEach(({element, disabled, ariaDisabled}) => {
      element.disabled = disabled;
      if (ariaDisabled === null) element.removeAttribute('aria-disabled');
      else element.setAttribute('aria-disabled', ariaDisabled);
    });
    if (pending.submitter) {
      pending.submitter.replaceChildren(...pending.labelNodes);
      if (pending.loading === null) pending.submitter.removeAttribute('data-omf-loading');
      else pending.submitter.setAttribute('data-omf-loading', pending.loading);
    }
    form.inert = pending.inert;
    if (pending.ariaBusy === null) form.removeAttribute('aria-busy');
    else form.setAttribute('aria-busy', pending.ariaBusy);
    delete form.dataset.omfState;
    pending = null;
    status.textContent = message;
    status.hidden = message === '';
    // 入力条件によるdisabledは再判定し、未入力のボタンを有効にしない。
    form.dispatchEvent(new Event('change', {bubbles: true}));
  };
  omfPendingForms.set(form, restore);

  document.addEventListener('submit', event => {
    if (event.target !== form || event.defaultPrevented) return;
    if (pending) { event.preventDefault(); return; }
    // フォーム自身の検証ハンドラーが終わった後に、ローディングを始める。
    const submitter = event.submitter;
    pending = {
      submitter, labelNodes: [...(submitter?.childNodes ?? [])], loading: submitter?.getAttribute('data-omf-loading') ?? null, inert: form.inert,
      ariaBusy: form.getAttribute('aria-busy'),
      controls: [...form.querySelectorAll('input, select, textarea, button')].map(element => ({
        element, disabled: element.disabled, ariaDisabled: element.getAttribute('aria-disabled'),
      })),
    };
    const action = submitter?.dataset.omfAction || submitter?.name;
    const message = action === 'confirm' ? '入力内容を確認しています…' : action === 'back' || action === 'submit_back' ? '入力画面に戻っています…' : '送信しています…';
    form.dataset.omfState = 'submitting';
    form.setAttribute('aria-busy', 'true');
    if (submitter) {
      submitter.textContent = action === 'confirm' ? '確認中…' : action === 'back' || action === 'submit_back' ? '移動中…' : '送信中…';
      submitter.setAttribute('data-omf-loading', 'true');
    }
    form.inert = true;
    status.textContent = message;
    status.hidden = false;
    const configured = Number(form.dataset.omfSubmitTimeout);
    const timeout = Number.isFinite(configured) && configured > 0 ? configured : 60000;
    pending.timer = setTimeout(() => restore('応答を確認できなかったため、操作できる状態に戻しました。送信済みの可能性があるため、再送前に状況をご確認ください。'), timeout);
    const state = pending;
    // ブラウザがPOST値を確定してからdisabledにし、入力・添付・操作名を通常どおり送る。
    pending.lockTimer = setTimeout(() => {
      if (pending !== state) return;
      if (event.defaultPrevented) { restore(); return; }
      state.controls.forEach(({element}) => { element.disabled = true; element.setAttribute('aria-disabled', 'true'); });
    }, 0);
  });
});

// 戻る/エラーの直後だけPHPから渡された値を使い、ブラウザ独自の入力復元は抑える。
window.addEventListener('pageshow', event => {
  omfPendingForms.forEach(restore => restore());
  if (!document.querySelector('[data-omf-step]')) return;
  if (event.persisted) { location.reload(); return; }
  document.querySelectorAll('form[data-omf-form][data-omf-step="entry"]').forEach(form => { form.reset(); form.dispatchEvent(new Event('change', {bubbles: true})); });
});
