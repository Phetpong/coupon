document.querySelector('#issue-form')?.addEventListener('submit', function (event) {
  const input = document.querySelector('#citizen_id');
  const value = input.value;
  let sum = 0;
  for (let i = 0; i < 12; i++) sum += Number(value[i]) * (13 - i);
  if (!/^[1-9][0-9]{12}$/.test(value) || (11 - sum % 11) % 10 !== Number(value[12])) {
    event.preventDefault();
    input.setCustomValidity('เลขบัตรประชาชนไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง');
    input.reportValidity();
    return;
  }
  const button = document.querySelector('#issue-button');
  button.disabled = true;
  button.textContent = 'กำลังบันทึก กรุณารอสักครู่…';
});
document.querySelector('#citizen_id')?.addEventListener('input', function () { this.setCustomValidity(''); });

let historyRequest, historyVersion = 0, historyTimer;
async function loadHistory(params, scroll = false, updateUrl = true) {
  historyRequest?.abort();
  const version = ++historyVersion;
  historyRequest = new AbortController();
  const section = document.querySelector('#history');
  if (!section) return;
  section.setAttribute('aria-busy', 'true');
  try {
    const response = await fetch('index.php?' + params, {signal:historyRequest.signal, cache:'no-store'});
    const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
    if (version !== historyVersion) return;
    const replacement = doc.querySelector('#history');
    if (!response.ok || !replacement || doc.querySelector('[role="alert"]')) throw new Error('โหลดประวัติไม่ได้ กรุณาลองใหม่ หรือตรวจสอบการเข้าสู่ระบบ');
    section.replaceWith(replacement);
    enhanceSelects(replacement);
    if (updateUrl) {
      const url=new URL('index.php',location.href);
      url.search=params.toString(); url.hash='history';
      if (url.href!==location.href) window.history.pushState(null,'',url);
    }
    if (scroll) replacement.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth',block:'start'});
  } catch (error) {
    if (error.name === 'AbortError' || version !== historyVersion) return;
    let message = section.querySelector('.history-error');
    if (!message) { message=document.createElement('p'); message.className='history-error'; message.setAttribute('role','alert'); section.prepend(message); }
    message.textContent=error.message;
  } finally { if(version===historyVersion) document.querySelector('#history')?.removeAttribute('aria-busy'); }
}
window.addEventListener('popstate',()=>{
  clearTimeout(historyTimer);
  loadHistory(new URL(location.href).searchParams,false,false);
});

