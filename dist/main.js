(()=>{var e={693:()=>{new class{constructor(){this.repeatField()}repeatField(){this.repeatCount=this.getRepeatFieldCount(),this.addRepeatFieldEvent(),this.removeRepeatFieldEvent(),this.toggleFieldEvent(),this.changeTitleEvent()}getRepeatFieldCount(){let e=1;const t=document.querySelectorAll(".js-omf-repeat-field");if(!t.length)return e;const n=t[t.length-1].dataset.omfValidationCount;return e=n?Number(n)+1:e,e}removeRepeatFieldEvent(){const e=document.querySelectorAll(".js-omf-remove");if(e.length)for(const t of e)this.addRemoveEvent(t)}addRemoveEvent(e){e&&e.addEventListener("click",(e=>{e.preventDefault(),this.removeRepeatField(e)}),!1)}removeRepeatField(e){const t=e.currentTarget.closest(".js-omf-repeat-field");t&&t.remove()}addRepeatFieldEvent(){const e=document.querySelector("#js-omf-repeat-add-button");e&&e.addEventListener("click",(e=>{e.preventDefault(),this.addRepeatField(e)}),!1)}addRepeatField(e){const t=document.querySelectorAll(".js-omf-repeat-field");if(!t.length)return;const n=t[t.length-1],o=n.cloneNode(!0),l=o.querySelectorAll('[name^="cf_omf_validation"]');let r=0;for(const e of l)e.name=this.replaceBracketsWithText(e.name,this.repeatCount),e.value=1==e.value?1:"",e.checked=!1,r===l.length-1&&(this.repeatCount=this.extractBracketContents(e.name)+1),r++;const i=o.querySelector(".js-omf-field-title");i&&(i.textContent=""),this.addChangeTitleEvent(o.querySelector(".js-omf-input-field-title")),this.addRemoveEvent(o.querySelector(".js-omf-remove")),this.addToggleFieldEvent(o.querySelector(".js-omf-toggle")),n.insertAdjacentElement("afterend",o)}replaceBracketsWithText(e,t){return e.replace(/(cf_omf_validation\[)(\d+)(\])/g,`$1${t}$3`)}extractBracketContents(e){const t=e.match(/cf_omf_validation\[(\d+)\]/);return t?Number(t[1]):null}toggleFieldEvent(){const e=document.querySelectorAll(".js-omf-toggle");if(e.length)for(const t of e)this.addToggleFieldEvent(t)}addToggleFieldEvent(e){e&&e.addEventListener("click",(e=>{e.preventDefault(),this.toggleField(e)}),!1)}toggleField(e){const t=e.currentTarget.closest(".js-omf-repeat-field");if(!t)return;const n=t.querySelector(".js-omf-toggle-field");n&&(n.classList.contains("open")?n.classList.remove("open"):n.classList.add("open"))}changeTitleEvent(){const e=document.querySelectorAll(".js-omf-input-field-title");if(e.length)for(const t of e)this.addChangeTitleEvent(t)}addChangeTitleEvent(e){e&&e.addEventListener("input",(e=>{e.preventDefault(),this.changeTitle(e)}),!1)}changeTitle(e){const t=e.currentTarget,n=t.closest(".js-omf-repeat-field");if(!n)return;const o=n.querySelector(".js-omf-field-title");o&&(o.textContent=t.value)}}}},t={};function n(o){var l=t[o];if(void 0!==l)return l.exports;var r=t[o]={exports:{}};return e[o](r,r.exports,n),r.exports}n.n=e=>{var t=e&&e.__esModule?()=>e.default:()=>e;return n.d(t,{a:t}),t},n.d=(e,t)=>{for(var o in t)n.o(t,o)&&!n.o(e,o)&&Object.defineProperty(e,o,{enumerable:!0,get:t[o]})},n.o=(e,t)=>Object.prototype.hasOwnProperty.call(e,t),(()=>{"use strict";n(693)})()})();
//# sourceMappingURL=main.js.map