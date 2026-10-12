/* RotaQR - Vanilla JavaScript. No build tools or external services required. */
(() => {
  'use strict';
  const $ = (selector, parent = document) => parent.querySelector(selector);
  const $$ = (selector, parent = document) => [...parent.querySelectorAll(selector)];
  const paths = {
    qr: '<rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="3" width="6" height="6" rx="1"/><rect x="3" y="15" width="6" height="6" rx="1"/><path d="M15 15h6v6h-6v-3h3M12 3v6M3 12h6M12 15v6"/>',
    route: '<circle cx="6" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="M9 6h8a4 4 0 0 1 0 8H7a4 4 0 0 0 0 8"/>',
    external: '<path d="M15 3h6v6M10 14 21 3M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
    lock: '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4M12 14v3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>',
    shield: '<path d="m12 3 8 4v6c0 5-8 9-8 9s-8-4-8-9V7l8-4Z"/><path d="m8 12 3 3 5-6"/>',
    arrow: '<path d="M4 12h16m-6-6 6 6-6 6"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M9 12h12m-4-4 4 4-4 4"/>',
    eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
    download: '<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
    copy: '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
    edit: '<path d="m15 5 4 4M4 20l4-1L20 7a2.83 2.83 0 0 0-4-4L4 15l-1 6 5-2"/>',
    trash: '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    link: '<path d="m10 13 4-4M8 16l-2 2a4.24 4.24 0 0 1-6-6l4-4a4.24 4.24 0 0 1 6 0M14 8l2-2a4.24 4.24 0 0 1 6 6l-4 4a4.24 4.24 0 0 1-6 0"/>',
  };
  function icon(name) { return `<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.link}</svg>`; }
  function icons(root = document) { $$('[data-icon]', root).forEach(el => { el.innerHTML = icon(el.dataset.icon); }); }
  function esc(value) { return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch])); }
  let toastTimer;
  function toast(message) {
    const el = $('#toast'); el.textContent = message; el.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { el.hidden = true; }, 5000);
  }
  function formError(form, message = '') {
    const el = $('.form-error', form); if (el) { el.textContent = message; el.hidden = !message; }
  }
  async function api(action, values, page = 1) {
    const options = {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}};
    let url = `api.php?action=${encodeURIComponent(action)}&page=${page}`;
    if (values !== undefined) {
      options.method = 'POST'; options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = $('meta[name="csrf-token"]').content;
      options.body = JSON.stringify({action, ...values}); url = 'api.php';
    }
    let response;
    try { response = await fetch(url, options); } catch (_) { throw new Error('Sem conexão. Confira sua internet e tente novamente.'); }
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('O servidor não respondeu como esperado. Tente novamente.'); }
    if (!response.ok) {
      if (response.status === 401 && document.body.dataset.screen !== 'login') {
        toast('Sua sessão terminou. Faça login novamente.');
        setTimeout(() => location.reload(), 1800);
      }
      if (data.password_required) openPassword(true);
      throw new Error(data.error || 'Não foi possível concluir esta ação.');
    }
    return data;
  }
  async function busy(button, callback) {
    if (button.disabled) return;
    const content = button.innerHTML;
    button.disabled = true; button.setAttribute('aria-busy','true'); button.innerHTML = `${icon('route')} Aguarde…`;
    try { return await callback(); } finally { button.disabled = false; button.removeAttribute('aria-busy'); button.innerHTML = content; }
  }
  async function copy(text) {
    try {
      if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
      else {
        const area = document.createElement('textarea'); area.value = text; area.className = 'copy-helper';
        const parent = $('dialog[open]') || document.body; parent.appendChild(area); area.select();
        const ok = document.execCommand('copy'); area.remove(); if (!ok) throw new Error();
      }
      toast('Link fixo copiado!');
    } catch (_) { toast('Não foi possível copiar. Selecione e copie o endereço exibido.'); }
  }
  let returnFocus = null;
  function openDialog(id) {
    const dialog = $(id); returnFocus = document.activeElement; dialog.showModal();
  }
  $$('dialog').forEach(dialog => dialog.addEventListener('close', () => {
    if (returnFocus instanceof HTMLElement && returnFocus.isConnected) returnFocus.focus();
  }));
  document.addEventListener('click', event => {
    const close = event.target.closest('[data-close]'); if (close) close.closest('dialog').close();
    const toggle = event.target.closest('[data-password-toggle]');
    if (toggle) {
      const input = document.getElementById(toggle.dataset.passwordToggle); const showing = input.type === 'password';
      input.type = showing ? 'text' : 'password'; toggle.setAttribute('aria-pressed', String(showing)); toggle.setAttribute('aria-label', showing ? 'Ocultar senha' : 'Mostrar senha');
    }
  });
  icons();

  const login = $('#login-form');
  if (login) {
    login.addEventListener('submit', event => {
      event.preventDefault(); formError(login);
      busy($('[type=submit]', login), async () => {
        try { await api('login', Object.fromEntries(new FormData(login))); location.reload(); }
        catch (error) { formError(login, error.message); }
      });
    });
    return;
  }
  if (!$('#links-container')) return;
  const admin = document.body.dataset.role === 'admin';
  let links = [], users = [], linkPage = 1, userPage = 1, selectedQr = null, selectedDelete = null;
  let passwordRequired = document.body.dataset.passwordRequired === '1';

  function getQR(url) { const code = qrcode(0, 'H'); code.addData(url, 'Byte'); code.make(); return code; }
  function qrSvg(url) {
    const qr = getQR(url), n = qr.getModuleCount(), size = n+8;
    let path = '';
    for (let row=0; row<n; row++) for (let col=0; col<n; col++) if (qr.isDark(row,col)) path += `M${col+4} ${row+4}h1v1h-1z`;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" width="${size}" height="${size}" shape-rendering="crispEdges"><rect width="${size}" height="${size}" fill="#fff"/><path d="${path}" fill="#000"/></svg>`;
  }
  function slug(name) { return name.normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-zA-Z0-9_-]+/g,'-').replace(/^-|-$/g,'').slice(0,70) || 'qr-code'; }
  function download(blob, filename) {
    const url = URL.createObjectURL(blob), anchor = document.createElement('a'); anchor.href=url; anchor.download=filename;
    document.body.appendChild(anchor); anchor.click(); anchor.remove(); setTimeout(()=>URL.revokeObjectURL(url),10000);
  }
  function pagination(element, page, pages, callback) {
    element.hidden = pages <= 1;
    element.innerHTML = `<button class="button secondary compact" ${page<=1?'disabled':''} data-prev>Anterior</button><span>Página ${page} de ${pages}</span><button class="button secondary compact" ${page>=pages?'disabled':''} data-next>Próxima</button>`;
    $('[data-prev]',element).onclick = ()=>callback(page-1);
    $('[data-next]',element).onclick = ()=>callback(page+1);
  }
  async function loadLinks(page = linkPage) {
    try {
      const data = await api('links.list',undefined,page); links=data.links; linkPage=data.page;
      $('#link-count').textContent = data.total;
      $('#links-meta').textContent = data.total ? 'Edite o destino sem gerar outro QR Code' : 'Seu primeiro link começa aqui';
      const container = $('#links-container');
      if (!links.length) container.innerHTML = `<div class="empty-state"><div class="empty-icon">${icon('qr')}</div><h3>Um QR Code. O destino que você quiser.</h3><p>Crie seu primeiro link e baixe o QR para usar em materiais impressos, telas ou onde sua ideia chegar.</p><button class="button primary" data-new-link>${icon('plus')}Criar meu primeiro link</button><div class="empty-steps"><span><b>01</b> Cole o destino</span><span><b>02</b> Baixe o QR Code</span><span><b>03</b> Atualize quando quiser</span></div></div>`;
      else container.innerHTML = `<div class="links-table"><div class="table-head"><span>LINK / QR CODE</span><span>DESTINO ATUAL</span><span>STATUS</span><span>AÇÕES</span></div>${links.map(link => `<article class="link-row"><div class="link-identity"><button class="qr-thumb" data-qr="${link.id}" aria-label="Ver QR Code de ${esc(link.name)}">${qrSvg(link.url)}</button><div class="link-description"><h3>${esc(link.name)}</h3><button class="fixed-link" data-copy="${link.id}" title="Copiar link fixo">${esc(link.url.replace(/^https?:\/\//,''))}${icon('copy')}</button>${admin?`<small class="owner-label">${esc(link.owner_name)}</small>`:''}</div></div><div class="destination"><span class="mobile-label">DESTINO ATUAL</span><a href="${esc(link.destination)}" target="_blank" rel="noopener noreferrer" title="${esc(link.destination)}">${esc(link.destination.replace(/^https?:\/\//,''))}${icon('external')}</a></div><div class="row-status"><span class="status ${link.active?'active':'paused'}">${link.active?'Ativo':'Pausado'}</span></div><div class="row-actions"><button class="button secondary compact" data-edit="${link.id}">${icon('edit')}Editar</button><button class="icon-button bordered" data-qr="${link.id}" aria-label="Baixar QR Code de ${esc(link.name)}" title="Baixar QR Code">${icon('download')}</button><button class="icon-button delete-button" data-delete="${link.id}" aria-label="Excluir ${esc(link.name)}" title="Excluir link">${icon('trash')}</button></div></article>`).join('')}</div>`;
      pagination($('#links-pagination'),data.page,data.pages,loadLinks);
    } catch (error) {
      $('#links-container').innerHTML = `<div class="load-error" role="alert"><p>${esc(error.message)}</p><button class="button secondary" data-retry-links>Tentar novamente</button></div>`;
    }
  }
  function openLink(id) {
    const form=$('#link-form'), link=links.find(item=>item.id===id); form.reset(); formError(form);
    form.elements.id.value=link?.id || ''; form.elements.name.value=link?.name || ''; form.elements.destination.value=link?.destination || ''; form.elements.active.checked=link?link.active:true;
    $('#link-dialog-title').textContent=link?'Editar link':'Criar novo link'; $('#save-link').textContent=link?'Salvar alterações':'Criar link e QR Code';
    $('#fixed-url-box').hidden=!link; $('#link-active-field').hidden=!link; $('#fixed-url-text').textContent=link?.url || '';
    openDialog('#link-dialog');
  }
  function openQR(link) {
    selectedQr=link; $('#qr-preview').innerHTML=qrSvg(link.url); $('#qr-name').textContent=link.name;
    $('#qr-fixed-url').textContent=link.url; $('#qr-error').hidden=true; openDialog('#qr-dialog');
  }
  $('#create-link').onclick=()=>openLink();
  $('#links-container').addEventListener('click',event=>{
    if(event.target.closest('[data-new-link]')) openLink();
    if(event.target.closest('[data-retry-links]')) loadLinks();
    for(const action of ['edit','qr','copy','delete']) {
      const el=event.target.closest(`[data-${action}]`); if(!el) continue;
      const link=links.find(item=>item.id===Number(el.dataset[action])); if(!link) return;
      if(action==='edit') openLink(link.id);
      if(action==='qr') openQR(link);
      if(action==='copy') copy(link.url);
      if(action==='delete') { selectedDelete=link; $('#delete-name').textContent=link.name; formError($('#confirm-dialog')); openDialog('#confirm-dialog'); }
    }
  });
  $('#link-form').addEventListener('submit',event=>{
    event.preventDefault(); const form=event.currentTarget; formError(form);
    const values=Object.fromEntries(new FormData(form)); values.active=form.elements.active.checked;
    busy($('#save-link'),async()=>{
      try {
        const data=await api(values.id?'links.update':'links.create',values); $('#link-dialog').close();
        await loadLinks(values.id?linkPage:1);
        toast(values.id?'Destino atualizado. O QR Code continua o mesmo.':'Link criado! Seu QR Code está pronto.');
        if(data.link) openQR(data.link);
      } catch(error) { formError(form,error.message); }
    });
  });
  $('#confirm-delete').onclick=()=>busy($('#confirm-delete'),async()=>{
    try { await api('links.delete',{id:selectedDelete.id}); $('#confirm-dialog').close(); toast('Link excluído.'); await loadLinks(); }
    catch(error) { formError($('#confirm-dialog'),error.message); }
  });
  $('#copy-qr-url').onclick=()=>copy(selectedQr.url);
  $('#download-svg').onclick=()=>{
    try { download(new Blob([qrSvg(selectedQr.url)],{type:'image/svg+xml;charset=utf-8'}),`${slug(selectedQr.name)}-${selectedQr.code}.svg`); toast('SVG gerado em qualidade vetorial.'); }
    catch(_) { $('#qr-error').textContent='Não foi possível gerar o SVG. Tente novamente.'; $('#qr-error').hidden=false; }
  };
  $('#download-png').onclick=()=>busy($('#download-png'),async()=>{
    let canvas;
    try {
      $('#qr-error').hidden=true;
      const size=Number($('#png-size').value), qr=getQR(selectedQr.url), n=qr.getModuleCount(), cell=Math.floor(size/(n+8)), offset=Math.floor((size-n*cell)/2);
      canvas=document.createElement('canvas'); canvas.width=size; canvas.height=size;
      const context=canvas.getContext('2d',{alpha:false}); if(!context) throw new Error();
      context.imageSmoothingEnabled=false; context.fillStyle='#fff'; context.fillRect(0,0,size,size); context.fillStyle='#000';
      for(let row=0;row<n;row++) for(let col=0;col<n;col++) if(qr.isDark(row,col)) context.fillRect(offset+col*cell,offset+row*cell,cell,cell);
      const blob=await new Promise((resolve,reject)=>canvas.toBlob(value=>value?resolve(value):reject(new Error()),'image/png'));
      download(blob,`${slug(selectedQr.name)}-${selectedQr.code}-${size}px.png`); toast(`PNG de ${size.toLocaleString('pt-BR')} × ${size.toLocaleString('pt-BR')} pixels gerado.`);
    } catch(_) { $('#qr-error').textContent='Seu navegador não conseguiu gerar este tamanho. Escolha um PNG menor ou baixe o SVG, que pode ser ampliado sem limite.'; $('#qr-error').hidden=false; }
    finally { if(canvas) { canvas.width=1; canvas.height=1; } }
  });
  async function loadUsers(page=userPage) {
    try {
      const data=await api('users.list',undefined,page); users=data.users; userPage=data.page; $('#user-count').textContent=data.total;
      $('#users-container').innerHTML=`<div class="users-table"><div class="users-table-head"><span>USUÁRIO</span><span>ACESSO</span><span>STATUS</span><span></span></div>${users.map(user=>`<article class="user-row"><div class="user-identity"><span class="avatar light">${esc(user.name.slice(0,1).toUpperCase())}</span><div><h3>${esc(user.name)}${user.id===Number(document.body.dataset.userId)?'<small class="you-badge">Você</small>':''}</h3><p>${esc(user.email)}</p></div></div><span class="role-label">${user.role==='admin'?'Administrador':'Usuário'}</span><span class="status ${user.active?'active':'paused'}">${user.active?'Ativo':'Desativado'}</span><button class="button secondary compact" data-edit-user="${user.id}">${icon('edit')}Editar</button></article>`).join('')}</div>`;
      pagination($('#users-pagination'),data.page,data.pages,loadUsers);
    } catch(error) { $('#users-container').innerHTML=`<div class="load-error" role="alert"><p>${esc(error.message)}</p><button class="button secondary" data-retry-users>Tentar novamente</button></div>`; }
  }
  function openUser(id) {
    const form=$('#user-form'), user=users.find(item=>item.id===id); form.reset(); formError(form);
    form.elements.id.value=user?.id || ''; form.elements.name.value=user?.name || ''; form.elements.email.value=user?.email || ''; form.elements.role.value=user?.role || 'user'; form.elements.active.checked=user?user.active:true;
    form.elements.password.required=!user;
    $('#user-dialog-title').textContent=user?'Editar usuário':'Novo usuário'; $('#save-user').textContent=user?'Salvar alterações':'Cadastrar usuário'; $('#user-active-field').hidden=!user;
    $('#user-password-label').textContent=user?'Redefinir senha (opcional)':'Senha temporária';
    $('#user-password-help').textContent=user?'Deixe vazio para manter a senha. Uma nova senha exige troca no próximo acesso.':'No mínimo 10 caracteres. O usuário definirá uma nova senha no primeiro acesso.';
    const self=user?.id===Number(document.body.dataset.userId); form.elements.role.disabled=self; form.elements.active.disabled=self;
    openDialog('#user-dialog');
  }
  if(admin) {
    $('#create-user').onclick=()=>openUser();
    $('#users-container').onclick=event=>{
      const edit=event.target.closest('[data-edit-user]'); if(edit) openUser(Number(edit.dataset.editUser));
      if(event.target.closest('[data-retry-users]')) loadUsers();
    };
    $('#user-form').addEventListener('submit',event=>{
      event.preventDefault(); const form=event.currentTarget; formError(form);
      const values=Object.fromEntries(new FormData(form)); values.active=form.elements.active.checked; values.role=form.elements.role.value;
      busy($('#save-user'),async()=>{
        try {
          const data=await api(values.id?'users.update':'users.create',values); $('#user-dialog').close();
          toast(values.id?'Conta atualizada.':'Usuário criado. Compartilhe a senha temporária diretamente com ele.');
          if(data.self_updated) { location.reload(); return; }
          await loadUsers(values.id?userPage:1);
        } catch(error) { formError(form,error.message); }
      });
    });
  }
  $$('[data-view]').forEach(button=>button.onclick=()=>{
    if(passwordRequired) { openPassword(true); return; }
    const view=button.dataset.view;
    $$('.view').forEach(item=>{item.hidden=item.id!==`${view}-view`;});
    $$('[data-view]').forEach(item=>{item.classList.toggle('selected',item===button); if(item===button)item.setAttribute('aria-current','page');else item.removeAttribute('aria-current');});
    $('#breadcrumb').textContent=view==='users'?'Usuários':'Links e QR Codes'; document.title=`${view==='users'?'Usuários':'Meus links'} · RotaQR`;
    if(view==='users') loadUsers();
  });
  function openPassword(required=false) {
    if(required) passwordRequired=true;
    const form=$('#password-form'); form.reset(); formError(form);
    $('#password-title').textContent=passwordRequired?'Defina sua senha pessoal':'Alterar senha';
    $('#password-intro').textContent=passwordRequired?'No primeiro acesso, troque a senha temporária enviada pelo administrador.':'Use uma senha que só você conhece.';
    $$('.password-close').forEach(el=>{el.hidden=passwordRequired;});
    if(!$('#password-dialog').open) openDialog('#password-dialog');
  }
  $('#open-profile').onclick=()=>openPassword();
  $('#password-dialog').addEventListener('cancel',event=>{if(passwordRequired)event.preventDefault();});
  $('#password-form').addEventListener('submit',event=>{
    event.preventDefault(); const form=event.currentTarget; formError(form);
    const values=Object.fromEntries(new FormData(form)); if(values.password!==values.confirmation) { formError(form,'As novas senhas não conferem.'); return; }
    busy($('[type=submit]',form),async()=>{
      try { await api('password.change',values); passwordRequired=false; $('#password-dialog').close(); toast('Senha alterada com sucesso.'); await loadLinks(); }
      catch(error) { formError(form,error.message); }
    });
  });
  $('#logout').onclick=()=>busy($('#logout'),async()=>{
    try { await api('logout',{}); location.reload(); } catch(error) { toast(error.message); }
  });
  if(passwordRequired) openPassword(true); else loadLinks();
})();