// Shared dialog picker for all selects, including AJAX-replaced filters.
let pickerDialog, pickerTitle, pickerSearch, pickerList, activePicker;
let pickerSequence = 0;
const pickerSupported = typeof HTMLDialogElement !== 'undefined' && typeof HTMLDialogElement.prototype.showModal === 'function';
function pickerLabel(select) {
  if (select.name === 'per_page') return 'จำนวนรายการต่อหน้า';
  return ({group_name:'กลุ่มผู้เข้าร่วม','organization-picker':'ฟาร์ม / ซุ้ม / หน่วยงาน','filter-group':'กรองกลุ่มผู้เข้าร่วม','filter-organization':'กรองฟาร์ม / ซุ้ม / หน่วยงาน'})[select.id] || select.getAttribute('aria-label') || 'เลือกรายการ';
}
function renderPickerChoices() {
  if (!activePicker) return;
  const {select, sync} = activePicker;
  pickerList.replaceChildren();
  const term=pickerSearch.value.trim().toLocaleLowerCase('th');
  const farm=select.id==='organization-picker';
  const all=Array.from(select.options).filter(option=>!option.disabled && !option.hidden && !(select.required && option.value===''));
  const choices=all.filter(option=>option.value==='__new__' || option.value==='' || option.textContent.toLocaleLowerCase('th').includes(term));
  if (!choices.some(option=>option.value!=='' && option.value!=='__new__')) {
    const empty=document.createElement('p'); empty.className='muted'; empty.textContent=term?'ไม่พบรายการที่ค้นหา':'ยังไม่มีรายการให้เลือก'; pickerList.append(empty);
  }
  for (const option of choices) {
    const button=document.createElement('button'); button.type='button'; button.className='picker-choice';
    button.textContent=farm && option.value===''?'ไม่ระบุหน่วยงาน':option.textContent;
    button.setAttribute('aria-pressed',String(option.selected));
    button.addEventListener('click',()=>{
      if (!select.isConnected || select.disabled) { pickerDialog.close(); return; }
      select.value=option.value;
      sync();
      pickerDialog.close();
      select.dispatchEvent(new Event('change',{bubbles:true}));
    });
    pickerList.append(button);
  }
}
function openPicker(picker) {
  if (picker.select.disabled) return;
  activePicker=picker;
  pickerTitle.textContent=pickerLabel(picker.select);
  pickerSearch.value='';
  // Search is unnecessary for the three page-size choices.
  pickerSearch.hidden=picker.select.name==='per_page';
  renderPickerChoices();
  if (!pickerDialog.open) pickerDialog.showModal();
}
function enhanceSelects(root=document) {
  if (!pickerSupported) return;
  root.querySelectorAll('select:not([data-picker-ready])').forEach(select=>{
    select.dataset.pickerReady='true';
    const trigger=document.createElement('button'); trigger.type='button'; trigger.className='picker-trigger';
    trigger.id='picker-trigger-'+(++pickerSequence);
    trigger.setAttribute('aria-haspopup','dialog'); trigger.setAttribute('aria-controls','shared-picker-dialog');
    select.after(trigger);
    const labels=Array.from(select.labels || []);
    labels.forEach(label=>label.htmlFor=trigger.id);
    select.classList.add('enhanced-native'); select.tabIndex=-1; select.setAttribute('aria-hidden','true');
    function sync() {
      trigger.disabled=select.disabled;
      trigger.textContent=select.selectedOptions[0]?.textContent || 'เลือกรายการ';
      trigger.setAttribute('aria-label',pickerLabel(select)+': '+trigger.textContent);
      if (select.getAttribute('aria-invalid')==='true') trigger.setAttribute('aria-invalid','true');
      else if (select.validity.valid) trigger.removeAttribute('aria-invalid');
      if (select.hasAttribute('aria-describedby')) trigger.setAttribute('aria-describedby',select.getAttribute('aria-describedby'));
    }
    const picker={select,trigger,sync};
    trigger.addEventListener('click',()=>openPicker(picker));
    select.addEventListener('change',sync);
    select.addEventListener('invalid',event=>{
      event.preventDefault(); trigger.setAttribute('aria-invalid','true');
      if (!pickerDialog.open) openPicker(picker);
    });
    new MutationObserver(()=>{
      sync();
      if (pickerDialog.open && activePicker?.select===select) renderPickerChoices();
    }).observe(select,{childList:true,subtree:true,attributes:true,attributeFilter:['disabled','selected','hidden']});
    select.form?.addEventListener('reset',()=>setTimeout(sync,0));
    sync();
  });
}
document.addEventListener('click',event=>{
  const link=event.target.closest('[data-error-focus] a');
  if (!link) return;
  const field=document.getElementById(link.hash.slice(1));
  const target=field?.classList.contains('enhanced-native')?field.nextElementSibling:field;
  if (target) { event.preventDefault(); target.focus(); }
});
window.addEventListener('pageshow',()=>{
  requestAnimationFrame(()=>document.querySelector('[data-error-focus]')?.focus());
  const button=document.querySelector('#issue-button');
  if (button) { button.disabled=false; button.textContent='ยืนยันบันทึกและจ่ายคูปอง'; }
});
if (pickerSupported) {
  pickerDialog=document.createElement('dialog'); pickerDialog.id='shared-picker-dialog'; pickerDialog.className='picker-dialog';
  pickerDialog.setAttribute('aria-labelledby','shared-picker-title');
  const heading=document.createElement('div'); heading.className='picker-dialog-heading';
  pickerTitle=document.createElement('h2'); pickerTitle.id='shared-picker-title';
  const close=document.createElement('button'); close.type='button'; close.textContent='ปิด'; close.className='secondary'; close.autofocus=true;
  heading.append(pickerTitle,close);
  pickerSearch=document.createElement('input'); pickerSearch.type='search'; pickerSearch.placeholder='ค้นหารายการ'; pickerSearch.setAttribute('aria-label','ค้นหารายการ');
  pickerList=document.createElement('div'); pickerList.className='picker-choices';
  pickerDialog.append(heading,pickerSearch,pickerList); document.body.append(pickerDialog);
  close.addEventListener('click',()=>pickerDialog.close());
  pickerDialog.addEventListener('close',()=>{
    // WebKit may return focus to body when a pointer opened the picker.
    if (activePicker?.trigger.isConnected && !activePicker.trigger.disabled &&
        (document.activeElement===document.body || pickerDialog.contains(document.activeElement))) {
      activePicker.trigger.focus({preventScroll:true});
    }
  });
  pickerSearch.addEventListener('input',renderPickerChoices);
  enhanceSelects();
  window.addEventListener('pageshow',()=>document.querySelectorAll('select[data-picker-ready]').forEach(select=>{
    // Restore the label after back/forward navigation without firing business actions.
    const trigger=select.nextElementSibling;
    if(trigger?.classList.contains('picker-trigger')) {
      trigger.textContent=select.selectedOptions[0]?.textContent || 'เลือกรายการ';
      trigger.disabled=select.disabled;
    }
  }));
}
document.addEventListener('submit', event => {
  if (event.target.id !== 'history-filters' && !event.target.classList.contains('history-page-form')) return;
  event.preventDefault(); clearTimeout(historyTimer);
  loadHistory(new URLSearchParams(new FormData(event.target)),event.target.classList.contains('history-page-form'));
});
document.addEventListener('click', event => {
  const link=event.target.closest('.history-nav');
  if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
  event.preventDefault(); clearTimeout(historyTimer);
  loadHistory(new URL(link.href).searchParams,true);
});
document.addEventListener('change', event => {
  if(event.target.name==='per_page' && event.target.form?.classList.contains('page-controls')) {
    event.target.form.elements.page.value=1;
    event.target.form.requestSubmit();
    return;
  }
  if(!['filter-group','filter-organization'].includes(event.target.id)) return;
  if(event.target.id==='filter-group') document.querySelector('#filter-organization').value='';
  clearTimeout(historyTimer);
  loadHistory(new URLSearchParams(new FormData(event.target.form)));
});

