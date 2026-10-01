document.querySelectorAll('[data-omf-mail-default]').forEach(button=>{
  button.addEventListener('click',()=>{
    ['title','mail'].forEach(part=>{
      const key=`cf_omf_${button.dataset.omfMailDefault}_${part}`,field=document.getElementById(key);
      if(field&&field.value.trim()===''){field.value=omfMailDefaults[key]||'';field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));}
    });
  });
});
