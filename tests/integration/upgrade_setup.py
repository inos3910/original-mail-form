"""原型を読み取り専用で利用し、製品更新を模した隔離WordPressを構築する。"""
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time

HERE = Path(__file__).resolve().parent
SOURCE = HERE.parent.parent

class UpgradeSite:
    def __init__(self, args):
        self.args = args
        self.db = 'omf_upgrade_' + secrets.token_hex(6)
        self.process = None
        self.private = None
        self.temp = tempfile.TemporaryDirectory(prefix='omf-upgrade-')
        self.root = Path(self.temp.name)/'site'

    def sql(self, query):
        return subprocess.check_output(['mysql','--protocol=SOCKET','--socket='+self.args.mysql_socket,'-u',self.args.mysql_user,'-N','-e',query],text=True)

    def wp(self, *args):
        result = subprocess.run([self.args.php,self.args.wp_cli,'--path='+str(self.root),*args],text=True,capture_output=True)
        if result.returncode: raise RuntimeError('隔離WP-CLI失敗: '+result.stderr[-1200:])
        return '\n'.join(line for line in result.stdout.splitlines() if not line.startswith(('Notice:','Warning:','Deprecated:'))).strip()

    def evaluate(self, code): return self.wp('eval',code)

    @staticmethod
    def literal(value): return "'" + str(value).replace('\\','\\\\').replace("'","\\'") + "'"

    def install_new(self):
        shutil.rmtree(self.plugin)
        self.plugin.mkdir()
        for name in ['classes','assets','blocks','dist','templates']: shutil.copytree(SOURCE/name,self.plugin/name)
        for name in ['original-mail-form.php','autoload.php']: shutil.copyfile(SOURCE/name,self.plugin/name)
        # 再有効化・設定保存を行わず、プラグインのファイルのみ交換する。

    def __enter__(self):
        self.sql('CREATE DATABASE '+self.db)
        self.root.mkdir()
        core=Path(self.args.wordpress_core)
        for file in core.glob('*.php'):
            if file.name!='wp-config.php': shutil.copyfile(file,self.root/file.name)
        for name in ['wp-admin','wp-includes']: shutil.copytree(core/name,self.root/name)
        self.content=self.root/'wp-content'
        for name in ['plugins','mu-plugins','themes/omf-upgrade','sessions']: (self.content/name).mkdir(parents=True)
        self.plugin=self.content/'plugins/original-mail-form'
        shutil.copytree(self.args.original_plugin,self.plugin,ignore=shutil.ignore_patterns('node_modules','.git','.DS_Store'))
        shutil.copyfile(HERE/'upgrade-fixture.php',self.content/'mu-plugins/upgrade.php')
        self.theme=self.content/'themes/omf-upgrade'
        (self.theme/'style.css').write_text('/* Theme Name: OMF Upgrade Test */')
        for step,name in [('entry','form'),('confirm','confirm'),('complete','complete')]: shutil.copyfile(Path(self.args.original_templates)/(name+'.php'),self.theme/(step+'.php'))
        (self.theme/'index.php').write_text(r'''<?php
use Sharesl\Original\MailForm\OMF;
$name=get_post_field('post_name',get_queried_object_id());$step=$name==='confirm'?'confirm':($name==='complete'?'complete':'entry');
echo '<!doctype html><html><head>';wp_head();echo '</head><body>';
if(get_option('upgrade_builder_template')){include __DIR__.'/builder.php';}else{echo '<pre id="upgrade-data">'.esc_html(wp_json_encode(OMF::get_post_values())).'</pre>';include __DIR__.'/'.$step.'.php';}
wp_footer();echo '</body></html>';''')
        (self.theme/'builder.php').write_text(r'''<?php
use Sharesl\Original\MailForm\OMF;
$context=OMF::form_context(['slug'=>'contact']);
if(is_wp_error($context)){echo esc_html($context->get_error_message());return;}
if($context['step']==='complete'){echo esc_html($context['complete_message']);return;}
echo '<form method="post" data-omf-form="contact">';OMF::nonce_field();
foreach(OMF::get_fields(['slug'=>'contact']) as $field){echo '<div class="theme-row">'.$field.'</div>';}
OMF::render_buttons([],['slug'=>'contact']);echo '</form>';''')
        with socket.socket() as sock: sock.bind(('127.0.0.1',0));self.port=sock.getsockname()[1]
        self.base='http://127.0.0.1:'+str(self.port)
        config='<?php\n'
        values={'DB_NAME':self.db,'DB_USER':self.args.mysql_user,'DB_PASSWORD':'','DB_HOST':'localhost:'+self.args.mysql_socket,'DB_CHARSET':'utf8mb4','WP_HOME':self.base,'WP_SITEURL':self.base}
        for key in ['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']: values[key]=secrets.token_hex(32)
        for key,value in values.items():config+='define('+self.literal(key)+','+self.literal(value)+');\n'
        config+="define('OMF_INTEGRATION_TEST',true);define('DISABLE_WP_CRON',true);define('WP_ENVIRONMENT_TYPE','local');define('WP_DEBUG',true);define('WP_DEBUG_DISPLAY',false);define('WP_DEBUG_LOG',true);define('AUTOMATIC_UPDATER_DISABLED',true);$table_prefix='wp_';if(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire_once ABSPATH . 'wp-settings.php';"
        (self.root/'wp-config.php').write_text(config)
        self.wp('core','install','--url='+self.base,'--title=Upgrade Fixture','--admin_user=fixture','--admin_password='+secrets.token_hex(24),'--admin_email=fixture@example.test','--skip-email')
        self.wp('plugin','activate','original-mail-form')
        output=self.wp('eval-file',str(HERE/'upgrade-seed.php'))
        self.ids=json.loads(output[output.rfind('{'):])
        # OMF_PRIVATE_UPLOAD_DIRは設定しない。既存サイトに追加設定が不要な経路を試す。
        dirs=json.loads(self.evaluate('echo wp_json_encode([get_temp_dir(),ini_get("upload_tmp_dir"),sys_get_temp_dir()]);'))
        digest=hashlib.sha256((str(self.root)+'/').encode()).hexdigest()[:16]
        self.private=[Path(directory)/('omf-'+digest) for directory in dirs if directory]
        (self.root/'router.php').write_text("<?php $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if($p!=='/'&&(is_file(__DIR__.$p)||is_file(__DIR__.$p.'/index.php'))){return false;}$_SERVER['SCRIPT_NAME']='/index.php';require __DIR__.'/index.php';")
        self.log=(self.root/'server.log').open('w+')
        self.process=subprocess.Popen([self.args.php,'-d','opcache.enable=0','-d','opcache.enable_cli=0','-d','session.save_path='+str(self.content/'sessions'),'-S','127.0.0.1:'+str(self.port),'-t',str(self.root),str(self.root/'router.php')],stdout=self.log,stderr=self.log)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',self.port),timeout=.1):break
            except OSError: time.sleep(.05)
        return self

    def __exit__(self,*args):
        if self.process:self.process.terminate();self.process.wait(timeout=10);self.log.close()
        for directory in self.private or []: shutil.rmtree(directory,ignore_errors=True)
        self.sql('DROP DATABASE IF EXISTS '+self.db)
        self.temp.cleanup()