const groupInput = document.querySelector('#group_name');
const organizationInput = document.querySelector('#organization');
const countOutput = document.querySelector('#organization-count');
let countTimer, countRequest, countVersion = 0;
function updateOrganizationCount() {
  clearTimeout(countTimer);
  countRequest?.abort();
  const version = ++countVersion;
  const group = groupInput.value;
  const organization = organizationInput.value.trim();
  countOutput.hidden = !group || !organization;
  if (countOutput.hidden) return;
  countOutput.textContent = 'กำลังตรวจสอบจำนวน…';
  countTimer = setTimeout(async () => {
    countRequest = new AbortController();
    try {
      const response = await fetch('organization-count.php?' + new URLSearchParams({group, organization}), {signal:countRequest.signal, cache:'no-store'});
      const data = await response.json();
      if (version !== countVersion) return;
      if (!response.ok) throw new Error(data.error || 'ตรวจสอบจำนวนไม่ได้');
      countOutput.textContent = `รับแล้ว ${data.count}${data.quota ? ' / ' + data.quota : ''} คน` + (data.quota && data.count >= data.quota ? ' · ครบจำนวนแล้ว' : '');
    } catch (error) {
      if (error.name !== 'AbortError' && version === countVersion) countOutput.textContent = error.message || 'ตรวจสอบจำนวนไม่ได้';
    }
  }, 300);
}
if (groupInput && organizationInput && countOutput) {
  const viewMembers=document.querySelector('#view-members');
  function currentMembers(scroll=false) {
    clearTimeout(historyTimer);
    historyRequest?.abort();
    ++historyVersion;
    viewMembers.hidden=!groupInput.value;
    const params=new URLSearchParams({filter_group:groupInput.value,filter_organization:organizationInput.value.trim(),per_page:document.querySelector('#history-filters [name="per_page"]')?.value || '10'});
    if(scroll) loadHistory(params,true);
    else historyTimer=setTimeout(() => loadHistory(params),350);
  }
  groupInput.addEventListener('change', () => { organizationInput.value=''; currentMembers(); });
  organizationInput.addEventListener('input', () => currentMembers());
  organizationInput.addEventListener('change', () => currentMembers());
  viewMembers.addEventListener('click', () => currentMembers(true));
  const options = document.querySelector('#organization-picker');
  const entry = document.querySelector('#organization-entry');
  const source = document.querySelector('#organization-source');
  function updateOrganizationOptions() {
    if (!options || !source) return;
    const names=Array.from(source.content.querySelectorAll('option')).filter(option => option.dataset.group === groupInput.value).map(option => option.value);
    options.replaceChildren(new Option(!groupInput.value?'เลือกกลุ่มก่อน':names.length?'เลือกหน่วยงาน':'ยังไม่มีหน่วยงานในกลุ่มนี้',''));
    names.forEach(name=>options.add(new Option(name,name)));
    if(groupInput.value) options.add(new Option('+ เพิ่มชื่อหน่วยงานใหม่','__new__'));
    options.hidden=false;
    options.disabled=!groupInput.value;
    const value=organizationInput.value;
    options.value=names.includes(value)?value:(value?'__new__':'');
    entry.hidden=options.value!=='__new__';
  }
  options.addEventListener('change',()=>{
    const adding=options.value==='__new__';
    entry.hidden=!adding;
    organizationInput.value=adding?'':options.value;
    organizationInput.dispatchEvent(new Event('change',{bubbles:true}));
    if(adding) organizationInput.focus();
  });
  groupInput.addEventListener('change', () => {
    organizationInput.value = '';
    updateOrganizationOptions();
    updateOrganizationCount();
  });
  organizationInput.addEventListener('input', updateOrganizationCount);
  organizationInput.addEventListener('change', updateOrganizationCount);
  updateOrganizationOptions();
  updateOrganizationCount();
  window.addEventListener('pageshow', () => { updateOrganizationOptions(); updateOrganizationCount(); });
}
