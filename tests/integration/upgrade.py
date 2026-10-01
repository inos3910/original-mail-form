"""原型の設定・テンプレートを保ち、ファイル交換だけで新版へ更新する回帰試験。"""
import argparse
import base64
import html
import http.cookiejar
import json
from pathlib import Path
import re
import shutil
import sys
import urllib.error
import urllib.request
import zipfile

sys.dont_write_bytecode = True
from run import Client, NoRedirect
from upgrade_setup import UpgradeSite

class LoopbackPolicy(http.cookiejar.DefaultCookiePolicy):
    def return_ok_secure(self, cookie, request):
        # 原型がHTTPでもSecure Cookieを発行するため、隔離ループバック試験だけで返す。
        return True

class UpgradeClient(Client):
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar(policy=LoopbackPolicy())), NoRedirect())

    def binary(self, path):
        try: response = self.opener.open(self.base + path, timeout=20)
        except urllib.error.HTTPError as error: response = error
        return response.status, response.read()

def run(args):
    count = 0
    def check(ok, label):
        nonlocal count
        assert ok, label
        count += 1
        print('PASS:', label, flush=True)
    site = UpgradeSite(args)
    try:
        site.__enter__()
        ev, lit, ids, base = site.evaluate, site.literal, site.ids, site.base
        def meta(key, value): ev(f'update_post_meta({ids["form"]},{lit(key)},{lit(value)});')
        def data(client):
            code, _, body = client.request('/contact/confirm/')
            assert code == 200, '確認GET'
            return json.loads(html.unescape(re.search(r'<pre id="upgrade-data">(.*?)</pre>', body, re.S)[1]))
        def mails(): return json.loads(ev('echo wp_json_encode(get_option("upgrade_mail",[]));'))
        payload = {'username':'載せ替え試験','email':'fixture@example.test','message':'更新前後の本文','communication':'必ず電話','privacy':'同意する','custom_hidden':'検証不要の値'}
        def confirm(client, file=None, extra=None):
            return client.request('/contact/', dict(client.fields('/contact/'), **dict(payload, **(extra or {})), confirm='confirm'), file=file)
        def send(client): return client.request('/contact/confirm/',dict(client.fields('/contact/confirm/'),send='send'))
        def flow(label, empty_email=False):
            client=UpgradeClient(base);before=len(mails())
            response=confirm(client,extra={'email':''} if empty_email else None)
            check('/contact/confirm/' in response[1].get('Location',''),label+' 旧入力から確認へ進む')
            values=data(client)
            check(values.get('communication')=='必ず電話' and values.get('custom_hidden')=='検証不要の値',label+' 検証設定のない項目・hiddenを維持')
            check('/contact/complete/' in send(client)[1].get('Location',''),label+' hidden入力値なしの確認から完了へ進む')
            generated=mails()[before:]
            check(len(generated)==(1 if empty_email else 2),label+' 返信省略または2通生成が原型どおり')
            check(all('必ず電話' in mail['message'] for mail in generated),label+' 未登録の入力値をメールへ反映')
            hook=json.loads(ev('echo wp_json_encode(get_option("upgrade_hook",[]));'))
            check(hook.get('custom_hidden')=='検証不要の値',label+' 既存送信後フックの入力値を維持')
            code,_,body=client.request('/contact/complete/')
            check(code==200,label+' 旧完了テンプレートを表示')
            if label=='更新後':check('disable-back-button.js' in body,label+' 旧完了画面の履歴抑止を維持')
        def rest(label):
            client=UpgradeClient(base)
            nonce=ev('echo wp_create_nonce("wp_rest");')
            response=client.request('/wp-json/omf-api/v0/validate',{},headers={'X-WP-Nonce':nonce,'X-OMF-POST-ID':str(ids['entry'])})
            check(response[0]==200 and json.loads(response[2])['valid'] is False,label+' REST v0の検証NGをHTTP 200のJSONで返す')
            response=client.request('/wp-json/omf-api/v0/send',payload,headers={'X-OMF-POST-ID':str(ids['entry'])})
            check(response[0]==200 and 'errors' in json.loads(response[2]),label+' REST v0の認証NGの旧応答を維持')
            fields=client.fields('/contact/');before=len(mails())
            headers={'X-WP-Nonce':nonce,'X-OMF-POST-ID':str(ids['entry']),'X-OMF-TOKEN':fields['omf_token']}
            response=client.request('/wp-json/omf-api/v0/send',payload,headers=headers)
            check(response[0]==200 and json.loads(response[2]).get('is_sended') is True and len(mails())==before+2,label+' REST v0の有効なnonce・トークンで2通送信')
            if label=='更新後':
                client.request('/wp-json/omf-api/v0/send',payload,headers=headers)
                check(len(mails())==before+2,'旧RESTでも使用済みトークンによる二重送信を拒否')
        flow('更新前');flow('更新前・返信先空欄',True);rest('更新前')
        held=UpgradeClient(base);confirm(held);held_fields=held.fields('/contact/confirm/')
        image=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4foAAAAASUVORK5CYII=')
        attached=UpgradeClient(base);confirm(attached,('legacy.png',image));old_file=data(attached)['file']
        check(bool(old_file.get('attachment_id')) and bool(old_file.get('image')), '原型の添付・プレビューが成立する')
        digest=ev(f'echo hash("sha256",serialize(get_post_meta({ids["form"]})));')
        records=ev(f'echo count(get_posts(["post_type"=>"omf_db_{ids["form"]}","fields"=>"ids","posts_per_page"=>-1]));')
        debug=site.content/'debug.log'
        baseline_log=debug.read_text() if debug.exists() else ''
        site.install_new()
        check(ev(f'echo hash("sha256",serialize(get_post_meta({ids["form"]})));')==digest,'載せ替えだけで既存フォーム設定を変更しない')
        check(ev(f'echo Sharesl\\Original\\MailForm\\OMF_Field_Schema::mode({ids["form"]});')=='code','旧方式を自動で切り替えない')
        check(ev(f'echo Sharesl\\Original\\MailForm\\OMF_Delivery::mode({ids["form"]});')=='serial','既存の同期直列を維持')
        check(ev(f'echo count(get_posts(["post_type"=>"omf_db_{ids["form"]}","fields"=>"ids","posts_per_page"=>-1]));')==records,'原型の受付記録を維持')
        check('/contact/complete/' in held.request('/contact/confirm/',dict(held_fields,send='send'))[1].get('Location',''),'更新前に開いた確認画面から送信できる')
        file=data(attached)['file'];url=file['image']['src'];path=url.removeprefix(base)
        check(file['attachment_id']==old_file['attachment_id'] and file.get('upload_id'), '更新前の添付をメディアIDを維持して非公開へ移す')
        check(attached.binary(path)==(200,image),'本人の旧プレビューは認証付きで表示できる')
        check(UpgradeClient(base).binary(path)[0]==404,'別セッションは旧添付を取得できない')
        check(attached.binary(path.replace(str(file['attachment_id']), '999999'))[0]==404,'保管IDが合っていてもメディアIDの偽造を拒否')
        check(attached.binary('/?omf_upload=../wp-config.php&omf_attachment='+str(file['attachment_id']))[0]==404,'添付URLのパストラバーサルを拒否')
        physical=ev(f'echo get_attached_file({file["attachment_id"]});')
        check(Path(physical).is_file() and not Path(physical).is_relative_to(site.root),'追加設定なしで公開領域外へ保管')
        before=len(mails());check('/contact/complete/' in send(attached)[1].get('Location',''),'更新前に添付した確認画面から送信できる')
        check([item['attachments'] for item in mails()[before:]]==[0,1], '非公開添付を通知へ配送し、自動返信からの漏えいを防ぐ')
        check('legacy.png' in mails()[-1]['message'] and Path(physical).is_file(),'旧添付タグ・保存済みメディアを維持')
        ev(f'touch(dirname(get_attached_file({file["attachment_id"]})),time()-7200); Sharesl\\Original\\MailForm\\OMF_Uploads::cleanup();')
        check(Path(physical).is_file(),'送信済みの旧メディアは一時添付の期限削除から保護')
        ev(f'wp_delete_attachment({file["attachment_id"]},true);')
        check(not Path(physical).exists(),'管理者のメディア削除で非公開の実体も削除')
        flow('更新後');flow('更新後・返信先空欄',True);rest('更新後')
        client=UpgradeClient(base);confirm(client,('sample.txt',b'legacy text'))
        file=data(client)['file'];check(file['name']=='sample.txt' and file.get('attachment_id'),'拡張子・file型の旧設定がなくても添付を受け付ける')
        check('/contact/complete/' in send(client)[1].get('Location',''),'旧設定の未登録添付を送信できる')
        client=UpgradeClient(base);before=len(mails());confirm(client,('attack.php',b'<?php echo 1;'))
        check(len(mails())==before and client.request('/contact/confirm/')[0]!=200,'未登録添付でも実行可能ファイルを拒否')
        client=UpgradeClient(base);confirm(client,('expire.txt',b'expire'))
        file=data(client)['file'];physical=ev(f'echo get_attached_file({file["attachment_id"]});')
        ev(f'touch(dirname(get_attached_file({file["attachment_id"]})),time()-7200); Sharesl\\Original\\MailForm\\OMF_Uploads::cleanup();')
        check(not Path(physical).exists() and ev(f'echo get_post({file["attachment_id"]})?"exists":"gone";')=='gone','未送信の期限切れ添付は実体・メディア情報を削除')
        # 更新前にはCAPTCHA通過記録がない。信頼できる記録なしに送信を許可しない。
        meta('cf_omf_turnstile','1');ev('update_option("omf_turnstile_secret_key","fixture");update_option("omf_turnstile_site_key","fixture");')
        client=UpgradeClient(base);before=len(mails())
        confirm(client,extra={'cf-turnstile-response':'fixture-pass'})
        check(client.request('/contact/confirm/')[0]==200,'更新後のCAPTCHA通過で旧確認テンプレートを表示')
        check('/contact/complete/' in send(client)[1].get('Location','') and len(mails())==before+2,'旧確認テンプレートのCAPTCHA再出力なしでも検証済み入力を送信')
        client=UpgradeClient(base);before=len(mails());confirm(client,extra={'cf-turnstile-response':'fixture-fail'})
        check(client.request('/contact/confirm/')[0]!=200 and len(mails())==before,'CAPTCHA失敗時は旧方式でも確認・送信を拒否')
        meta('cf_omf_turnstile','')
        client=UpgradeClient(base);confirm(client);meta('cf_omf_turnstile','1');before=len(mails())
        check('/contact/complete/' not in send(client)[1].get('Location','') and len(mails())==before,'更新前相当のCAPTCHA記録なしの確認は再認証を要求し送信しない')
        meta('cf_omf_turnstile','')
        client=UpgradeClient(base);confirm(client);before=len(mails());ev('update_option("upgrade_mail_fail",true);delete_option("upgrade_hook");')
        check('/contact/complete/' not in send(client)[1].get('Location','') and len(mails())==before+2,'旧方式の配送失敗は完了扱いにしない')
        check(json.loads(ev('echo wp_json_encode(get_option("upgrade_hook",[]));')).get('custom_hidden')=='検証不要の値','旧方式の配送失敗でも既存の送信後フックへ入力値を渡す')
        ev('delete_option("upgrade_mail_fail");')
        # 管理画面の保存経路を通し、その後にだけテーマを新APIへ調整する。
        fields=[{'key':'username','type':'text','label':'お名前','required':True},{'key':'email','type':'email','label':'メール'},{'key':'message','type':'textarea','label':'内容','required':True},{'key':'privacy','type':'text','label':'同意','required':True},{'key':'communication','type':'text','label':'連絡方法'},{'key':'file','type':'file','label':'添付','extensions':['txt']}]
        schema=json.dumps({'version':1,'fields':fields},ensure_ascii=False)
        result=ev(f'wp_set_current_user(1); $_POST=["omf_meta_nonce"=>wp_create_nonce("omf_save_meta"),"omf_builder_mode"=>"builder","omf_builder_schema"=>wp_slash({lit(schema)})]; $GLOBALS["global_omf"]->get_instance("admin")->save_omf_custom_field({ids["form"]}); echo get_post_meta({ids["form"]},"cf_omf_form_mode",true);')
        check(result=='builder','載せ替え後に管理画面の保存で新方式へ切り替えられる')
        ev('update_option("upgrade_builder_template",true);')
        client=UpgradeClient(base);response=client.request('/contact/',dict(client.fields('/contact/'),**payload,confirm='confirm'),managed=True)
        check('/contact/confirm/' in response[1].get('Location',''),'テンプレート調整後に新方式の入力から確認へ進む')
        check('/contact/complete/' in client.request('/contact/confirm/',dict(client.fields('/contact/confirm/'),send='send'),managed=True)[1].get('Location',''),'切り替え後も確認・送信が成立する')
        check('お問い合わせありがとうございます。' in client.request('/contact/complete/')[2],'新方式の完了表示が成立する')
        result=ev(f'wp_set_current_user(1);$_POST=["omf_meta_nonce"=>wp_create_nonce("omf_save_meta"),"omf_builder_mode"=>"code","omf_builder_schema"=>wp_slash({lit(schema)})];$GLOBALS["global_omf"]->get_instance("admin")->save_omf_custom_field({ids["form"]});echo get_post_meta({ids["form"]},"cf_omf_form_mode",true);')
        check(result=='code','管理画面から旧方式へ戻せる')
        ev('delete_option("upgrade_builder_template");')
        flow('旧方式へ戻した後')
        archive=site.root/'master.zip'
        with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as package:
            for item in site.plugin.rglob('*'):
                if item.is_file():package.write(item,'original-mail-form-master/'+str(item.relative_to(site.plugin)))
        current_digest=ev(f'echo hash("sha256",serialize(get_post_meta({ids["form"]})));')
        invoke='$m=new ReflectionMethod($GLOBALS["global_omf"]->get_instance("admin"),"update_plugin_from_github");$m->setAccessible(true);$m->invoke($GLOBALS["global_omf"]->get_instance("admin"));'
        deny='add_filter("wp_die_handler",static fn()=>static function(){throw new RuntimeException("denied");});'
        for user,nonce,label in [(0,'wp_create_nonce("omf_update_plugin")','権限なしの旧更新操作を拒否'),(1,'"invalid"','nonceなしの旧更新操作を拒否')]:
            output=ev(f'wp_set_current_user({user});$_REQUEST["_wpnonce"]={nonce};{deny}try{{{invoke}echo "allowed";}}catch(RuntimeException $e){{echo $e->getMessage();}}')
            check(output=='denied',label)
        output=ev(f'wp_set_current_user(1);$_REQUEST["_wpnonce"]=$_POST["_wpnonce"]=wp_create_nonce("omf_update_plugin");add_filter("upgrader_pre_download",static fn()=>{lit(archive)},10,1);$m=new ReflectionMethod($GLOBALS["global_omf"]->get_instance("admin"),"update_plugin_from_github");$m->setAccessible(true);$m->invoke($GLOBALS["global_omf"]->get_instance("admin"));')
        check('プラグインが更新されました。' in output and site.plugin.is_dir() and not (site.content/'plugins/original-mail-form-master').exists(),'旧master更新操作も標準の安全な展開で有効パスを維持')
        check(ev(f'echo hash("sha256",serialize(get_post_meta({ids["form"]})));')==current_digest,'更新メニューで再更新しても設定を保持')
        check(ev('echo is_plugin_active("original-mail-form/original-mail-form.php")?"yes":"no";')=='yes','更新メニューで再更新してもプラグインの有効状態を保持')
        current_log=debug.read_text() if debug.exists() else ''
        if current_log!=baseline_log:
            Path('/tmp/omf-upgrade-new-debug.log').write_text(current_log[len(baseline_log):])
        check(current_log==baseline_log,'新版の載せ替え・更新・旧新方式の試験で警告・例外を追加しない')
        print(f'載せ替え互換性試験: {count}項目成功（実メール・外部APIは遮断）')
    finally: site.__exit__()

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wordpress-core',required=True);parser.add_argument('--original-plugin',required=True);parser.add_argument('--original-templates',required=True)
    parser.add_argument('--mysql-socket',required=True);parser.add_argument('--mysql-user',default='root')
    parser.add_argument('--php',default=shutil.which('php'));parser.add_argument('--wp-cli',default=shutil.which('wp'))
    run(parser.parse_args())
