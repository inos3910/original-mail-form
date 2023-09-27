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
    //項目の開閉
    this.toggleFieldEvent();
    //特定の要素に対して項目のタイトル入力時にフィールドタイトルが連動して変える
    this.changeTitleEvent();
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
    const repeatFieldRemoveButtons = document.querySelectorAll('.js-omf-remove');
    if(repeatFieldRemoveButtons.length){
      for(const el of repeatFieldRemoveButtons){
        this.addRemoveEvent(el);
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

    //タイトルを空にする
    const fieldTitle = clone.querySelector('.js-omf-field-title');
    if(fieldTitle){
      fieldTitle.textContent = '';
    }

    //タイトル変更イベントを追加
    this.addChangeTitleEvent(clone.querySelector('.js-omf-input-field-title'));
    //削除イベントを登録
    this.addRemoveEvent(clone.querySelector('.js-omf-remove'));
    //開閉イベントを登録
    this.addToggleFieldEvent(clone.querySelector('.js-omf-toggle'));

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


  /**
   * 項目の開閉イベント追加
   */
  toggleFieldEvent() {
    const toggleFieldButtons = document.querySelectorAll('.js-omf-toggle');
    if(!toggleFieldButtons.length){
      return;
    }

    for(const el of toggleFieldButtons){
      this.addToggleFieldEvent(el);
    }
  }


  /**
   * 特定の要素に対して項目の開閉イベント追加
   * @param {HTMLElement} target
   */
  addToggleFieldEvent(target) {
    if(!target){
      return;
    }

    target.addEventListener('click', (e) => {
      e.preventDefault();
      this.toggleField(e);
    }, false);
  }

  /**
   * 項目を開閉
   * @param {Object} e イベントオブジェクト
   */
  toggleField(e) {
    const button = e.currentTarget;
    const repeatField = button.closest('.js-omf-repeat-field');
    if(!repeatField){
      return;
    }

    const target = repeatField.querySelector('.js-omf-toggle-field');
    if(!target){
      return;
    }

    if(target.classList.contains('open')){
      target.classList.remove('open');
    }
    else{
      target.classList.add('open');
    }
    
  }

  /**
   * 特定の要素に対して項目のタイトル入力時にフィールドタイトルが連動して変わるイベント
   */
  changeTitleEvent() {
    const toggleFieldButtons = document.querySelectorAll('.js-omf-input-field-title');
    if(!toggleFieldButtons.length){
      return;
    }

    for(const el of toggleFieldButtons){
      this.addChangeTitleEvent(el);
    }
  }


  /**
   * 特定の要素に対して項目のタイトル入力時にフィールドタイトルが連動して変わるイベントを追加
   * @param {HTMLElement} target
   */
  addChangeTitleEvent(target) {
    if(!target){
      return;
    }

    target.addEventListener('input', (e) => {
      e.preventDefault();
      this.changeTitle(e);
    }, false);
  }


  /**
   * 項目のタイトル入力時にフィールドタイトルが連動して変更する
   * @param {Object} e イベントオブジェクト
   */
  changeTitle(e){
    const input = e.currentTarget;
    const repeatField = input.closest('.js-omf-repeat-field');
    if(!repeatField){
      return;
    }

    const target = repeatField.querySelector('.js-omf-field-title');
    if(!target){
      return;
    }

    target.textContent = input.value;
  }



}

const omf = new OMF();