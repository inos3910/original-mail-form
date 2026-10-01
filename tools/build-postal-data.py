"""日本郵便UTF-8郵便番号CSVを、先頭3桁ごとの同梱JSONへ変換する。"""
import argparse, csv, io, json, zipfile, hashlib
from pathlib import Path
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('zip_file');parser.add_argument('--date',required=True);args=parser.parse_args()
raw=Path(args.zip_file).read_bytes()
with zipfile.ZipFile(io.BytesIO(raw)) as archive:
 data=archive.read(next(name for name in archive.namelist() if name.lower().endswith('.csv'))).decode('utf-8-sig')
groups={}
for row in csv.reader(io.StringIO(data)):
 code=row[2]
 if len(code)!=7 or not code.isascii() or not code.isdigit():raise ValueError('郵便番号の形式が不正です')
 town=row[8]
 # 郵便番号から特定できない注記や番地条件は住所に挿入しない。
 if '以下に掲載がない場合' in town or 'の次に番地がくる場合' in town or '一円'==town:town=''
 else:town=town.split('（')[0]
 address=[row[6],row[7],town]
 entries=groups.setdefault(code[:3],{}).setdefault(code,[])
 if address not in entries:entries.append(address)
out=Path(__file__).resolve().parents[1]/'assets/postal';out.mkdir(parents=True,exist_ok=True)
for old in out.glob('[0-9][0-9][0-9].json'):
 if old.stem not in groups:old.unlink()
for prefix,entries in groups.items():
 (out/(prefix+'.json')).write_text(json.dumps(entries,ensure_ascii=False,separators=(',',':')),encoding='utf-8')
(out/'manifest.json').write_text(json.dumps({'source':'https://www.post.japanpost.jp/service/search/zipcode/download/utf-zip.html','date':args.date,'sha256':hashlib.sha256(raw).hexdigest(),'postal_codes':sum(len(values) for values in groups.values())},ensure_ascii=False,indent=2),encoding='utf-8')
print(f'{len(groups)}分割ファイル、{sum(len(values) for values in groups.values())}郵便番号を生成しました')
