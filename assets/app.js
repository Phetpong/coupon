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
async function loadHistory(params, scroll = false) {
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
    if (scroll) replacement.scrollIntoView({behavior:'smooth',block:'start'});
  } catch (error) {
    if (error.name === 'AbortError' || version !== historyVersion) return;
    let message = section.querySelector('.history-error');
    if (!message) { message=document.createElement('p'); message.className='history-error'; message.setAttribute('role','alert'); section.prepend(message); }
    message.textContent=error.message;
  } finally { if(version===historyVersion) document.querySelector('#history')?.removeAttribute('aria-busy'); }
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

// A large, searchable picker avoids tiny browser-owned option menus.
const farmSelect = document.querySelector('#organization-picker');
if (farmSelect && typeof HTMLDialogElement !== 'undefined' && typeof HTMLDialogElement.prototype.showModal === 'function') {
  const trigger = document.createElement('button');
  trigger.type = 'button'; trigger.className = 'organization-trigger';
  trigger.setAttribute('aria-haspopup','dialog');
  trigger.setAttribute('aria-controls','organization-dialog');
  const dialog = document.createElement('dialog');
  dialog.id = 'organization-dialog'; dialog.className = 'organization-dialog';
  dialog.setAttribute('aria-labelledby','organization-dialog-title');
  const heading = document.createElement('div'); heading.className='organization-dialog-heading';
  const title = document.createElement('h2'); title.id='organization-dialog-title'; title.textContent='เลือกฟาร์ม / ซุ้ม / หน่วยงาน';
  const close = document.createElement('button'); close.type='button'; close.textContent='ปิด'; close.className='secondary'; close.autofocus=true;
  heading.append(title,close);
  const search = document.createElement('input'); search.type='search'; search.placeholder='ค้นหาชื่อหน่วยงาน'; search.setAttribute('aria-label','ค้นหาชื่อหน่วยงาน');
  const list = document.createElement('div'); list.className='organization-choices';
  dialog.append(heading,search,list); document.body.append(dialog);
  farmSelect.after(trigger); farmSelect.classList.add('enhanced-native');
  document.querySelector('label[for="organization-picker"]')?.setAttribute('for','organization-trigger');
  trigger.id='organization-trigger';
  function syncTrigger() {
    trigger.disabled=farmSelect.disabled;
    trigger.textContent=farmSelect.selectedOptions[0]?.textContent || 'เลือกหน่วยงาน';
    trigger.setAttribute('aria-label','ชื่อฟาร์ม / ซุ้ม / หน่วยงาน: '+trigger.textContent);
  }
  function renderChoices() {
    list.replaceChildren();
    const term=search.value.trim().toLocaleLowerCase('th');
    const options=Array.from(farmSelect.options).filter(option=>option.value && option.value!=='__new__' && option.textContent.toLocaleLowerCase('th').includes(term));
    if(!options.length) {
      const empty=document.createElement('p'); empty.className='muted'; empty.textContent=term?'ไม่พบชื่อที่ค้นหา':'ยังไม่มีหน่วยงานในกลุ่มนี้'; list.append(empty);
    }
    const choices=[...options,{value:'__new__',textContent:'+ เพิ่มชื่อหน่วยงานใหม่'},{value:'',textContent:'ไม่ระบุหน่วยงาน'}];
    for(const option of choices) {
      const button=document.createElement('button'); button.type='button'; button.className='organization-choice'; button.textContent=option.textContent;
      button.setAttribute('aria-pressed',String(option.value===farmSelect.value));
      button.addEventListener('click',()=>{
        farmSelect.value=option.value; dialog.close();
        farmSelect.dispatchEvent(new Event('change',{bubbles:true})); syncTrigger();
      });
      list.append(button);
    }
  }
  trigger.addEventListener('click',()=>{ search.value=''; renderChoices(); dialog.showModal(); });
  close.addEventListener('click',()=>dialog.close());
  search.addEventListener('input',renderChoices);
  farmSelect.addEventListener('change',syncTrigger);
  new MutationObserver(syncTrigger).observe(farmSelect,{childList:true,subtree:true,attributes:true});
  syncTrigger();
}
