class OMF {
  constructor() {
    this.repeatField();
  }

  /**
   * クリックすると項目を追加する
   * @return {[type]} [description]
   */
  repeatField() {
    //リピートフィールドのカウントをセット
    this.repeatCount = this.getRepeatFieldCount();
    //項目の追加
    this.addRepeatFieldEvent();
    //項目の削除
    this.removeRepeatFieldEvent();

  }

  /**
   * リピートフィールドのカウントを取得
   * @return {int} カウント
   */
  getRepeatFieldCount(){
    let fieldCount = 1;
    const fields = document.querySelectorAll('.js-omf-repeat-field');
    if(!fields.length){
      return fieldCount;
    }

    //最後のフィールドを取得
    const lastField = fields[fields.length - 1];
    const lastCount = lastField.dataset.omfValidationCount;
    fieldCount = !lastCount ? fieldCount : Number(lastCount) + 1;

    return fieldCount;
  }

  /**
   * 項目の削除イベント追加
   */
  removeRepeatFieldEvent(){
    const repeatFieldRemoveButtons = document.querySelectorAll('.js-omf-remove-button');
    if(repeatFieldRemoveButtons.length){
      for(const el of repeatFieldRemoveButtons){
        el.addEventListener('click', (e) => {
          e.preventDefault();
          this.removeRepeatField(e);
        }, false);
      }
    }
  }

  /**
   * 特定の要素に対して項目の削除イベント追加
   * @param {HTMLElement} target
   */
  addRemoveEvent(target) {
    if(!target){
      return;
    }

    target.addEventListener('click', (e) => {
      e.preventDefault();
      this.removeRepeatField(e);
    }, false);
  }

  /**
   * 項目を削除
   * @param  {Object} e イベントオブジェクト
   */
  removeRepeatField(e) {
    const button = e.currentTarget;
    const target = button.closest('.js-omf-repeat-field');

    if(!target){
      return;
    }

    target.remove();
  } 

  /**
   * 項目の追加イベント追加
   */
  addRepeatFieldEvent() {
    const repeatFieldAddButton = document.querySelector('#js-omf-repeat-add-button');
    if(repeatFieldAddButton){
      repeatFieldAddButton.addEventListener('click', (e) => {
        e.preventDefault();

        this.addRepeatField(e);

      }, false);
    }
  }

  /**
   * 項目を追加
   * @param {Object} e イベントオブジェクト
   */
  addRepeatField(e){
    const targets = document.querySelectorAll('.js-omf-repeat-field');
    if(!targets.length){
      return;
    }

    const target = targets[targets.length - 1];
    const clone  = target.cloneNode(true);
    
    const elems = clone.querySelectorAll('[name^="cf_omf_validation"]');

    let counter = 0;
    for(const el of elems){

      el.name    = this.replaceBracketsWithText(el.name, this.repeatCount);
      el.value   = el.value == 1 ? 1 : '';
      el.checked = false;

      if(counter === elems.length - 1){
        this.repeatCount = this.extractBracketContents(el.name) + 1;
      }
      counter++;
    }

    //削除イベントを登録
    this.addRemoveEvent(clone.querySelector('.js-omf-remove-button'));

    target.insertAdjacentElement('afterend', clone);
  }

  /**
   * 正規表現でカウンターを置換
   * @param  {String} inputString 
   * @param  {String} newText 
   * @return {String}
   */
  replaceBracketsWithText(inputString, newText) {
    return inputString.replace(/(cf_omf_validation\[)(\d+)(\])/g, `$1${newText}$3`);
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

}

const omf = new OMF();