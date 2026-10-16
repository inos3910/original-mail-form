// 配布用ファイルだけを release/ に集める（npm run package から、ビルドの後に実行する）。
// 出力：release/original-mail-form/（FTPなどでそのまま置く用）と release/original-mail-form.zip（管理画面からのアップロード・GitHubリリース用）
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const root = path.resolve(__dirname, '..');
const slug = 'original-mail-form';
const outDir = path.join(root, 'release');
const target = path.join(outDir, slug);
const zipFile = path.join(outDir, slug + '.zip');

// 配布に含めるもの（readme「配布と開発」の構成）。これ以外（tests・src・docs・tools・node_modules など）は含めない。
const entries = ['original-mail-form.php', 'autoload.php', 'classes', 'templates', 'dist', 'assets', 'blocks'];
// OS やエディタが作る不要ファイル
const ignored = new Set(['.DS_Store', 'Thumbs.db']);

for (const entry of entries) {
  if (!fs.existsSync(path.join(root, entry))) {
    throw new Error('配布に必要な ' + entry + ' がありません。');
  }
}

fs.rmSync(outDir, { recursive: true, force: true });
fs.mkdirSync(target, { recursive: true });
for (const entry of entries) {
  fs.cpSync(path.join(root, entry), path.join(target, entry), {
    recursive: true,
    filter: (source) => !ignored.has(path.basename(source)),
  });
}

// ZIP のトップ階層を original-mail-form/ にする
execFileSync('zip', ['-rqX', zipFile, slug], { cwd: outDir });

const version = (fs.readFileSync(path.join(root, 'original-mail-form.php'), 'utf8').match(/^\s*\*\s*Version:\s*(\S+)/m) || [])[1] || '不明';
const count = (dir) => fs.readdirSync(dir, { withFileTypes: true }).reduce((sum, item) => sum + (item.isDirectory() ? count(path.join(dir, item.name)) : 1), 0);
console.log('バージョン ' + version + ' の配布用ファイルを作成しました（' + count(target) + 'ファイル）');
console.log('  フォルダ：' + path.relative(root, target) + '/');
console.log('  ZIP　　：' + path.relative(root, zipFile) + '（' + Math.round(fs.statSync(zipFile).size / 1024) + 'KB）');
