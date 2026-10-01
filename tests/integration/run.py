"""隔離WordPressでHTTP結合試験。既存サイトのDB・設定・プラグインを変更しない。"""
import argparse
import os
import signal
import html
import http.cookiejar
import json
import mimetypes
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

HERE = Path(__file__).resolve().parent
PLUGIN = HERE.parent.parent

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None

class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def request(self, path, data=None, headers=None, file=None, managed=False):
        if managed and data is not None:
            controls = ['omf_nonce','omf_token','_wp_http_referer','confirm','send','submit_back','omf_restart','cf-turnstile-response']
            data = {key if key in controls else 'omf_fields['+key+']': value for key,value in data.items()}
        headers = dict(headers or {})
        if file:
            boundary = 'omf-' + secrets.token_hex(12)
            body = b''
            for key, value in data.items():
                body += f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
            file_field = 'omf_fields[file]' if managed else 'file'
            mime = mimetypes.guess_type(file[0])[0] or 'application/octet-stream'
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="{file_field}"; filename="{file[0]}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + file[1] + f'\r\n--{boundary}--\r\n'.encode()
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        else:
            body = urllib.parse.urlencode(data).encode() if data is not None else None
        req = urllib.request.Request(self.base + path, data=body, headers=headers)
        try:
            response = self.opener.open(req, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.headers, response.read().decode()

    def fields(self, path='/entry/'):
        code, _, body = self.request(path)
        assert code == 200, (path, code)
        return {name: html.unescape(re.search(f'name="{name}" value="([^"]*)"', body)[1]) for name in ['omf_nonce', 'omf_token', '_wp_http_referer']}


def run(args):
    core = Path(args.wordpress_core).resolve()
    assert (core/'wp-settings.php').is_file(), 'WordPress本体を指定してください'
    db = 'omf_integration_' + secrets.token_hex(6)
    mysql = ['mysql', '--protocol=SOCKET', '--socket=' + args.mysql_socket, '-u', args.mysql_user]
    def sql(query):
        return subprocess.check_output(mysql + ['-N', '-e', query], text=True)
    sql('CREATE DATABASE ' + db)
    process = None
    try:
        with tempfile.TemporaryDirectory(prefix='omf-wp-integration-') as temp:
            root = Path(temp)/'site'
            root.mkdir()
            private = Path(temp)/'private-uploads'
            private.mkdir()
            for source in core.glob('*.php'):
                if source.name != 'wp-config.php': shutil.copyfile(source, root/source.name)
            for name in ['wp-admin', 'wp-includes']: shutil.copytree(core/name, root/name)
            content = root/'wp-content'
            for name in ['plugins', 'mu-plugins', 'themes/omf-integration', 'sessions']:
                (content/name).mkdir(parents=True)
            (content/'plugins/original-mail-form').symlink_to(PLUGIN, target_is_directory=True)
            shutil.copyfile(HERE/'fixture.php', content/'mu-plugins/fixture.php')
            shutil.copyfile(HERE/'template.php', content/'themes/omf-integration/index.php')
            shutil.copyfile(HERE/'custom-fields.php', content/'themes/omf-integration/custom-fields.php')
            shutil.copyfile(HERE/'custom-complete.php', content/'themes/omf-integration/custom-complete.php')
            for step in ['entry', 'confirm', 'complete']:
                shutil.copyfile(HERE/f'theme-{step}.php', content/f'themes/omf-integration/theme-{step}.php')
            (content/'themes/omf-integration/style.css').write_text('/* Theme Name: OMF Integration */')
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
            base = f'http://127.0.0.1:{port}'
            def literal(value): return "'" + str(value).replace('\\', '\\\\').replace("'", "\\'") + "'"
            config = '<?php\n'
            for key, value in {'DB_NAME':db, 'DB_USER':args.mysql_user, 'DB_PASSWORD':'', 'DB_HOST':'localhost:'+args.mysql_socket, 'DB_CHARSET':'utf8mb4', 'WP_HOME':base, 'WP_SITEURL':base, 'OMF_PRIVATE_UPLOAD_DIR':str(private)}.items():
                config += f'define({literal(key)}, {literal(value)});\n'
            for key in ['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']:
                config += f'define({literal(key)}, {literal(secrets.token_hex(32))});\n'
            config += "define('OMF_INTEGRATION_TEST',true); define('WP_ENVIRONMENT_TYPE','local'); define('DISABLE_WP_CRON',true); define('WP_DEBUG',true); define('WP_DEBUG_DISPLAY',false); define('WP_DEBUG_LOG',true); define('AUTOMATIC_UPDATER_DISABLED',true); $table_prefix='wp_'; if (!defined('ABSPATH')) { define('ABSPATH',__DIR__.'/'); } \nrequire_once ABSPATH . 'wp-settings.php';"
            (root/'wp-config.php').write_text(config)
            def wp(*params):
                result = subprocess.run(['wp', '--path='+str(root), *params], text=True, capture_output=True)
                if result.returncode:
                    raise RuntimeError('WP-CLI失敗: ' + result.stderr)
                return result.stdout
            wp('core','install','--url='+base,'--title=OMF Test','--admin_user=fixture','--admin_password='+secrets.token_hex(24),'--admin_email=fixture@example.test','--skip-email')
            wp('plugin','activate','original-mail-form')
            seed_output = wp('eval-file',str(HERE/'seed.php'))
            seeded = json.loads(seed_output)
            def evaluate(code): return wp('eval',code)
            def meta(key,value): evaluate(f'update_post_meta({seeded["form"]},{literal(key)},{literal(value)});')
            def records(): return json.loads(evaluate('echo wp_json_encode(omf_test_records());'))
            (root/'router.php').write_text("<?php $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); if ($path !== '/' && (is_file(__DIR__.$path) || is_file(__DIR__.$path.'/index.php'))) { return false; } $_SERVER['SCRIPT_NAME']='/index.php'; require __DIR__.'/index.php';")
            with (root/'server.log').open('w+') as log:
                process = subprocess.Popen(['php','-d','session.save_path='+str(content/'sessions'),'-S',f'127.0.0.1:{port}','-t',str(root),str(root/'router.php')],stdout=log,stderr=log,start_new_session=True,env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4'))
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.1): break
                    except OSError: time.sleep(.05)
                check_count = 0
                def check(ok,label):
                    nonlocal check_count
                    assert ok,label
                    check_count += 1
                    print('PASS:',label)
                c = Client(base)
                code, headers, _ = c.request('/ordinary/')
                check(code == 200 and 'PHPSESSID' not in headers.get('Set-Cookie',''), '一般ページはセッションCookieを発行しない')
                code,_,_ = Client(base).request('/confirm/',{'send':'send'})
                check(code < 500 and not records(), '確認画面へ直接POSTしても500・メール送信にならない')
                fields = c.fields()
                code,headers,_ = c.request('/entry/',dict(fields,email='invalid',message='本文保持',confirm='confirm'))
                code,_,body = c.request('/entry/')
                check(code == 200 and '本文保持' in body and 'email' in body.split('id="errors"')[1], '入力エラーと本文を保持')
                fields = c.fields()
                def confirm(client,fields,message='試験本文',file=None,extra=None):
                    return client.request('/entry/',dict(fields,email='user@example.test',message=message,confirm='confirm',**(extra or {})),file=file)
                code,headers,_ = confirm(c,fields)
                check(code == 303 and '/confirm' in headers['Location'], '入力から確認へ303遷移')
                fields = c.fields('/confirm/')
                evaluate('update_option("omf_test_fail_admin",true);')
                code,headers,_ = c.request('/confirm/',dict(fields,send='send'))
                first = records()
                check([x['ok'] for x in first] == [True,False] and '/complete' not in headers.get('Location',''), '通知失敗を完了扱いにしない')
                evaluate('update_option("omf_test_fail_admin",false);')
                # 失敗時は入力画面へ戻るため、入力保持と再確認を経て送信する。
                fields = c.fields()
                confirm(c,fields)
                fields = c.fields('/confirm/')
                code,headers,_ = c.request('/confirm/',dict(fields,send='send'))
                check(code == 303 and '/complete' in headers['Location'], '失敗後の再試行から完了へ遷移')
                check([x['kind'] for x in records()] == ['reply','admin','admin'], '再試行で成功済み自動返信を重複送信しない')
                saved = json.loads(evaluate('echo wp_json_encode(get_posts(["post_type"=>"omf_db_' + str(seeded['form']) + '","fields"=>"ids","posts_per_page"=>-1]));'))
                check(len(saved) == 1, '再試行でDBの受付レコードを重複作成しない')
                check('送信成功' in evaluate(f'echo get_post_meta({saved[0]},"omf_admin_mail_sended",true);'), '通知再試行後のDB結果を更新')
                code,_,body = c.request('/complete/')
                check(code == 200 and '<h1>complete</h1>' in body, '完了画面を表示')
                before=len(records()); c.request('/confirm/',dict(fields,send='send'))
                check(len(records()) == before, '使用済みトークンを再送しても送信しない')
                c = Client(base); fields = c.fields()
                code,_,_ = confirm(c,fields,file=('sample.txt',b'Integration attachment'))
                check(code == 303 and len(list(private.rglob('sample.txt'))) == 1, '実multipartの添付を非公開保存')
                fields = c.fields('/confirm/'); code,_,_=c.request('/confirm/',dict(fields,send='send'))
                check(code == 303 and [x['attachments'] for x in records()[-2:]] == [0,1], '通知のみ添付し自動返信には添付しない')
                check(len(list(private.rglob('sample.txt'))) == 1, '旧方式では成功後も添付を非公開のまま保持')
                # 後続の新方式の一時添付試験へ、旧方式の保存済み添付を持ち込まない。
                evaluate('foreach(get_posts(["post_type"=>"attachment","post_status"=>"any","meta_key"=>"_omf_retained_upload","meta_value"=>"1"]) as $file){wp_delete_attachment($file->ID,true);}')
                c = Client(base); fields=c.fields(); before=len(records())
                code,_,_=confirm(c,fields,file=('attack.php',b'<?php echo 1;'))
                check(code != 500 and not list(private.rglob('attack.php')) and len(records())==before, 'PHP添付を保存・送信しない')
                meta('cf_omf_turnstile','1')
                c=Client(base); fields=c.fields();code,headers,_=confirm(c,fields)
                check('/confirm' not in headers.get('Location',''), 'CAPTCHA未通過を拒否')
                fields=c.fields();code,headers,_=confirm(c,fields,extra={'cf-turnstile-response':'fixture-pass'})
                check(code==303 and '/confirm' in headers['Location'], 'CAPTCHA通過後に確認へ進む')
                fields=c.fields('/confirm/');code,headers,_=c.request('/confirm/',dict(fields,send='send'))
                check(code==303 and '/complete' in headers['Location'], '同じ入力のCAPTCHA証明で送信できる')
                c=Client(base); fields=c.fields(); confirm(c,fields,extra={'cf-turnstile-response':'fixture-pass'})
                fields=c.fields('/confirm/'); before=len(records())
                code,headers,_=c.request('/confirm/',dict(fields,send='send',message='改ざん'))
                check('/complete' not in headers.get('Location','') and len(records()) == before, 'CAPTCHA通過後の本文変更を拒否')
                meta('cf_omf_turnstile','')
                c=Client(base); fields=c.fields(); confirm(c,fields)
                fields=c.fields('/confirm/'); before=len(records())
                evaluate('update_option("omf_test_fail_reply",true);')
                c.request('/confirm/',dict(fields,send='send'))
                evaluate('update_option("omf_test_fail_reply",false);')
                fields=c.fields(); confirm(c,fields); fields=c.fields('/confirm/')
                code,headers,_=c.request('/confirm/',dict(fields,send='send'))
                check(code==303 and '/complete' in headers['Location'] and [x['kind'] for x in records()[before:]] == ['reply','admin','reply'], '返信失敗の再試行では成功済み通知を重複送信しない')
                latest=int(evaluate('echo get_posts(["post_type"=>"omf_db_' + str(seeded['form']) + '","fields"=>"ids","posts_per_page"=>1,"orderby"=>"ID"])[0];'))
                check('送信成功' in evaluate(f'echo get_post_meta({latest},"omf_reply_mail_sended",true);'), '返信再試行後のDB結果を更新')
                schema = {'version': 1, 'fields': [
                    {'key':'email','type':'email','label':'メール','required':True},
                    {'key':'message','type':'select','label':'選択','required':True,'choices':[{'value':'a,b','label':'選択A'}]}
                ]}
                evaluate(f'update_post_meta({seeded["form"]},"cf_omf_field_schema",json_decode({literal(json.dumps(schema, ensure_ascii=False))},true));')
                meta('cf_omf_form_mode','builder')
                c=Client(base); fields=c.fields(); code,headers,_=confirm(c,fields,message='a,b')
                check(code==303 and '/confirm' in headers['Location'], '共通定義から確認画面へ進む')
                fields=c.fields('/confirm/');code,headers,_=c.request('/confirm/',dict(fields,send='send'))
                check(code==303 and '/complete' in headers['Location'] and records()[-1]['message']=='a,b', '共通定義の選択値をメール本文へ渡す')
                c=Client(base);fields=c.fields();before=len(records());code,headers,_=confirm(c,fields,message='a')
                check('/confirm' not in headers.get('Location','') and len(records())==before, '共通定義の選択肢改ざんをHTTPでも拒否')
                admin_result = evaluate("wp_set_current_user(1); $_POST = ['omf_meta_nonce'=>wp_create_nonce('omf_save_meta'), 'omf_builder_mode'=>'builder', 'omf_builder_schema'=>wp_slash(wp_json_encode(['version'=>1,'fields'=>[['key'=>'email','type'=>'email','label'=>'メール','required'=>true],['key'=>'message','type'=>'textarea','label'=>'保存テスト']]]))]; $GLOBALS['global_omf']->get_instance('admin')->save_omf_custom_field(get_option('omf_test_form_id')); echo get_post_meta(get_option('omf_test_form_id'),'cf_omf_field_schema',true)['fields'][1]['label'];")
                check(admin_result == '保存テスト', '実WordPressの権限・nonceを通して項目定義を保存')
                denied_result = evaluate('wp_set_current_user(0); $_POST=[\'omf_meta_nonce\'=>wp_create_nonce(\'omf_save_meta\'),\'omf_builder_mode\'=>\'code\',\'omf_builder_schema\'=>\'{"version":1,"fields":[]}\']; $GLOBALS[\'global_omf\']->get_instance(\'admin\')->save_omf_custom_field(get_option(\'omf_test_form_id\')); echo get_post_meta(get_option(\'omf_test_form_id\'),\'cf_omf_form_mode\',true);')
                check(denied_result == 'builder', '実WordPressで権限なしの項目設定変更を拒否')
                from managed_flow import run_managed
                run_managed(content, private, wp, evaluate, literal, base, Client, check, records, seeded)
                print(wp('eval-file',str(HERE/'delivery.php')),end='')
                print(wp('eval-file',str(HERE/'cron.php')),end='')
                print(wp('eval-file',str(HERE/'page-link.php')),end='')
                from fse import run_fse
                run_fse(root, content, wp, evaluate, literal, base, Client, check, records, seeded['form'])
                check(not (content/'debug.log').exists() or not (content/'debug.log').read_text().strip(), 'WordPressの警告・例外ログなし')
                print(f'HTTP結合試験: {check_count}項目成功（外部メール/APIは遮断）')
                if args.browser_review:
                    postal_demo={'version':1,'fields':[
                        {'key':'postal_code','type':'text','label':'郵便番号（住所1行）','validation_format':'postal_code','address_targets':{'full':'address'}},
                        {'key':'address','type':'text','label':'住所（1行）'},
                        {'key':'postal_split','type':'text','label':'郵便番号（住所分割）','validation_format':'postal_code','address_targets':{'prefecture':'prefecture','city':'address1'}},
                        {'key':'prefecture','type':'select','label':'都道府県','choices':[{'value':name,'label':name} for name in ['東京都','大阪府','北海道']]},
                        {'key':'address1','type':'text','label':'住所1（市区町村・町域）'},
                        {'key':'address2','type':'text','label':'住所2（番地・マンション名等）'}
                    ]}
                    evaluate(f'update_post_meta({seeded["form"]},"cf_omf_field_schema",json_decode({literal(json.dumps(postal_demo,ensure_ascii=False))},true));')
                    meta('cf_omf_front_validation','1');meta('cf_omf_turnstile','');meta('cf_omf_delivery_mode','serial')
                    # 資格情報を出力せず、隔離サイトだけにローカル試験用ログイン入口を用意する。
                    (content/'mu-plugins/browser-review.php').write_text("<?php if (!defined('OMF_INTEGRATION_TEST') || !OMF_INTEGRATION_TEST || PHP_SAPI !== 'cli-server') { return; } add_action('init', static function () { if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/omf-test-login') { return; } wp_set_current_user(1); wp_set_auth_cookie(1); wp_safe_redirect(admin_url('post.php?post=' . get_option('omf_test_form_id') . '&action=edit')); exit; });")
                    print('ブラウザ試験: ' + base + '/omf-test-login', flush=True)
                    input('確認後Enterで試験サイト・DBを片付けます。')
                os.killpg(process.pid,signal.SIGTERM); process.wait(); process=None
    finally:
        if process: os.killpg(process.pid,signal.SIGTERM); process.wait()
        # この実行で新規作成したランダム名のDBのみ破棄する。
        sql('DROP DATABASE ' + db)

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wordpress-core',required=True)
    parser.add_argument('--mysql-socket',required=True)
    parser.add_argument('--mysql-user',default='root',help='パスワードなしのローカル試験DBユーザー')
    parser.add_argument('--browser-review',action='store_true',help='隔離した実管理画面での確認中、試験サイトを維持する')
    run(parser.parse_args())
