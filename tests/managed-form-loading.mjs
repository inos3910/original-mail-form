// 実際の管理フォームJSを実行し、POST値の確定後に操作を止めることを検証する。
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import assert from 'node:assert/strict';
const source = readFileSync(new URL('../assets/managed-form.js', import.meta.url), 'utf8');
class Element {
  constructor(name = '', value = '', type = 'text') {
    this.name = name; this.value = value; this.type = type; this.disabled = false;
    this.dataset = {}; this.attrs = new Map(); this.listeners = new Map(); this.childNodes = [{textContent: name}];
  }
  addEventListener(type, callback) { this.listeners.set(type, [...(this.listeners.get(type) || []), callback]); }
  dispatchEvent(event) { this.listeners.get(event.type)?.forEach(callback => callback(event)); }
  getAttribute(name) { return this.attrs.get(name) ?? null; }
  setAttribute(name, value) { this.attrs.set(name, value); }
  removeAttribute(name) { this.attrs.delete(name); }
  get textContent() { return this.childNodes.map(node => node.textContent).join(''); }
  set textContent(value) { this.childNodes = [{textContent: value}]; }
  replaceChildren(...nodes) { this.childNodes = nodes; }
}
function environment() {
  const form = new Element(); form.inert = false; form.dataset.omfSubmitTimeout = '25'; form.resets = 0;
  const file = new Blob(['添付の試験'], {type:'text/plain'});
  const controls = [new Element('email', 'fixture@example.test'), new Element('choices[]', 'a', 'checkbox'), new Element('choices[]', 'b', 'checkbox'), new Element('file', file, 'file'), new Element('omf_token', 'fixture', 'hidden'), new Element('unused', '保持', 'text')];
  controls[5].disabled = true; controls[5].setAttribute('aria-disabled', 'true');
  const button = new Element('confirm', 'confirm', 'submit'); button.dataset.omfAction = 'confirm';
  const icon = {textContent:'アイコン'}; button.childNodes = [{textContent:'内容を確認'}, icon]; controls.push(button);
  form.querySelectorAll = selector => selector === '[data-omf-required-if-key]' ? [] : controls;
  form.reset = () => { form.resets++; };
  let status;
  form.after = element => { status = element; };
  const document = new Element();
  document.querySelectorAll = selector => selector.includes('[data-omf-step="entry"]') || selector === 'form[data-omf-form]' ? [form] : [];
  document.querySelector = () => form;
  document.createElement = () => new Element();
  const window = new Element(); const timers = new Map(); let nextTimer = 0; let reloads = 0;
  class Data {
    constructor(target, submitter) {
      this.entries = [];
      if (target) {
        for (const control of controls) if (!control.disabled && control.type !== 'submit') this.append(control.name, control.value);
        if (submitter && !submitter.disabled) this.append(submitter.name, submitter.value);
      }
    }
    append(name, value) { this.entries.push([name,value]); }
    delete(name) { this.entries = this.entries.filter(([key]) => key !== name); }
    keys() { return this.entries.map(([name]) => name); }
    get(name) { return this.entries.find(([key]) => key === name)?.[1]; }
    getAll(name) { return this.entries.filter(([key]) => key === name).map(([,value]) => value); }
    [Symbol.iterator]() { return this.entries[Symbol.iterator](); }
  }
  runInNewContext(source, {document, window, Event:class {constructor(type) {this.type=type;}}, location:{reload:() => reloads++}, setTimeout:(callback, delay) => {timers.set(++nextTimer,{callback,delay});return nextTimer;}, clearTimeout:id => timers.delete(id)});
  const submit = (prevented = false) => {
    const event = {type:'submit', target:form, submitter:button, defaultPrevented:prevented, preventDefault() {this.defaultPrevented=true;}};
    document.dispatchEvent(event); return event;
  };
  return {form, controls, button, icon, file, submit, Data, window, get status(){return status;}, get reloads(){return reloads;}, timer:delay => {for (const [id,timer] of timers) if(timer.delay===delay){timers.delete(id);timer.callback();}}};
}
let e = environment(); e.submit(true);
assert.equal(e.form.inert, false); assert(e.controls.slice(0,5).every(control => !control.disabled));
console.log('PASS: 検証で取り消したsubmitはロックしない');
e = environment(); const event = e.submit();
assert(e.controls.slice(0,5).every(control => !control.disabled)); assert.equal(e.button.disabled,false); assert.equal(e.form.inert,true); assert.equal(e.form.getAttribute('aria-busy'),'true'); assert.equal(e.button.textContent,'確認中…'); assert.equal(e.status.hidden,false);
// submitの既定動作でブラウザが値を確定した後に、タイマーのタスクを実行する。
const payload = new e.Data(e.form, e.button);
assert.equal(payload.get('email'),'fixture@example.test'); assert.deepEqual(payload.getAll('choices[]'),['a','b']); assert.equal(payload.get('file'),e.file); assert.equal(payload.get('omf_token'),'fixture'); assert.equal(payload.get('confirm'),'confirm'); assert.equal(payload.get('unused'),undefined);
e.timer(0); assert(e.controls.every(control => control.disabled));
console.log('PASS: POST値の確定後にdisabledへ切替。入力・複数選択・添付・トークン・操作名を退避せず送る');
assert.equal(e.submit().defaultPrevented,true);
console.log('PASS: 送信中の重複submitは取り消す');
e.timer(25);
assert.equal(e.form.inert,false); assert.equal(e.form.getAttribute('aria-busy'),null); assert.equal(e.form.dataset.omfState,undefined); assert.equal(e.button.textContent,'内容を確認アイコン'); assert.equal(e.button.childNodes[1],e.icon); assert.equal(e.controls[5].disabled,true); assert.equal(e.controls[5].getAttribute('aria-disabled'),'true'); assert(e.status.textContent.includes('送信済みの可能性')); assert(e.controls.slice(0,5).every(control => !control.disabled));
console.log('PASS: タイムアウトで操作・元のdisabled・ボタンのHTMLを復元し結果不明を通知');
e = environment(); e.submit(); e.timer(0); e.window.dispatchEvent({type:'pageshow',persisted:true});
assert.equal(e.form.inert,false); assert.equal(e.status.hidden,true); assert.equal(e.reloads,1); assert.equal(e.form.resets,0);
console.log('PASS: 履歴キャッシュの復帰でロックを解除して表示許可を再検証');
e = environment(); e.submit(); e.window.dispatchEvent({type:'pageshow',persisted:true}); e.timer(0);
assert(e.controls.slice(0,5).every(control => !control.disabled)); assert.equal(e.button.disabled,false);
console.log('PASS: disabledの予約前に復帰しても解除後の再ロックがない');
e = environment(); const cancelled = e.submit(); cancelled.preventDefault(); e.timer(0);
assert.equal(e.form.inert,false); assert.equal(e.status.hidden,true);
console.log('PASS: 後続の送信取消でもローディングを解除');
