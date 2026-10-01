"""実ブロックテーマで本文設置・状態分離・無効設置を検証する。"""
import json


def run_fse(root, content, wp, evaluate, literal, base, Client, check, records, form):
    theme = content / 'themes/omf-fse'
    (theme / 'templates').mkdir(parents=True)
    (theme / 'style.css').write_text('/* Theme Name: OMF FSE Test */')
    (theme / 'theme.json').write_text(json.dumps({'version': 2, 'settings': {'layout': {'contentSize': '800px', 'wideSize': '1200px'}}, 'styles': {'spacing': {'padding': {'left': '16px', 'right': '16px'}}, 'typography': {'fontFamily': 'system-ui, sans-serif'}}}))
    (theme / 'templates/index.html').write_text('<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:post-title {"level":1} /--><!-- wp:post-content /--></div><!-- /wp:group -->')
    ids = json.loads(wp('eval-file', str(__import__('pathlib').Path(__file__).with_name('fse-seed.php'))))
    check(evaluate('echo wp_is_block_theme() ? "yes" : "no";') == 'yes', '実ブロックテーマを使用')
    check(evaluate(f'echo get_post_meta({ids["fse-block"]},"cf_omf_select",true);') == 'fse-block-form', '本文設置もページの連携ON設定を使用し、描画で書き換えない')
    def meta(slug, key, value): evaluate(f'update_post_meta({ids[slug+"-form"]},{literal(key)},{literal(value)});')
    def submit(c, slug, message, extra=None, file=None):
        path = '/'+slug+'/'
        return c.request(path, dict(c.fields(path), email='fse@example.test', message=message, agree='1', confirm='confirm', **(extra or {})), managed=True, file=file)
    # 認証済みの送信情報を持っていても、途中で連携OFFにしたページから送れない。
    for slug in ['fse-block','fse-shortcode','fse-slug-shortcode','fse-nested','fse-pattern','fse-post']:
        for suffix in ['', '-confirm', '-complete']:
            client = Client(base)
            submit(client, slug, '連携OFFの検証')
            fields = client.fields('/'+slug+'-confirm/')
            page_id = ids[slug+suffix]
            evaluate(f'update_post_meta({page_id},"cf_omf_select","");')
            try:
                code, headers, body = Client(base).request('/'+slug+suffix+'/')
                check(code == 200 and 'name="omf_nonce"' not in body and 'data-omf-form=' not in body and 'PHPSESSID' not in headers.get('Set-Cookie',''), '連携OFFで本文フォーム・送信用セッションを生成しない: '+slug+suffix)
                before = len(records())
                client.request('/'+slug+'-confirm/', dict(fields, send='send'), managed=True)
                client.request('/'+slug+suffix+'/', dict(fields, send='send'), managed=True)
                check(len(records()) == before, '連携OFFで認証済みPOSTからも送信しない: '+slug+suffix)
            finally:
                evaluate(f'update_post_meta({page_id},"cf_omf_select",{literal(slug+"-form")});')
        check('data-omf-form=' in Client(base).request('/'+slug+'/')[2], '連携ONへ戻すと本文フォームを利用できる: '+slug)
    # 別フォームを選択した場合と、連携欄を未保存の場合も自動で有効にしない。
    for value in ['integration', None]:
        page_id = ids['fse-block']
        evaluate(f'delete_post_meta({page_id},"cf_omf_select");' if value is None else f'update_post_meta({page_id},"cf_omf_select",{literal(value)});')
        try:
            check('data-omf-form=' not in Client(base).request('/fse-block/')[2], '本文フォームと連携先が不一致・未設定なら無効: '+str(value))
        finally:
            evaluate(f'update_post_meta({page_id},"cf_omf_select","fse-block-form");')
    c = Client(base)
    a = c.fields('/fse-block/'); b = c.fields('/fse-shortcode/')
    check(a['omf_token'] != b['omf_token'] and a['omf_nonce'] != b['omf_nonce'], '別フォームのnonceとトークンを分離')
    before = len(records())
    c.request('/fse-shortcode/', dict(a, email='fse@example.test', message='別フォームのトークン', agree='1', confirm='confirm'), managed=True)
    check(c.request('/fse-shortcode-confirm/')[0] == 303 and len(records()) == before, '別フォームの認証情報を拒否')
    submit(c, 'fse-block', 'ブロック側の本文')
    submit(c, 'fse-shortcode', 'ショートコード側の本文')
    check('ブロック側の本文' in c.request('/fse-block-confirm/')[2] and 'ショートコード側の本文' in c.request('/fse-shortcode-confirm/')[2], '別フォームを同時に確認しても状態を混同しない')
    for slug in ['fse-block','fse-shortcode','fse-slug-shortcode','fse-nested','fse-pattern','fse-post']:
        client = Client(base)
        code, headers, body = client.request('/'+slug+'/')
        check(code == 200 and 'name="omf_fields[email]"' in body, '入れ子・同期パターン・投稿を含む本文設置: '+slug)
        result = submit(client, slug, slug+'本文')
        check(result[0] == 303 and result[1]['Location'].endswith('/'+slug+'-confirm/'), '本文設置も確認専用URLへ303遷移: '+slug)
        check(slug+'本文' in client.request('/'+slug+'-confirm/')[2], 'ページを越えて同じフォームの入力を共有: '+slug)
        fields = client.fields('/'+slug+'-confirm/')
        client.request('/'+slug+'-confirm/', dict(fields, send='send'), managed=True)
        body = client.request('/'+slug+'-complete/')[2]
        check('お問い合わせありがとうございます。' in body and records()[-1]['message'] == slug+'本文', '本文設置から専用完了URL: '+slug)
        check(client.request('/'+slug+'-complete/')[0] == 303, '本文設置の完了も単回表示: '+slug)
        count = len(records()); client.request('/'+slug+'-confirm/', dict(fields, send='send'), managed=True)
        check(len(records()) == count, '本文設置の二重送信防止: '+slug)
    for slug in ['fse-duplicate','fse-mixed','fse-missing','fse-draft','fse-code','fse-query','fse-cycle','fse-empty']:
        client = Client(base); before = len(records())
        code, headers, body = client.request('/'+slug+'/')
        check(code == 200 and 'name="omf_nonce"' not in body and 'PHPSESSID' not in headers.get('Set-Cookie',''), '無効設置で送信用セッションを作らない: '+slug)
        client.request('/'+slug+'/', {'send':'send','omf_token':'invalid'})
        check(len(records()) == before, '無効設置のPOSTは送信しない: '+slug)
    slug = 'fse-block'
    meta(slug, 'cf_omf_turnstile', '1')
    c = Client(base); submit(c, slug, 'CAPTCHA未認証')
    check('omf-managed-errors' in c.request('/fse-block/')[2], 'FSEでもCAPTCHAを省略できない')
    submit(c, slug, '添付つきFSE', {'cf-turnstile-response':'fixture-pass'}, ('fse.txt',b'FSE attachment'))
    check('fse.txt' in c.request('/fse-block-confirm/')[2], 'FSEでCAPTCHA・添付を専用URLに共有')
    c.request('/fse-block-confirm/', dict(c.fields('/fse-block-confirm/'), send='send'), managed=True)
    check('お問い合わせありがとうございます。' in c.request('/fse-block-complete/')[2] and records()[-1]['attachments'] == 1, 'FSEで添付を送信')
    meta(slug, 'cf_omf_turnstile', ''); meta(slug, 'cf_omf_skip_confirm', '1'); meta(slug, 'cf_omf_screen_confirm', '')
    c = Client(base)
    c.request('/fse-block/', dict(c.fields('/fse-block/'), email='fse@example.test', message='確認省略', agree='1', send='send'), managed=True)
    check('お問い合わせありがとうございます。' in c.request('/fse-block-complete/')[2], 'FSEも確認未設定の2ページで送信')
    meta(slug, 'cf_omf_skip_confirm', '0'); meta(slug, 'cf_omf_screen_confirm', 'fse-block-confirm')
    slug = 'fse-shortcode'
    for mode in ['wp_async','server_cron','parallel']:
        meta(slug, 'cf_omf_delivery_mode', mode)
        c = Client(base); before = len(records()); submit(c, slug, mode)
        c.request('/fse-shortcode-confirm/', dict(c.fields('/fse-shortcode-confirm/'), send='send'), managed=True)
        body = c.request('/fse-shortcode-complete/')[2]
        if mode == 'parallel':
            check('お問い合わせありがとうございます。' in body and len(records()) == before+2, 'FSEで同期並列送信')
        else:
            check('お問い合わせありがとうございます。' in body and len(records()) == before, 'FSEで非同期受付: '+mode)
            if mode == 'wp_async': wp('cron','event','run','omf_delivery_tick')
            else: wp('omf','delivery','run')
            check(len(records()) == before+2, 'FSEの単回完了表示後にワーカーが配送: '+mode)
    meta(slug, 'cf_omf_delivery_mode', 'serial')
    check(Client(base).request('/wp-json/original-mail-form/v1/forms')[0] == 401, 'エディターのフォーム検索は未認証を拒否')
    print(wp('eval-file', str(__import__('pathlib').Path(__file__).with_name('fse-permissions.php'))), end='')
    print('FSEデモ: ' + base + '/fse-block/', flush=True)
    print('FSE本文編集: ' + base + '/wp-admin/post.php?post=' + str(ids['fse-block']) + '&action=edit', flush=True)
