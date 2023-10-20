/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./src/sass/style.scss":
/*!*****************************!*\
  !*** ./src/sass/style.scss ***!
  \*****************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ })

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry need to be wrapped in an IIFE because it need to be isolated against other modules in the chunk.
(() => {
/*!************************!*\
  !*** ./src/js/main.js ***!
  \************************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _sass_style_scss__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../sass/style.scss */ "./src/sass/style.scss");
var __defProp = Object.defineProperty;
var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
var __publicField = (obj, key, value) => {
  __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);
  return value;
};

class OMF {
  constructor() {
    //ドラッグ開始
    __publicField(this, "handleDragStart", (e) => {
      e.currentTarget.style.opacity = "0.4";
      this.dragSrcEl = e.currentTarget;
      e.dataTransfer.effectAllowed = "move";
      e.dataTransfer.setData("text/html", this.dragSrcEl);
    });
    //ドラッグ終了
    __publicField(this, "handleDragEnd", (e) => {
      e.currentTarget.style.opacity = "1";
      const items = document.querySelectorAll(".js-omf-repeat-field");
      if (!items.length) {
        return;
      }
      items.forEach((item) => {
        item.classList.remove("over");
      });
    });
    //ドラッグ対象がドロップ対象上にある時
    __publicField(this, "handleDragOver", (e) => {
      if (e.preventDefault) {
        e.preventDefault();
      }
      return false;
    });
    //ドラッグ対象がドロップ対象に入った時
    __publicField(this, "handleDragEnter", (e) => {
      e.currentTarget.classList.add("over");
    });
    //ドラッグ対象がドロップ対象から離れた時
    __publicField(this, "handleDragLeave", (e) => {
      e.currentTarget.classList.remove("over");
    });
    //ドラッグ対象がドロップ対象にドロップされた時
    __publicField(this, "handleDrop", (e) => {
      e.stopPropagation();
      if (this.dragSrcEl !== e.currentTarget) {
        const dragIndex = Number(this.dragSrcEl.dataset.omfValidationCount);
        const targetIndex = Number(e.currentTarget.dataset.omfValidationCount);
        const position = dragIndex < targetIndex ? "afterend" : "beforebegin";
        const newField = e.currentTarget.insertAdjacentElement(
          position,
          this.dragSrcEl
        );
        this.updateFieldIndex();
      }
      return false;
    });
    this.repeatField();
  }
  /**
   * クリックすると項目を追加する
   * @return {[type]} [description]
   */
  repeatField() {
    this.repeatCount = this.getRepeatFieldCount();
    this.addRepeatFieldEvent();
    this.removeRepeatFieldEvent();
    this.toggleFieldEvent();
    this.changeTitleEvent();
    this.dragEvent();
  }
  /**
   * リピートフィールドのカウントを取得
   * @return {int} カウント
   */
  getRepeatFieldCount() {
    let fieldCount2 = 1;
    const fields = document.querySelectorAll(".js-omf-repeat-field");
    if (!fields.length) {
      return fieldCount2;
    }
    const lastField = fields[fields.length - 1];
    const lastCount = lastField.dataset.omfValidationCount;
    fieldCount2 = !lastCount ? fieldCount2 : Number(lastCount) + 1;
    return fieldCount2;
  }
  /**
   * 項目の削除イベント追加
   */
  removeRepeatFieldEvent() {
    const repeatFieldRemoveButtons = document.querySelectorAll(".js-omf-remove");
    if (repeatFieldRemoveButtons.length) {
      for (const el of repeatFieldRemoveButtons) {
        this.addRemoveEvent(el);
      }
    }
  }
  /**
   * 特定の要素に対して項目の削除イベント追加
   * @param {HTMLElement} target
   */
  addRemoveEvent(target) {
    if (!target) {
      return;
    }
    target.addEventListener(
      "click",
      (e) => {
        e.preventDefault();
        this.removeRepeatField(e);
      },
      false
    );
  }
  /**
   * 項目を削除
   * @param  {Object} e イベントオブジェクト
   */
  removeRepeatField(e) {
    const button = e.currentTarget;
    const target = button.closest(".js-omf-repeat-field");
    if (!target) {
      return;
    }
    target.remove();
  }
  /**
   * 項目の追加イベント追加
   */
  addRepeatFieldEvent() {
    const repeatFieldAddButton = document.querySelector(
      "#js-omf-repeat-add-button"
    );
    if (repeatFieldAddButton) {
      repeatFieldAddButton.addEventListener(
        "click",
        (e) => {
          e.preventDefault();
          this.addRepeatField(e);
        },
        false
      );
    }
  }
  /**
   * 項目を追加
   * @param {Object} e イベントオブジェクト
   */
  addRepeatField(e) {
    const targets = document.querySelectorAll(".js-omf-repeat-field");
    if (!targets.length) {
      return;
    }
    const target = targets[targets.length - 1];
    const clone = target.cloneNode(true);
    const insertedElem = target.insertAdjacentElement("afterend", clone);
    const elems = insertedElem.querySelectorAll('[name^="cf_omf_validation"]');
    let counter = 0;
    for (const el of elems) {
      el.name = this.replaceBracketsWithText(el.name, this.repeatCount);
      el.value = el.value == 1 ? 1 : "";
      el.setAttribute("value", el.value);
      el.checked = false;
      el.removeAttribute("checked");
      if (counter === elems.length - 1) {
        this.repeatCount = this.extractBracketContents(el.name) + 1;
      }
      counter++;
    }
    const fieldTitle = insertedElem.querySelector(".js-omf-field-title");
    if (fieldTitle) {
      fieldTitle.textContent = "";
    }
    this.addChangeTitleEvent(
      insertedElem.querySelector(".js-omf-input-field-title")
    );
    this.addRemoveEvent(insertedElem.querySelector(".js-omf-remove"));
    this.addToggleFieldEvent(insertedElem.querySelector(".js-omf-toggle"));
    this.addDragEvent(insertedElem);
  }
  /**
   * 正規表現でカウンターを置換
   * @param  {String} inputString
   * @param  {String} newText
   * @return {String}
   */
  replaceBracketsWithText(inputString, newText) {
    return inputString.replace(
      /(cf_omf_validation\[)(\d+)(\])/g,
      `$1${newText}$3`
    );
  }
  /**
   * 正規表現でカウンターを取得
   * @param  {String} inputString
   * @return {String}
   */
  extractBracketContents(inputString) {
    const match = inputString.match(/cf_omf_validation\[(\d+)\]/);
    return match ? Number(match[1]) : null;
  }
  /**
   * 項目の開閉イベント追加
   */
  toggleFieldEvent() {
    const toggleFieldButtons = document.querySelectorAll(".js-omf-toggle");
    if (!toggleFieldButtons.length) {
      return;
    }
    for (const el of toggleFieldButtons) {
      this.addToggleFieldEvent(el);
    }
  }
  /**
   * 特定の要素に対して項目の開閉イベント追加
   * @param {HTMLElement} target
   */
  addToggleFieldEvent(target) {
    if (!target) {
      return;
    }
    target.addEventListener(
      "click",
      (e) => {
        e.preventDefault();
        this.toggleField(e);
      },
      false
    );
  }
  /**
   * 項目を開閉
   * @param {Object} e イベントオブジェクト
   */
  toggleField(e) {
    const button = e.currentTarget;
    const repeatField = button.closest(".js-omf-repeat-field");
    if (!repeatField) {
      return;
    }
    const target = repeatField.querySelector(".js-omf-toggle-field");
    if (!target) {
      return;
    }
    if (target.classList.contains("open")) {
      target.classList.remove("open");
    } else {
      target.classList.add("open");
    }
  }
  /**
   * 特定の要素に対して項目のタイトル入力時にフィールドタイトルが連動して変わるイベント
   */
  changeTitleEvent() {
    const toggleFieldButtons = document.querySelectorAll(
      ".js-omf-input-field-title"
    );
    if (!toggleFieldButtons.length) {
      return;
    }
    for (const el of toggleFieldButtons) {
      this.addChangeTitleEvent(el);
    }
  }
  /**
   * 特定の要素に対して項目のタイトル入力時にフィールドタイトルが連動して変わるイベントを追加
   * @param {HTMLElement} target
   */
  addChangeTitleEvent(target) {
    if (!target) {
      return;
    }
    target.addEventListener(
      "input",
      (e) => {
        e.preventDefault();
        this.changeTitle(e);
      },
      false
    );
    target.addEventListener(
      "blur",
      (e) => {
        e.currentTarget.setAttribute("value", e.currentTarget.value);
      },
      false
    );
  }
  /**
   * 項目のタイトル入力時にフィールドタイトルが連動して変更する
   * @param {Object} e イベントオブジェクト
   */
  changeTitle(e) {
    const input = e.currentTarget;
    const repeatField = input.closest(".js-omf-repeat-field");
    if (!repeatField) {
      return;
    }
    const target = repeatField.querySelector(".js-omf-field-title");
    if (!target) {
      return;
    }
    target.textContent = input.value;
  }
  /**
   * ドラッグ＆ドロップで並び替えるイベント
   */
  dragEvent() {
    const items = document.querySelectorAll(".js-omf-repeat-field");
    if (!items.length) {
      return;
    }
    this.dragSrcEl = null;
    items.forEach((item) => {
      this.addDragEvent(item);
    });
  }
  /**
   * ドラッグ＆ドロップで並び替えるイベントを追加
   */
  addDragEvent(el) {
    el.addEventListener("dragstart", this.handleDragStart);
    el.addEventListener("dragover", this.handleDragOver);
    el.addEventListener("dragenter", this.handleDragEnter);
    el.addEventListener("dragleave", this.handleDragLeave);
    el.addEventListener("dragend", this.handleDragEnd);
    el.addEventListener("drop", this.handleDrop);
  }
  //フィールドの連番を振り直す
  updateFieldIndex() {
    const fields = document.querySelectorAll(".js-omf-repeat-field");
    if (!fields.length) {
      return fieldCount;
    }
    let count = 0;
    for (const field of fields) {
      field.dataset.omfValidationCount = count;
      const elems = field.querySelectorAll('[name^="cf_omf_validation"]');
      for (const el of elems) {
        el.name = this.replaceBracketsWithText(el.name, count);
      }
      count++;
    }
  }
}
const omf = new OMF();

})();

/******/ })()
;
//# sourceMappingURL=main.js.map