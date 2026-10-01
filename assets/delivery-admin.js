document.addEventListener('DOMContentLoaded',()=>{const inputs=document.querySelectorAll('[name=omf_delivery_mode]');const refresh=()=>{const selected=document.querySelector('[name=omf_delivery_mode]:checked');document.querySelectorAll('[data-delivery-note]').forEach(note=>note.hidden=note.dataset.deliveryNote!==selected?.value);};inputs.forEach(input=>input.addEventListener('change',refresh));refresh();});

document.querySelectorAll('.omf-cron-setup').forEach(panel=>{
  const feedback=panel.querySelector('.omf-cron-feedback');
  panel.querySelectorAll('[data-omf-cron-copy],[data-omf-cron-renew]').forEach(button=>button.addEventListener('click',async()=>{
    const renew=button.hasAttribute('data-omf-cron-renew');
    if(renew&&!window.confirm('登録用キーを再発行すると、現在のcron設定は使えなくなります。再発行して設定し直しますか？'))return;
    button.disabled=true;feedback.textContent='準備しています…';
    let failure='設定を取得できませんでした。画面を更新してやり直してください。';
    try{
      const response=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'omf_cron_setup',nonce:panel.dataset.omfCronNonce,kind:renew?'renew':button.dataset.omfCronCopy})});
      const result=await response.json();
      if(!response.ok||!result.success){failure=result.data?.message||failure;throw new Error();}
      if(renew){panel.querySelector('[data-omf-cron-probe]').textContent='未確認';feedback.textContent=result.data.message;}
      else{failure='コピーできませんでした。ブラウザのクリップボード権限とHTTPS接続を確認してください。';await navigator.clipboard.writeText(result.data.command);feedback.textContent=button.dataset.omfCronCopy==='probe_command'?'コピーしました。サーバーのコマンド実行画面かターミナルへ貼り付け、1回実行してください。':'コピーしました。サーバー側の設定欄へ貼り付けてください。';}
    }catch{feedback.textContent=failure;}
    finally{button.disabled=false;}
  }));
});
