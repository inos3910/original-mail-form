/* 公開フォームの選択と見本。送信可能な入力欄はエディターに作らない。 */
const { createElement: el, useState, useEffect } = wp.element;
const { ComboboxControl, Notice, Spinner, Button } = wp.components;
const { useBlockProps } = wp.blockEditor;
const { useSelect } = wp.data;

function FieldPreview({ field }) {
  const choice = ['select', 'radio', 'checkboxes', 'acceptance'].includes(field.type);
  const examples = field.type === 'acceptance' ? '□ 同意する' : (field.choices || []).map(item => item.label).join(' / ');
  return el('div', { className: 'omf-block-preview-field' },
    el('div', { className: 'omf-block-preview-label' }, field.label, field.required && el('span', null, '必須')),
    el('div', { className: `omf-block-preview-input is-${field.type}` }, choice ? examples || '選択してください' : field.type === 'file' ? 'ファイルを選択' : '入力欄')
  );
}

function Edit({ attributes, setAttributes, clientId }) {
  const props = useBlockProps({ className: 'omf-block-editor' });
  const [query, setQuery] = useState('');
  const [options, setOptions] = useState([]);
  const [selected, setSelected] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const { formId } = attributes;
  const unsupported = useSelect(select => {
    const editor = select('core/editor');
    const type = editor?.getCurrentPostType?.();
    const blocks = select('core/block-editor');
    return (type && !['post', 'page'].includes(type)) || blocks.getBlockParents(clientId).some(id => blocks.getBlockName(id) === 'core/query');
  }, [clientId]);
  useEffect(() => {
    let active = true;
    setLoading(true); setError('');
    const timer = setTimeout(() => {
      const list = wp.apiFetch({ path: `/original-mail-form/v1/forms?search=${encodeURIComponent(query)}` });
      const chosen = formId ? wp.apiFetch({ path: `/original-mail-form/v1/forms?id=${formId}` }) : Promise.resolve([]);
      Promise.all([list, chosen]).then(([items, current]) => {
        if (!active) return;
        setOptions(items); setSelected(current[0] || null);
        if (formId && !current.length) setError('このフォームは利用できません。公開済みのフォームを選び直してください。');
      }).catch(() => {
        if (active) setError('フォームを読み込めませんでした。ページを再読み込みしてください。');
      }).finally(() => { if (active) setLoading(false); });
    }, 250);
    return () => { active = false; clearTimeout(timer); };
  }, [query, formId]);
  const choices = selected && !options.some(item => item.id === selected.id) ? [selected, ...options] : options;
  return el('div', props,
    el('div', { className: 'omf-block-heading' }, el('strong', null, 'お問い合わせフォーム'), el('span', null, 'フォームを選ぶだけで設置できます')),
    unsupported ? el(Notice, { status: 'warning', isDismissible: false }, '投稿・固定ページの本文に設置してください。テンプレートやクエリーループ内には設置できません。') : el(wp.element.Fragment, null,
      el(ComboboxControl, {
        label: '設置するフォーム', help: 'フォーム名で検索できます。公開済みの「画面でかんたんに作成」フォームが表示されます。',
        value: formId ? String(formId) : '', options: choices.map(item => ({ value: String(item.id), label: item.title })),
        onFilterValueChange: setQuery,
        onChange: value => { setAttributes({ formId: Number(value) || 0 }); setQuery(''); },
      }),
      loading && el('div', { role: 'status' }, el(Spinner), '読み込み中…'),
      error && el(Notice, { status: 'error', isDismissible: false }, error),
      !loading && !error && !choices.length && el(Notice, { status: 'info', isDismissible: false }, '設置できるフォームがありません。フォーム管理画面で作成・公開してください。'),
      selected && !error && el('div', { className: 'omf-block-preview' },
        el('div', { className: 'omf-block-preview-heading' }, el('strong', null, selected.title), el('span', null, '入力欄の見本')),
        selected.fields.map((field, index) => el(FieldPreview, { field, key: index })),
        el('div', { className: 'omf-block-footer' },
          selected.editUrl && el(Button, { variant: 'secondary', href: selected.editUrl, target: '_blank', rel: 'noopener noreferrer' }, 'フォームの項目を編集 ↗'),
          el('p', null, '入力・確認・完了画面は公開ページで確認できます。')
        )
      )
    )
  );
}

wp.blocks.registerBlockType('original-mail-form/form', {
  apiVersion: 3, title: 'お問い合わせフォーム', category: 'widgets', icon: 'email-alt',
  attributes: { formId: { type: 'number', default: 0 } },
  supports: { html: false, multiple: false, align: ['wide', 'full'] },
  edit: Edit, save: () => null,
});
