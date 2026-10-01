"""専用URL・単回表示・保存済みセッションを実HTTPで検証する。"""
import json
import re
import concurrent.futures
import subprocess


def run_managed(content, private, wp, evaluate, literal, base, Client, check, records, seeded):
    form = seeded['form']
    def meta(key, value): evaluate(f'update_post_meta({form},{literal(key)},{literal(value)});')
    meta('cf_omf_render_enabled', '1')
    def session_path(c):
        jar = next(h.cookiejar for h in c.opener.handlers if hasattr(h, 'cookiejar'))
        sid = next(cookie.value for cookie in jar if cookie.name == 'PHPSESSID')
        return content / 'sessions' / ('sess_' + sid)
    def session(c): return session_path(c).read_text()
    def discard_state(c, key):
        path = session_path(c)
        subprocess.run(['php', '-r', 'session_id(substr(basename($argv[1]),5)); session_start(["use_cookies"=>0,"cache_limiter"=>"","save_path"=>dirname($argv[1])]); unset($_SESSION[$argv[2]]); session_write_close();', str(path), key], check=True, capture_output=True)
    def confirm(c, message='専用URL本文', **extra):
        return c.request('/entry/', dict(c.fields(), email='managed@example.test', message=message, confirm='confirm', **extra), managed=True)
    def entry_redirect(result, label):
        check(result[0] == 303 and result[1]['Location'].endswith('/entry/'), label)
    for step in ['entry', 'confirm', 'complete']:
        client = Client(base); confirm(client)
        fields = client.fields('/confirm/')
        page_id = seeded['pages'][step]
        evaluate(f'update_post_meta({page_id},"cf_omf_select","");')
        try:
            code, headers, body = Client(base).request('/'+step+'/')
            check(code == 200 and 'name="omf_nonce"' not in body and 'data-omf-form=' not in body and 'PHPSESSID' not in headers.get('Set-Cookie',''), 'PHP設置の連携OFFでフォーム・送信用セッションを生成しない: '+step)
            before = len(records())
            client.request('/confirm/', dict(fields, send='send'), managed=True)
            client.request('/'+step+'/', dict(fields, send='send'), managed=True)
            check(len(records()) == before, 'PHP設置の連携OFFで認証済みPOSTからも送信しない: '+step)
        finally:
            evaluate(f'update_post_meta({page_id},"cf_omf_select","integration");')
        check('data-omf-form=' in Client(base).request('/entry/')[2], 'PHP設置の連携ON復帰でフォームを利用できる: '+step)
    c = Client(base)
    for path in ['/confirm/', '/complete/']:
        entry_redirect(c.request(path), '未認証GETを入力へ戻す: ' + path)
        entry_redirect(c.request(path, {'send':'send'}), '不正POSTを入力へ戻す: ' + path)
    for key in ['omf_integration_data', 'omf_integration_auth', 'omf_integration_token']:
        broken = Client(base); confirm(broken); discard_state(broken, key)
        entry_redirect(broken.request('/confirm/'), '確認の表示には入力・認証・トークンが必要: '+key)
    for change in [{'omf_nonce':'invalid'}, {'omf_token':'invalid'}, {'confirm':'confirm', 'send':'send'}]:
        broken = Client(base); fields = broken.fields(); before = len(records())
        result = broken.request('/entry/', {**fields, 'email':'managed@example.test', 'message':'不正POST', 'confirm':'confirm', **change}, managed=True)
        entry_redirect(result, 'nonce・トークン・操作不整合を拒否: '+','.join(change))
        check(len(records()) == before, '不正な入力POSTで送信しない')
    result = confirm(c)
    check(result[0] == 303 and result[1]['Location'].endswith('/confirm/'), '入力から確認専用URLへ303遷移')
    for _ in range(2):
        code, headers, body = c.request('/confirm/')
        check(code == 200 and 'omf-managed--confirm' in body and '専用URL本文' in body and 'no-store' in headers['Cache-Control'], '認証済み確認はGET・リロードで表示しキャッシュ禁止')
    for path in ['/favicon.ico', '/robots.txt', '/wp-json/', '/wp-content/plugins/original-mail-form/assets/managed-form.css']:
        c.request(path)
        check(c.request('/confirm/')[0] == 200, '付随リクエストで認証済み状態を初期化しない: '+path)
    fields = c.fields('/confirm/')
    entry_redirect(c.request('/confirm/', dict(fields, submit_back='back'), managed=True), '修正は入力へ303遷移')
    check('専用URL本文</textarea>' in c.request('/entry/')[2], '修正直後だけ検証済み入力を保持')
    check('専用URL本文</textarea>' not in c.request('/entry/')[2], '通常リロードで入力を初期化')
    confirm(c)
    c.request('/ordinary/')
    entry_redirect(c.request('/confirm/'), '一般ページを訪問すると確認許可を破棄')
    confirm(c)
    fields = c.fields('/confirm/')
    before = len(records())
    # 同じCookie・トークンの競合POSTをPHPセッションのロック下で処理する。
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda _: c.request('/confirm/', dict(fields, send='send', message='差し替え本文'), managed=True, file=('injected.txt', b'injected')), range(2)))
    check(all(r[0] == 303 and r[1]['Location'].endswith('/complete/') for r in results) and len(records()) == before + 2 and records()[-1]['message'] == '専用URL本文', '二重クリックは各1通だけ送信しPOST本文・添付を無視')
    code, _, body = c.request('/complete/')
    check(code == 200 and 'data-omf-step="complete"' in body and 'omf_token' not in body and '<h2' not in body and '<a ' not in body, '完了は1回だけ表示しメッセージ以外のHTMLを強制しない')
    check('omf_integration_data|' not in session(c) and 'omf_integration_token|' not in session(c) and 'omf_active_forms' not in session(c), '描画後の保存済みセッションにフォーム状態・トークンを残さない')
    check('other_plugin_state|' in session(c) and 'omf_integration_extra_data|' in session(c), '完了時に他プラグインと接頭辞が重なる別フォームの状態を残す')
    entry_redirect(c.request('/complete/'), '完了リロードを入力へ戻す')
    entry_redirect(c.request('/confirm/', dict(fields, send='send'), managed=True), '消費済みPOSTの再送を拒否')
    check(len(records()) == before + 2, '消費後の再送でメールを重複させない')
    for mode in ['custom', 'shortcode', 'theme']:
        evaluate(f'update_option("omf_test_render_mode",{literal(mode)});')
        c = Client(base)
        if mode == 'theme':
            body = c.request('/entry/')[2]
            check('fixture-row' in body and 'omf-managed-field--email' in body and 'omf_fields[email]' in body, '項目HTML配列はテーマの外枠内に管理項目・検証属性を返す')
            check('omf-managed-button--confirm' in body and 'テーマの次へ</button>' in body and 'name="send"' not in body, 'テーマから指定文言の確認ボタンだけを共通HTMLで描画')
        confirm(c, message='完了退避値')
        if mode == 'theme':
            body = c.request('/confirm/')[2]
            check('omf-managed-button--back' in body and 'formnovalidate' in body and 'テーマの修正</button>' in body and 'テーマの送信</button>' in body, '確認の共通ボタンは修正と送信の操作属性・指定文言を保持')
        c.request('/confirm/', dict(c.fields('/confirm/'), send='send'), managed=True)
        code, _, body = c.request('/complete/')
        check(code == 200 and 'data-omf-step="complete"' in body, 'PHPテンプレートの設置方式で専用完了URL: '+mode)
        if mode == 'theme':
            check('fixture-theme-complete' in body and 'fixture-api-value">完了退避値' in body and 'トップページへ戻る' in body and 'omf-managed-actions' not in body, '3画面の独立したテーマテンプレートで動的APIだけを利用できる')
        if mode == 'custom':
            check('fixture-complete-value">完了退避値' in body and 'fixture-api-value">完了退避値' in body and 'fixture-api-step">complete' in body, '完了テンプレートと値取得・画面APIは退避済み情報を参照')
        check('omf_token' not in body and 'omf_integration_data|' not in session(c) and 'omf_integration_token|' not in session(c), '完了描画の公開APIでトークン・セッションを再作成しない: '+mode)
    evaluate('delete_option("omf_test_render_mode");')
    # 入力エラーは初回GETだけ保持する。
    c = Client(base)
    entry_redirect(c.request('/entry/', dict(c.fields(), email='bad', message='エラー保持', confirm='confirm'), managed=True), '入力エラーで303遷移')
    body = c.request('/entry/')[2]
    check('エラー保持</textarea>' in body and 'omf-managed-errors' in body, '入力エラー直後の値・エラーを表示')
    check('エラー保持' not in c.request('/entry/')[2], '入力エラー後の通常リロードは初期化')
    # 片側成功を再試行で再送しない。
    c = Client(base); confirm(c, '部分成功本文'); fields = c.fields('/confirm/'); before = len(records())
    evaluate('update_option("omf_test_fail_admin",true);')
    entry_redirect(c.request('/confirm/', dict(fields, send='send'), managed=True), '送信失敗で完了を許可しない')
    body = c.request('/entry/')[2]
    check('部分成功本文</textarea>' in body and '送信できませんでした' in body, '送信エラー直後に値とエラーを保持')
    evaluate('update_option("omf_test_fail_admin",false);')
    # 初回のエラーGETで発行済みのトークンを使い、通常GETによる初期化を挟まない。
    fields = {name: re.search('name="'+name+'" value="([^"]*)"', body)[1] for name in ['omf_nonce','omf_token','_wp_http_referer']}
    c.request('/entry/', dict(fields, email='managed@example.test', message='部分成功本文', confirm='confirm'), managed=True)
    c.request('/confirm/', dict(c.fields('/confirm/'), send='send'), managed=True)
    check([r['kind'] for r in records()[before:]] == ['reply','admin','admin'], '管理画面方式も再試行で成功済み返信を重複送信しない')
    c.request('/complete/')
    meta('cf_omf_skip_confirm', '1'); meta('cf_omf_screen_confirm', '')
    c = Client(base)
    result = c.request('/entry/', dict(c.fields(), email='skip@example.test', message='2ページ送信', send='send'), managed=True)
    check(result[0] == 303 and result[1]['Location'].endswith('/complete/'), '確認未設定でも確認省略は専用完了URLへ進む')
    check('data-omf-step="complete"' in c.request('/complete/')[2], '確認省略の完了を1回表示')
    entry_redirect(c.request('/complete/'), '確認省略の完了リロードも入力へ戻す')
    meta('cf_omf_screen_confirm', 'confirm')
    entry_redirect(c.request('/confirm/'), '確認省略時に確認URLを表示しない')
    meta('cf_omf_skip_confirm', '0')
    for key, value in [('cf_omf_screen_confirm',''), ('cf_omf_screen_complete','entry')]:
        meta(key, value); before = len(records()); c = Client(base)
        code, headers, body = c.request('/entry/')
        check(code == 200 and 'omf-render-notice' in body and 'omf_nonce' not in body and 'PHPSESSID' not in headers.get('Set-Cookie',''), '設定不足・重複で送信フォームとセッションを生成しない: '+key)
        c.request('/entry/', {'send':'send'}); check(len(records()) == before, '設定不備のPOSTを送信しない')
        meta(key, 'confirm' if key.endswith('confirm') else 'complete')
    evaluate(f'update_post_meta({seeded["pages"]["complete"]},"cf_omf_select","wrong-form");')
    check('omf_nonce' not in Client(base).request('/entry/')[2], '専用ページのフォーム指定不一致を拒否')
    evaluate(f'update_post_meta({seeded["pages"]["complete"]},"cf_omf_select","integration");')
    # 管理項目を変更しても全種類と選択値・同意・添付を維持する。
    schema = {'version':1,'fields':[
        {'key':'email','type':'email','label':'メール','required':True},
        {'key':'message','type':'textarea','label':'本文','required':True},
        {'key':'name','type':'text','label':'お名前','default':'初期名'},
        {'key':'tel','type':'tel','label':'電話'}, {'key':'url','type':'url','label':'URL'},
        {'key':'choice','type':'select','label':'選択','choices':[{'value':'a,b','label':'選択A'}]},
        {'key':'radio','type':'radio','label':'単一選択','choices':[{'value':'0','label':'ゼロ'}]},
        {'key':'checks','type':'checkboxes','label':'複数選択','default':['a'],'choices':[{'value':'a','label':'選択A'}]},
        {'key':'agree','type':'acceptance','label':'同意','description':'[個人情報保護方針](/privacy/)を確認'},
        {'key':'file','type':'file','label':'添付','extensions':['txt'],'max_bytes':1048576}]}
    evaluate(f'update_post_meta({form},"cf_omf_field_schema",json_decode({literal(json.dumps(schema,ensure_ascii=False))},true));')
    c = Client(base); body = c.request('/entry/')[2]
    check(all('name="omf_fields['+f['key']+']' in body for f in schema['fields']) and 'value="初期名"' in body, '専用入力URLで基本10種類と初期値を描画')
    meta('cf_omf_turnstile', '1')
    c = Client(base); confirm(c, agree='1'); check('omf-managed-errors' in c.request('/entry/')[2], '専用URLでもCAPTCHA未通過を拒否')
    fields = c.fields()
    c.request('/entry/', dict(fields, email='all@example.test', message='全項目', name='', agree='1', choice='a,b', radio='0', confirm='confirm', **{'cf-turnstile-response':'fixture-pass'}), managed=True, file=('fixture.txt', b'managed attachment'))
    body = c.request('/confirm/')[2]
    check('fixture.txt' in body and '選択A' in body and 'ゼロ' in body and '同意する' in body and 'omf-managed-required' not in body, '確認URLで添付名・選択ラベル・同意を同じ並びに表示')
    c.request('/confirm/', dict(c.fields('/confirm/'), send='send'), managed=True)
    check('お問い合わせありがとうございます。' in c.request('/complete/')[2] and records()[-1]['attachments'] == 1, 'CAPTCHAと添付を含むフォームを専用完了へ送信')
    meta('cf_omf_turnstile', ''); meta('cf_omf_delivery_mode', 'server_cron')
    c = Client(base); before = len(records())
    c.request('/entry/', dict(c.fields(), email='async@example.test', message='非同期添付', agree='1', confirm='confirm'), managed=True, file=('queued.txt',b'queued attachment'))
    c.request('/confirm/', dict(c.fields('/confirm/'), send='send'), managed=True)
    body = c.request('/complete/')[2]
    check('お問い合わせありがとうございます。' in body and len(records()) == before and 'omf_integration_data|' not in session(c) and 'omf_integration_token|' not in session(c), '非同期は永続受付後に単回表示・セッション破棄')
    uploads = list(private.glob('*/*'))
    check(len(uploads) == 1, 'セッション破棄後も非同期配送の添付を保持')
    wp('omf','delivery','run')
    check(len(records()) == before + 2 and records()[-1]['attachments'] == 1 and not uploads[0].exists(), 'セッション破棄後にワーカーが添付を配送・削除')
    # 永続保存失敗を実DBのINSERT失敗として発生させる。
    c = Client(base); confirm(c, '受付失敗', agree='1'); fields = c.fields('/confirm/'); before = len(records())
    debug = content / 'debug.log'
    previous_log = debug.read_text() if debug.exists() else ''
    evaluate('global $wpdb; $wpdb->query("RENAME TABLE ".Sharesl\\Original\\MailForm\\OMF_Delivery_Store::table()." TO omf_test_queue_backup");')
    result = c.request('/confirm/', dict(fields, send='send'), managed=True)
    evaluate('global $wpdb; $wpdb->query("RENAME TABLE omf_test_queue_backup TO ".Sharesl\\Original\\MailForm\\OMF_Delivery_Store::table());')
    entry_redirect(result, '非同期の永続保存失敗では完了へ進まない')
    check(len(records()) == before, '受付失敗で配送しない')
    # 故障注入で予期したDBエラーだけを分類し、他の警告は消さない。
    if debug.exists():
        added = debug.read_text()[len(previous_log):]
        entries = [record for record in re.split(r'(?m)(?=^\[\d{2}-[A-Za-z]{3}-\d{4})', added) if record.strip()]
        check(bool(entries) and all('WordPress database error' in record and 'wp_omf_deliveries' in record for record in entries), '保存失敗時のログは故障注入したテーブルのDBエラーだけ')
        debug.write_text(previous_log)
    meta('cf_omf_delivery_mode', 'serial')
