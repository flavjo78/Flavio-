'use strict';
/* AGENZIA — applicazione (senza librerie). Pagine: scadenze, pratiche, clienti, fornitori, statistiche, opzioni. */

// ================= utilità =================
const $ = (sel, el = document) => el.querySelector(sel);
const $$ = (sel, el = document) => Array.from(el.querySelectorAll(sel));
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const eur = c => { const n = Number(c || 0) / 100; const neg = n < 0; const [i, d] = Math.abs(n).toFixed(2).split('.'); return (neg ? '-' : '') + i.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + d; };
const eurS = c => eur(c) + ' €';
const dataIt = d => { if (!d) return ''; const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? `${m[3]}/${m[2]}/${m[1]}` : d; };
const oggiIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
const MESI = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
const STATI = ['Preventivo', 'Confermata', 'Annullata', 'Annullata con penale', 'Chiusa'];
const SERIE = ['var(--s1)', 'var(--s2)', 'var(--s3)', 'var(--s4)', 'var(--s5)', 'var(--s6)', 'var(--s7)', 'var(--s8)'];

function badgeStato(s) {
  const cls = s === 'Annullata con penale' ? 'penale' : (s || 'grigio').split(' ')[0];
  return `<span class="badge ${cls}">${esc(s || '')}</span>`;
}
function opzioni(lista, sel, vuoto) {
  let h = vuoto !== undefined ? `<option value="">${esc(vuoto)}</option>` : '';
  for (const v of lista) {
    const val = typeof v === 'object' ? v.value : v, lab = typeof v === 'object' ? v.label : v;
    h += `<option value="${esc(val)}" ${String(val) === String(sel ?? '') ? 'selected' : ''}>${esc(lab)}</option>`;
  }
  return h;
}
function datalist(id, lista) { return `<datalist id="${id}">${lista.map(v => `<option value="${esc(v)}">`).join('')}</datalist>`; }

function toast(msg, tipo = '') {
  const t = document.createElement('div');
  t.className = 'toast ' + tipo; t.textContent = msg;
  $('#toast').appendChild(t);
  setTimeout(() => t.remove(), tipo === 'err' ? 6000 : 3000);
}

// ---- API ----
async function api(azione, corpo, query) {
  const qs = query ? '?' + new URLSearchParams(Object.entries(query).filter(([, v]) => v !== undefined && v !== null && v !== '')).toString() : '';
  const r = await fetch('/api/' + azione + qs, corpo !== undefined
    ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo) }
    : {});
  let d = null;
  try { d = await r.json(); } catch (e) { d = { errore: 'Risposta non valida dal server' }; }
  if (!r.ok) {
    if (d && d.login) { STATO.utente = null; renderLogin(); }
    throw new Error(d && d.errore ? d.errore : 'Errore ' + r.status);
  }
  return d;
}

// ---- modale generico ----
function modale({ titolo, corpo, ok = 'OK', annulla = 'Annulla', larga = false, onOk, senzaOk = false }) {
  return new Promise(resolve => {
    const d = document.createElement('dialog');
    d.className = 'modal' + (larga ? ' larga' : '');
    d.innerHTML = `<div class="mh">${esc(titolo)}</div><div class="mb">${corpo}</div>
      <div class="mf"><button class="btn" data-x="annulla">${esc(annulla)}</button>${senzaOk ? '' : `<button class="btn primario" data-x="ok">${esc(ok)}</button>`}</div>`;
    document.body.appendChild(d);
    const chiudi = v => { d.close(); d.remove(); resolve(v); };
    $('[data-x=annulla]', d).onclick = () => chiudi(null);
    if (!senzaOk) $('[data-x=ok]', d).onclick = async () => {
      try { const v = onOk ? await onOk(d) : true; if (v !== false) chiudi(v); }
      catch (e) { toast(e.message, 'err'); }
    };
    d.addEventListener('cancel', e => { e.preventDefault(); chiudi(null); });
    d.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && !senzaOk) { e.preventDefault(); $('[data-x=ok]', d).click(); } });
    d.showModal();
    const primo = $('input,select,textarea', d); if (primo) primo.focus();
  });
}
const conferma = (titolo, testo) => modale({ titolo, corpo: `<p>${esc(testo)}</p>`, ok: 'Sì, procedi' });

// legge i campi [name] dentro un contenitore in un oggetto
function leggiForm(el) {
  const o = {};
  for (const i of $$('[name]', el)) {
    if (i.closest('[data-righe]')) continue;
    if (i.type === 'checkbox') o[i.name] = i.checked ? 1 : 0;
    else if (i.type === 'radio') { if (i.checked) o[i.name] = i.value; }
    else o[i.name] = i.value;
  }
  return o;
}

// ================= stato globale e router =================
const STATO = { utente: null, pannello: false, imp: null, route: '' };
// Guardia "modifiche non salvate": la pagina che la imposta dice se è sporca e come salvare.
let GUARDIA = null;
let IGNORA_HASH = false;
window.addEventListener('beforeunload', e => { if (GUARDIA && GUARDIA.sporca()) { e.preventDefault(); e.returnValue = ''; } });
function chiediSalvataggio(cosa) {
  return new Promise(resolve => {
    const d = document.createElement('dialog'); d.className = 'modal';
    d.innerHTML = `<div class="mh">Modifiche non salvate</div><div class="mb"><p>Hai modificato ${esc(cosa)} e non hai salvato. Cosa vuoi fare?</p></div>
      <div class="mf"><button class="btn" data-x="resta">Resta qui</button><button class="btn pericolo" data-x="esci">Esci senza salvare</button><button class="btn primario" data-x="salva">Salva e vai</button></div>`;
    document.body.appendChild(d);
    for (const b of $$('[data-x]', d)) b.onclick = () => { d.close(); d.remove(); resolve(b.dataset.x); };
    d.addEventListener('cancel', e => { e.preventDefault(); d.close(); d.remove(); resolve('resta'); });
    d.showModal();
  });
}

function naviga(hash) { location.hash = hash; }
window.addEventListener('hashchange', () => render());

async function avvio() {
  try {
    const st = await api('stato_installazione');
    if (!st.installato) return renderInstalla();
    const me = await api('me').catch(() => null);
    if (!me || !me.utente) return renderLogin();
    STATO.utente = me.utente; STATO.pannello = me.pannello;
    STATO.imp = await api('impostazioni');
    render();
  } catch (e) { toast(e.message, 'err'); renderLogin(); }
}

function renderLogin() {
  (async () => {
    let utenti = [];
    try { utenti = (await api('stato_installazione')).utenti || []; } catch (e) { /* elenco non disponibile: si scrive il nome */ }
    let scelto = '';
    try { scelto = localStorage.getItem('agenzia_ultimo_utente') || ''; } catch (e) { /* ignora */ }
    if (!utenti.some(u => u.username === scelto)) scelto = utenti.length === 1 ? utenti[0].username : '';
    const iniziali = n => n.split(/\s+/).map(p => p[0] || '').join('').slice(0, 2).toUpperCase();
    $('#app').innerHTML = `<div class="login-wrap"><form class="login" id="fLogin" style="width:${utenti.length > 1 ? 520 : 400}px">
      <h1>Agenzia</h1><p>${utenti.length ? 'Chi sei?' : 'Entra con il tuo nome utente'}</p>
      ${utenti.length ? `<div class="utenti-scelta">${utenti.map(u => `<button type="button" class="ut ${u.username === scelto ? 'sel' : ''}" data-u="${esc(u.username)}"><span class="av">${esc(iniziali(u.nome))}</span><span>${esc(u.nome)}</span></button>`).join('')}</div>` : ''}
      <input type="hidden" name="username" value="${esc(scelto)}">
      ${utenti.length ? '' : '<div class="campo"><label>Nome utente</label><input name="username" autocomplete="username" autofocus></div>'}
      <div class="campo" id="campoPw" style="${utenti.length && !scelto ? 'visibility:hidden' : ''}"><label>Password di <b id="lblChi">${esc((utenti.find(u => u.username === scelto) || {}).nome || '')}</b></label><input name="password" type="password" autocomplete="current-password" ${scelto || !utenti.length ? 'autofocus' : ''}></div>
      <button class="btn primario" style="width:100%;justify-content:center;margin-top:6px">Entra</button>
    </form></div>`;
    $$('.ut').forEach(b => b.onclick = () => {
      $$('.ut').forEach(x => x.classList.remove('sel')); b.classList.add('sel');
      $('[name=username]').value = b.dataset.u;
      $('#lblChi').textContent = utenti.find(u => u.username === b.dataset.u).nome;
      $('#campoPw').style.visibility = ''; $('[name=password]').value = ''; $('[name=password]').focus();
    });
    $('#fLogin').onsubmit = async e => {
      e.preventDefault();
      const f = leggiForm($('#fLogin'));
      if (!f.username) { toast('Scegli chi sei', 'err'); return; }
      try {
        const r = await api('login', f);
        try { localStorage.setItem('agenzia_ultimo_utente', r.utente.username); } catch (err) { /* ignora */ }
        STATO.utente = r.utente; STATO.pannello = r.pannello;
        STATO.imp = await api('impostazioni');
        if (!location.hash || location.hash === '#/') naviga('#/scadenze'); else render();
      } catch (err) { toast(err.message, 'err'); $('[name=password]').value = ''; $('[name=password]').focus(); }
    };
  })();
}
function renderInstalla() {
  $('#app').innerHTML = `<div class="login-wrap"><form class="login" id="fInst" style="width:460px">
    <h1>Primo avvio</h1><p>Crea il primo utente e la password del pannello opzioni.</p>
    <div class="campo"><label>Nome dell'agenzia (per l'intestazione)</label><input name="insegna" placeholder="Es. I Viaggi di ..."></div>
    <div class="campo"><label>Il tuo nome (compare nelle pratiche)</label><input name="nome" placeholder="Es. Fabio"></div>
    <div class="campo"><label>Nome utente per entrare</label><input name="username" placeholder="es. fabio"></div>
    <div class="campo"><label>La tua password</label><input name="password" type="password"></div>
    <div class="campo"><label>Password del PANNELLO OPZIONI (diversa, per le impostazioni delicate)</label><input name="pannello_password" type="password"></div>
    <button class="btn primario" style="width:100%;justify-content:center;margin-top:6px">Crea e entra</button>
  </form></div>`;
  $('#fInst').onsubmit = async e => {
    e.preventDefault();
    try {
      const r = await api('installa', leggiForm($('#fInst')));
      STATO.utente = r.utente; STATO.imp = await api('impostazioni');
      toast('Benvenuto! Imposta i dati dell\'agenzia dal pannello Opzioni.', 'ok');
      naviga('#/scadenze');
    } catch (err) { toast(err.message, 'err'); }
  };
}

const ICONE = {
  scadenze: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
  pratiche: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/></svg>',
  clienti: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
  fornitori: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-6h6v6"/></svg>',
  statistiche: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/></svg>',
  opzioni: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>'
};
const PAGINE = [['scadenze', 'Scadenze'], ['pratiche', 'Pratiche'], ['clienti', 'Clienti'], ['fornitori', 'Fornitori'], ['statistiche', 'Statistiche'], ['opzioni', 'Opzioni']];

function shell(pagina, titoloHtml, contenutoHtml, azioniHtml = '') {
  const u = STATO.utente;
  $('#app').innerHTML = `
  <aside class="sidebar">
    <div class="brand">${esc(STATO.imp?.agenzia?.insegna || 'Agenzia')}<small>gestionale</small></div>
    <nav class="nav">${PAGINE.map(([k, l]) => `<a href="#/${k}" class="${pagina === k ? 'attivo' : ''}">${ICONE[k]}${l}</a>`).join('')}</nav>
    <div class="sidebar-foot"><div class="nome">${esc(u.nome)}</div><a href="#" id="lnkPw">cambia password</a> · <a href="#" id="lnkEsci">esci</a></div>
  </aside>
  <div class="main">
    <div class="topbar"><h1>${titoloHtml}</h1><div class="spazio"></div><div class="azioni">${azioniHtml}</div></div>
    <div class="contenuto" id="pg">${contenutoHtml}</div>
  </div>`;
  $('#lnkEsci').onclick = async e => { e.preventDefault(); await api('logout', {}); STATO.utente = null; renderLogin(); };
  $('#lnkPw').onclick = e => { e.preventDefault(); cambiaPassword(); };
}
function cambiaPassword() {
  modale({
    titolo: 'Cambia la tua password', ok: 'Salva',
    corpo: `<div class="campo"><label>Password attuale</label><input name="vecchia" type="password"></div>
      <div class="campo" style="margin-top:10px"><label>Nuova password</label><input name="nuova" type="password"></div>`,
    onOk: async d => { await api('cambia_password', leggiForm(d)); toast('Password cambiata', 'ok'); }
  });
}

async function render() {
  if (!STATO.utente) return renderLogin();
  const h = (location.hash || '#/scadenze').replace(/^#\/?/, '');
  if (IGNORA_HASH) { IGNORA_HASH = false; return; }
  if (GUARDIA && GUARDIA.sporca() && h !== GUARDIA.route) {
    const scelta = await chiediSalvataggio(GUARDIA.cosa);
    if (scelta === 'resta') { IGNORA_HASH = true; location.hash = '#/' + GUARDIA.route; return; }
    if (scelta === 'salva') { const r = await GUARDIA.salva(); if (!r) { IGNORA_HASH = true; location.hash = '#/' + GUARDIA.route; return; } toast('Salvato', 'ok'); }
  }
  GUARDIA = null;
  const [pagina, id, extra] = h.split('/');
  STATO.route = h;
  try {
    switch (pagina) {
      case 'pratiche': return id ? await paginaPratica(id) : await paginaPratiche();
      case 'clienti': return id ? await paginaCliente(id) : await paginaClienti();
      case 'fornitori': return id ? await paginaFornitore(id) : await paginaFornitori();
      case 'statistiche': return await paginaStatistiche(id);
      case 'opzioni': return await paginaOpzioni(id);
      default: return await paginaScadenze();
    }
  } catch (e) { toast(e.message, 'err'); console.error(e); }
}

// ================= SCADENZE =================
async function paginaScadenze() {
  const d = await api('scadenze');
  const rigaP = p => `<tr class="click" onclick="naviga('#/pratiche/${p.id}')">`;
  const saldi = d.saldi.length ? `<table class="t compatta"><thead><tr><th>Scadenza</th><th>Pratica</th><th>Cliente</th><th>Destinazione</th><th class="num">Totale</th><th class="num">Versato</th><th class="num">Saldo</th></tr></thead><tbody>
    ${d.saldi.map(p => `${rigaP(p)}<td class="${p.scaduto ? 'err' : ''}">${p.scaduto ? '⚠ ' : ''}${dataIt(p.scadenza) || '<span class="muted">—</span>'}</td><td class="mono">${p.numero}/${p.anno}</td><td>${esc(p.cliente)}</td><td>${esc(p.destinazione)}</td><td class="num">${eur(p.dovuto_cent)}</td><td class="num">${eur(p.versato_cent)}</td><td class="num"><b>${eur(p.saldo_cent)}</b></td></tr>`).join('')}
    </tbody><tfoot><tr><td colspan="6">Totale da incassare</td><td class="num">${eur(d.saldi.reduce((s, p) => s + p.saldo_cent, 0))}</td></tr></tfoot></table>` : '<div class="vuoto">Nessun saldo in sospeso</div>';
  const forn = d.fornitori.length ? `<table class="t compatta"><thead><tr><th>Partenza</th><th>Fornitore</th><th>Pratica</th><th>Cliente</th><th class="num">Costo</th><th class="num">Pagato</th><th class="num">Da pagare</th></tr></thead><tbody>
    ${d.fornitori.map(p => `${rigaP(p)}<td>${dataIt(p.partenza)}</td><td>${esc(p.fornitore)}</td><td class="mono">${p.numero}/${p.anno}</td><td>${esc(p.cliente)}</td><td class="num">${eur(p.costo_cent)}</td><td class="num">${eur(p.pagato_cent)}</td><td class="num"><b>${eur(p.dovuto_cent)}</b></td></tr>`).join('')}
    </tbody><tfoot><tr><td colspan="6">Totale da pagare ai fornitori</td><td class="num">${eur(d.fornitori.reduce((s, p) => s + p.dovuto_cent, 0))}</td></tr></tfoot></table>` : '<div class="vuoto">Nessun pagamento dovuto ai fornitori</div>';
  const part = d.partenze.length ? `<table class="t compatta"><thead><tr><th>Partenza</th><th>Rientro</th><th>Pratica</th><th>Cliente</th><th>Cellulare</th><th>Destinazione</th></tr></thead><tbody>
    ${d.partenze.map(p => `${rigaP(p)}<td><b>${dataIt(p.partenza)}</b></td><td>${dataIt(p.rientro)}</td><td class="mono">${p.numero}/${p.anno}</td><td>${esc(p.cliente)}</td><td>${esc(p.cellulare)}</td><td>${esc(p.destinazione)}</td></tr>`).join('')}</tbody></table>` : '<div class="vuoto">Nessuna partenza nei prossimi 30 giorni</div>';
  const doc = d.documenti.length ? `<table class="t compatta"><thead><tr><th>Scade</th><th>Cliente</th><th>Documento</th><th>Cellulare</th></tr></thead><tbody>
    ${d.documenti.map(c => `<tr class="click" onclick="naviga('#/clienti/${c.id}')"><td class="${c.doc_scade < d.oggi ? 'err' : 'warn'}">${dataIt(c.doc_scade)}</td><td>${esc(c.cliente)}</td><td>${esc(c.doc_tipo)}</td><td>${esc(c.cellulare)}</td></tr>`).join('')}</tbody></table>` : '<div class="vuoto">Nessun documento in scadenza tra i clienti in partenza</div>';
  shell('scadenze', 'Scadenze', `
    <div class="cards-stat">
      <div class="stat"><div class="l">Saldi da incassare</div><div class="v">${d.saldi.length}</div><div class="s">${eurS(d.saldi.reduce((s, p) => s + p.saldo_cent, 0))}${d.saldi.some(p => p.scaduto) ? ' · <span class="err">' + d.saldi.filter(p => p.scaduto).length + ' scaduti</span>' : ''}</div></div>
      <div class="stat"><div class="l">Da pagare ai fornitori</div><div class="v">${d.fornitori.length}</div><div class="s">${eurS(d.fornitori.reduce((s, p) => s + p.dovuto_cent, 0))}</div></div>
      <div class="stat"><div class="l">Partenze nei prossimi 30 giorni</div><div class="v">${d.partenze.length}</div><div class="s">&nbsp;</div></div>
      <div class="stat"><div class="l">Documenti in scadenza</div><div class="v">${d.documenti.length}</div><div class="s">clienti con partenza prevista</div></div>
    </div>
    <div class="card"><h2>Saldi clienti</h2>${saldi}</div>
    <div class="card"><h2>Pagamenti dovuti ai fornitori</h2>${forn}</div>
    <div class="griglia g2"><div class="card"><h2>Prossime partenze</h2>${part}</div><div class="card"><h2>Documenti in scadenza</h2>${doc}</div></div>`,
    `<a class="btn primario" href="#/pratiche/nuova">+ Nuova pratica</a>`);
}

// ================= PRATICHE: elenco =================
const FILTRI_PR = { q: '', anno: '', stato: '' };
async function paginaPratiche() {
  const anni = await api('pratiche_anni');
  if (FILTRI_PR.anno === '' && anni.length) FILTRI_PR.anno = String(anni[0]);
  shell('pratiche', 'Pratiche', `
    <div class="toolbar">
      <input class="inp cerca" id="prQ" placeholder="Cerca per cliente, destinazione, numero, riferimento…" value="${esc(FILTRI_PR.q)}">
      <select id="prAnno">${opzioni(['tutti gli anni', ...anni].map(a => a === 'tutti gli anni' ? { value: 'tutti', label: a } : { value: String(a), label: String(a) }), FILTRI_PR.anno || 'tutti')}</select>
      <select id="prStato">${opzioni(STATI, FILTRI_PR.stato, 'tutti gli stati')}</select>
      <span class="muted small" id="prN"></span>
    </div>
    <div class="card" id="prTab"></div>`,
    `<a class="btn primario" href="#/pratiche/nuova">+ Nuova pratica</a>`);
  let timer;
  const carica = async () => {
    const lista = await api('pratiche', undefined, { q: FILTRI_PR.q, anno: FILTRI_PR.anno === 'tutti' ? '' : FILTRI_PR.anno, stato: FILTRI_PR.stato });
    $('#prN').textContent = `${lista.length} pratiche`;
    $('#prTab').innerHTML = lista.length ? `<table class="t"><thead><tr><th>N.</th><th>Apertura</th><th>Cliente</th><th>Destinazione</th><th>Partenza</th><th>Fornitori</th><th>Stato</th><th class="num">Totale</th><th class="num">Versato</th><th class="num">Saldo</th><th>Operatore</th></tr></thead><tbody>
      ${lista.map(p => `<tr class="click" onclick="naviga('#/pratiche/${p.id}')"><td class="mono"><b>${p.numero}</b>/${p.anno}</td><td>${dataIt(p.data_apertura)}</td><td>${esc(p.cliente)}</td><td>${esc((p.destinazione || '').split('\n')[0]).slice(0, 60)}</td><td>${dataIt(p.partenza)}</td><td class="small">${esc((p.fornitori || '').replace(/,/g, ', '))}</td><td>${badgeStato(p.stato)}</td><td class="num">${eur(p.totale_cent)}</td><td class="num">${eur(p.versato_cent)}</td><td class="num ${p.saldo_cent > 0 ? 'warn' : ''}">${p.stato === 'Annullata' ? '—' : eur(p.saldo_cent)}</td><td class="small">${esc(p.operatore || '')}</td></tr>`).join('')}
      </tbody></table>` : '<div class="vuoto">Nessuna pratica trovata</div>';
  };
  $('#prQ').oninput = e => { FILTRI_PR.q = e.target.value; clearTimeout(timer); timer = setTimeout(carica, 250); };
  $('#prAnno').onchange = e => { FILTRI_PR.anno = e.target.value; carica(); };
  $('#prStato').onchange = e => { FILTRI_PR.stato = e.target.value; carica(); };
  await carica();
  $('#prQ').focus();
}

// ================= PRATICHE: scheda =================
let FORNITORI_CACHE = null;
async function fornitoriAttivi(forza) {
  if (!FORNITORI_CACHE || forza) FORNITORI_CACHE = await api('fornitori');
  return FORNITORI_CACHE;
}
const selFornitore = (sel, lista, vuoto = '—') => `<select name="fornitore_id">${opzioni(lista.map(f => ({ value: f.id, label: f.nome })), sel, vuoto)}</select>`;

async function paginaPratica(id) {
  const nuova = id === 'nuova';
  const parti = STATO.route.split('/');
  const clienteIniziale = nuova && parti[2] ? await api('cliente', undefined, { id: parti[2] }).catch(() => null) : null;
  const apriAcconto = !nuova && parti[2] === 'acconto';
  const P = nuova ? {
    id: 0, anno: new Date().getFullYear(), numero: '', stato: 'Preventivo', data_apertura: oggiIso(), intermediazione: 1, pacchetto_servizio: 'pacchetto', in_catalogo: 1,
    cliente_id: clienteIniziale ? clienteIniziale.id : 0, cliente: clienteIniziale ? `${clienteIniziale.cognome} ${clienteIniziale.nome}` : '',
    righe: [], partecipanti: clienteIniziale ? [{ nome: `${clienteIniziale.cognome} ${clienteIniziale.nome}`, nato_il: clienteIniziale.nato_il }] : [], acconti: [], pagamenti_fornitore: [], fornitori: [],
    adulti: 1, bambini: 0, infanti: 0, durata_giorni: 0, durata_notti: 0, abbuono_cent: 0, penale_cent: 0, tipo: '', documenti_necessari: ''
  } : await api('pratica', undefined, { id });
  if (nuova) { const pn = await api('prossimo_numero', undefined, { anno: P.anno }); P.numero = pn.numero; }
  const forn = await fornitoriAttivi();
  const L = STATO.imp.liste;
  const titolo = nuova ? `Nuova pratica <span class="muted" style="font-weight:400">· sarà la n. <b>${P.numero}</b>/${P.anno}</span>` : `Pratica <b>${P.numero}</b>/${P.anno} ${badgeStato(P.stato)}`;

  // ---- card di un servizio (padre) con le sue voci nascoste (figli) ----
  const rigaFiglio = (c = {}) => `<div class="figlio" data-figlio>
    <span class="freccia">↳</span>
    <input data-k="descrizione" value="${esc(c.descrizione || '')}" placeholder="Voce nascosta: provvigione, diritti, costi…" list="dlFigli">
    <input data-k="prezzo" class="num soldi" value="${c.prezzo_cent ? eur(c.prezzo_cent) : ''}" placeholder="€ cad.">
    <label class="small" title="Se spuntato, questa voce compare in stampa come riga a parte invece di essere sommata al padre"><input type="checkbox" data-k="stampa" ${c.stampa ? 'checked' : ''}> in stampa</label>
    <button type="button" class="btn piccolo icona" data-del-figlio title="Togli">✕</button></div>`;
  const cardServizio = (r = {}) => `<div class="srv" data-srv>
    <div class="srv-h">
      <div class="campo c-desc"><label>Descrizione (come in stampa)</label><input data-k="descrizione" list="dlServizi" value="${esc(r.descrizione || '')}" placeholder="es. Quota pacchetto"></div>
      <div class="campo c-forn"><label>Fornitore</label>${selFornitore(r.fornitore_id || '', forn, '— nessuno —')}</div>
      <div class="campo c-num"><label>Importo cad. €</label><input data-k="prezzo" class="num soldi" value="${r.prezzo_cent ? eur(r.prezzo_cent) : ''}"></div>
      <div class="campo c-pax"><label>Pax</label><input data-k="pax" class="num" value="${r.pax ?? 1}"></div>
      <div class="campo c-tot"><label>Totale riga</label><div class="tot" data-tot>0,00</div></div>
      <div class="campo c-st"><label>&nbsp;</label><label class="small"><input type="checkbox" data-k="stampa" ${r.stampa === 0 ? '' : 'checked'}> in stampa</label></div>
      <div class="campo c-az"><label>&nbsp;</label><button type="button" class="btn piccolo icona pericolo" data-del title="Togli servizio">✕</button></div>
    </div>
    <div class="srv-f">${(r.figli || []).map(rigaFiglio).join('')}</div>
    <div class="srv-foot"><button type="button" class="btn piccolo" data-add-figlio>+ Aggiungi voce nascosta (provvigione, costi…)</button><span class="small muted" data-cliente></span></div>
  </div>`;
  const rigaPart = (q = {}) => `<tr><td><input data-k="nome" value="${esc(q.nome || '')}" placeholder="Cognome e nome"></td><td style="width:170px"><input data-k="nato_il" type="date" value="${esc(q.nato_il || '')}"></td><td class="az"><button type="button" class="btn piccolo icona" data-del>✕</button></td></tr>`;

  shell('pratiche', titolo, `
  ${!nuova ? `<div class="muted small" style="margin:-8px 0 12px">Aperta il ${dataIt(P.data_apertura)} da <b>${esc(P.operatore || '—')}</b> · ultima modifica ${P.aggiornato_ts ? new Date(P.aggiornato_ts).toLocaleString('it-IT') : ''}</div>` : `<div class="avviso info">Il numero <b>${P.numero}/${P.anno}</b> viene assegnato al primo salvataggio. Fino ad allora la pratica è una bozza.</div>`}
  <div id="fPratica">
  <div class="card"><h2>Cliente e pratica</h2>
    <div class="griglia g4">
      <div class="campo col2"><label>Cliente intestatario *</label>
        <div class="picker"><input id="pkCliente" value="${esc(P.cliente || '')}" placeholder="Cerca per cognome…" autocomplete="off"><input type="hidden" name="cliente_id" value="${P.cliente_id || ''}"><div class="lista hidden" id="pkLista"></div></div>
        <div class="small"><a href="#" id="lnkApriCliente" class="${P.cliente_id ? '' : 'hidden'}">apri la scheda del cliente</a></div></div>
      <div class="campo"><label>Stato</label><select name="stato" id="selStato">${opzioni(STATI, P.stato)}</select></div>
      <div class="campo" id="campoPenale" style="${P.stato === 'Annullata con penale' ? '' : 'display:none'}"><label>Penale dovuta dal cliente (€)</label><input name="penale" class="num soldi" value="${P.penale_cent ? eur(P.penale_cent) : ''}"></div>
      <div class="campo"><label>Tipo pratica</label><input name="tipo" list="dlTipi" value="${esc(P.tipo || '')}">${datalist('dlTipi', L.tipi_pratica)}</div>
      <div class="campo"><label>Data apertura</label><input name="data_apertura" type="date" value="${esc(P.data_apertura || '')}"></div>
      <div class="campo"><label>Rapporto</label><select name="intermediazione">${opzioni([{ value: '1', label: 'Intermediazione (vendiamo per conto del T.O.)' }, { value: '0', label: 'Nostra organizzazione' }], String(P.intermediazione ?? 1))}</select></div>
      <div class="campo"><label>Oggetto</label><select name="pacchetto_servizio">${opzioni([{ value: 'pacchetto', label: 'Pacchetto turistico' }, { value: 'servizio', label: 'Servizio turistico' }], P.pacchetto_servizio)}</select></div>
      <div class="campo"><label>Catalogo</label><select name="in_catalogo">${opzioni([{ value: '1', label: 'In catalogo' }, { value: '0', label: 'Fuori catalogo' }], String(P.in_catalogo ?? 1))}</select></div>
      <div class="campo"><label>Nome catalogo</label><input name="catalogo" value="${esc(P.catalogo || '')}"></div>
      <div class="campo"><label>Pagina</label><input name="catalogo_pag" value="${esc(P.catalogo_pag || '')}"></div>
      <div class="campo"><label>N. pratica del T.O. / riferimento</label><input name="riferimento_to" value="${esc(P.riferimento_to || '')}"></div>
      <div class="campo"><label>Operatore del T.O.</label><input name="operatore_to" value="${esc(P.operatore_to || '')}"></div>
    </div></div>

  <div class="card"><h2>Viaggio</h2>
    <div class="griglia g6">
      <div class="campo col6"><label>Destinazione / itinerario (va in stampa)</label><textarea name="destinazione">${esc(P.destinazione || '')}</textarea></div>
      <div class="campo"><label>Data partenza</label><input name="partenza" type="date" id="inPartenza" value="${esc(P.partenza || '')}"></div>
      <div class="campo"><label>Parte da (luogo)</label><input name="partenza_da" value="${esc(P.partenza_da || '')}" placeholder="es. Napoli"></div>
      <div class="campo"><label>Arriva a (luogo)</label><input name="partenza_a" value="${esc(P.partenza_a || '')}" placeholder="es. Madrid"></div>
      <div class="campo"><label>Data rientro</label><input name="rientro" type="date" id="inRientro" value="${esc(P.rientro || '')}"></div>
      <div class="campo"><label>Durata giorni</label><input name="durata_giorni" id="inGiorni" class="num" value="${P.durata_giorni ?? 0}"></div>
      <div class="campo"><label>Durata notti</label><input name="durata_notti" id="inNotti" class="num" value="${P.durata_notti ?? 0}"></div>
      <input type="hidden" name="rientro_da" value="${esc(P.rientro_da || '')}"><input type="hidden" name="rientro_a" value="${esc(P.rientro_a || '')}">
      <div class="campo"><label>Adulti</label><input name="adulti" class="num" value="${P.adulti ?? 0}"></div>
      <div class="campo"><label>Bambini</label><input name="bambini" class="num" value="${P.bambini ?? 0}"></div>
      <div class="campo"><label>Infanti</label><input name="infanti" class="num" value="${P.infanti ?? 0}"></div>
      <div class="campo col3"><label>PNR / biglietti</label><input name="pnr" value="${esc(P.pnr || '')}"></div>
      <div class="campo col3"><label>Documenti necessari</label><input name="documenti_necessari" list="dlDoc" value="${esc(P.documenti_necessari || '')}">${datalist('dlDoc', L.documenti_necessari)}</div>
      <div class="campo col3"><label>Richieste specifiche (va in stampa)</label><input name="richieste" value="${esc(P.richieste || '')}"></div>
      <div class="campo col6"><label>Note interne (NON in stampa)</label><textarea name="note" style="min-height:44px">${esc(P.note || '')}</textarea></div>
    </div></div>

  <div class="card"><h2>Partecipanti <span class="spazio"></span><button type="button" class="btn piccolo" id="btnAddPart">+ Aggiungi</button></h2>
    <table class="t compatta" data-righe id="tabPart"><thead><tr><th>Nome e cognome</th><th>Nato il</th><th></th></tr></thead><tbody>${P.partecipanti.map(rigaPart).join('')}</tbody></table></div>

  <div class="card card-servizi"><h2>Servizi richiesti <span class="spazio"></span><button type="button" class="btn primario" id="btnAddRiga">+ Aggiungi servizio</button></h2>
    ${datalist('dlServizi', L.descrizioni_servizi)}${datalist('dlFigli', ['Provvigione agenzia', "Diritti d'agenzia", 'Assicurazione', 'Spese', 'Altro'])}
    <div id="listaServizi" data-righe>${P.righe.map(cardServizio).join('')}</div>
    <div class="srv-tot">
      <div><span class="l">Totale pratica (al cliente)</span><span class="v" id="totPratica">0,00</span></div>
      <div><span class="l">Dovuto ai fornitori</span><span class="v" id="totCosto">0,00</span></div>
      <div><span class="l">Resta all'agenzia</span><span class="v ok" id="totMargine">0,00</span></div>
    </div>
    <div class="small muted" style="margin-top:8px">Ogni servizio è una riga in stampa. Le <b>voci nascoste</b> sotto al servizio (provvigione, diritti…) si sommano al suo importo: il cliente vede una riga sola con il totale. L'importo del servizio con fornitore è quanto dovete al fornitore.</div>
  </div>

  <div class="card"><h2>Acconti e saldo <span class="spazio"></span><button type="button" class="btn piccolo primario" id="btnAddAcc">+ Registra acconto</button></h2>
    <div id="boxAcconti"></div>
    <div class="griglia g4" style="margin-top:12px">
      <div class="campo"><label>Versato</label><input readonly id="outVersato" value="${eur(P.versato_cent)}"></div>
      <div class="campo"><label>Saldo residuo</label><input readonly id="outSaldo" value="${eur(P.saldo_cent)}" style="font-weight:600"></div>
      <div class="campo"><label>Saldo da versare entro il</label><input name="saldo_entro" type="date" value="${esc(P.saldo_entro || '')}"></div>
      <div class="campo"><label>Abbuono / sconto finale € <span class="muted">(facoltativo)</span></label><input name="abbuono" class="num soldi" id="inAbbuono" value="${P.abbuono_cent ? eur(P.abbuono_cent) : ''}"></div>
    </div></div>

  ${nuova ? '' : `<div class="card"><h2>Fornitori di questa pratica <span class="spazio"></span><button type="button" class="btn piccolo" id="btnAddPag">+ Registra pagamento a fornitore</button></h2><div id="boxFornitori"></div></div>`}
  </div>
  <div class="sticky-azioni"><button class="btn primario" id="btnSalva">Salva</button><span class="small muted">oppure Ctrl+S</span>
    ${nuova ? '' : `<a class="btn" href="stampa.html?id=${P.id}" target="_blank">🖨 Stampa conferma</a>`}
    <span class="spazio" style="flex:1"></span>
    ${nuova ? '' : `<button class="btn pericolo" id="btnElimina">Elimina pratica</button>`}
    <a class="btn" href="#/pratiche">Torna all'elenco</a></div>`,
    nuova ? '' : `<a class="btn" href="stampa.html?id=${P.id}" target="_blank">🖨 Stampa conferma</a><button class="btn primario" onclick="$('#btnSalva').click()">Salva</button>`);

  // ---- comportamento ----
  const pg = $('#pg');
  let sporca = false;
  const ricalcola = () => {
    let tot = 0, costo = 0;
    for (const card of $$('#listaServizi [data-srv]')) {
      const prezzo = euroACent($(':scope > .srv-h [data-k=prezzo]', card).value);
      const pax = parseInt($(':scope > .srv-h [data-k=pax]', card).value, 10) || 0;
      const forn = $(':scope > .srv-h [name=fornitore_id]', card).value;
      let nascosti = 0, figliStampa = 0;
      for (const f of $$('[data-figlio]', card)) {
        const v = euroACent($('[data-k=prezzo]', f).value);
        if ($('[data-k=stampa]', f).checked) figliStampa += v; else nascosti += v;
      }
      const alCliente = prezzo + nascosti;
      $('[data-tot]', card).textContent = eur((prezzo + nascosti + figliStampa) * pax);
      $('[data-cliente]', card).textContent = (nascosti || figliStampa) ? `Il cliente vede: ${eur(alCliente)} cad. × ${pax} = ${eur(alCliente * pax)}${figliStampa ? ` + voci in stampa ${eur(figliStampa * pax)}` : ''}` : '';
      tot += (prezzo + nascosti + figliStampa) * pax;
      if (forn) costo += prezzo * pax;
    }
    $('#totPratica').textContent = eur(tot); $('#totCosto').textContent = eur(costo); $('#totMargine').textContent = eur(tot - costo);
    const stato = $('#selStato').value;
    let dovuto = tot - euroACent($('#inAbbuono').value);
    if (stato === 'Annullata con penale') dovuto = euroACent($('[name=penale]', pg).value);
    if (stato === 'Annullata') dovuto = 0;
    $('#outSaldo').value = eur(dovuto - (P.versato_cent || 0));
  };
  $('#fPratica').addEventListener('input', () => { sporca = true; ricalcola(); });
  $('#fPratica').addEventListener('change', () => { sporca = true; ricalcola(); });
  // campi importo: selezione al focus, formato pulito all'uscita
  pg.addEventListener('focusin', e => { if (e.target.classList && e.target.classList.contains('soldi')) e.target.select(); });
  pg.addEventListener('focusout', e => { const t = e.target; if (t.classList && t.classList.contains('soldi') && t.value.trim()) { t.value = eur(euroACent(t.value)); ricalcola(); } });
  $('#selStato').onchange = () => { $('#campoPenale').style.display = $('#selStato').value === 'Annullata con penale' ? '' : 'none'; ricalcola(); };
  pg.addEventListener('click', e => {
    const bf = e.target.closest('[data-del-figlio]'); if (bf) { bf.closest('[data-figlio]').remove(); sporca = true; ricalcola(); return; }
    const b = e.target.closest('[data-del]'); if (b) { const el = b.closest('[data-srv]') || b.closest('tr'); el.remove(); sporca = true; ricalcola(); return; }
    const af = e.target.closest('[data-add-figlio]'); if (af) { const card = af.closest('[data-srv]'); $('.srv-f', card).insertAdjacentHTML('beforeend', rigaFiglio()); $('.srv-f [data-figlio]:last-child input', card).focus(); sporca = true; }
  });
  $('#btnAddRiga').onclick = () => { $('#listaServizi').insertAdjacentHTML('beforeend', cardServizio({ pax: parseInt($('[name=adulti]', pg).value, 10) + parseInt($('[name=bambini]', pg).value, 10) || 1 })); $('#listaServizi [data-srv]:last-child input').focus(); sporca = true; ricalcola(); };
  $('#btnAddPart').onclick = () => { $('#tabPart tbody').insertAdjacentHTML('beforeend', rigaPart()); $('#tabPart tbody tr:last-child input').focus(); sporca = true; };
  const durata = () => {
    const a = $('#inPartenza').value, b = $('#inRientro').value;
    if (a && b && b >= a) { const g = Math.round((Date.parse(b) - Date.parse(a)) / 86400000); $('#inGiorni').value = g + 1; $('#inNotti').value = g; }
  };
  $('#inPartenza').addEventListener('change', durata); $('#inRientro').addEventListener('change', durata);
  ricalcola();

  // picker cliente
  pickerCliente($('#pkCliente'), $('[name=cliente_id]', pg), $('#pkLista'), c => {
    sporca = true;
    $('#lnkApriCliente').classList.remove('hidden');
    $('#lnkApriCliente').href = '#/clienti/' + c.id;
    if (!$$('#tabPart tbody tr').length) $('#tabPart tbody').insertAdjacentHTML('beforeend', rigaPart({ nome: `${c.cognome} ${c.nome}`, nato_il: c.nato_il }));
  });
  if (P.cliente_id) $('#lnkApriCliente').href = '#/clienti/' + P.cliente_id;

  // acconti e fornitori
  const renderAcconti = () => {
    $('#boxAcconti').innerHTML = P.acconti && P.acconti.length ? `<table class="t compatta"><thead><tr><th>Data</th><th>Descrizione</th><th>Metodo</th><th>Riferito a</th><th>Registrato da</th><th class="num">Importo</th><th></th></tr></thead><tbody>
      ${P.acconti.map((a, i) => `<tr><td>${dataIt(a.data)}</td><td>${esc(a.descrizione || (i + 1) + '° acconto')}</td><td>${esc(a.metodo)}</td><td class="small">${esc(a.fornitore || '')}</td><td class="small">${esc(a.operatore || '')}</td><td class="num">${eur(a.importo_cent)}</td><td class="az"><button type="button" class="btn piccolo" data-acc-mod="${a.id}">modifica</button> <button type="button" class="btn piccolo pericolo" data-acc-del="${a.id}">✕</button></td></tr>`).join('')}
      </tbody><tfoot><tr><td colspan="5">Totale versato</td><td class="num">${eur(P.versato_cent)}</td><td></td></tr></tfoot></table>` : `<div class="muted small">Nessun acconto registrato${nuova ? ' (si possono registrare da subito: al primo acconto la pratica viene salvata)' : ''}</div>`;
    $('#outVersato').value = eur(P.versato_cent || 0);
    ricalcola();
  };
  const renderFornitori = () => {
    if (nuova) return;
    const tutti = P.fornitori || [];
    $('#boxFornitori').innerHTML = `${tutti.length ? `<table class="t compatta"><thead><tr><th>Fornitore</th><th class="num">Venduto al cliente</th><th class="num">Dovuto al fornitore</th><th class="num">Pagato al fornitore</th><th class="num">Ancora da pagare</th><th class="num">Il cliente ha versato per questa quota</th></tr></thead><tbody>
      ${tutti.map(f => `<tr class="click" onclick="naviga('#/fornitori/${f.fornitore_id}')"><td>${esc(f.fornitore)}</td><td class="num">${eur(f.venduto_cent)}</td><td class="num">${eur(f.costo_cent)}</td><td class="num">${eur(f.pagato_cent)}</td><td class="num ${f.dovuto_cent > 0 ? 'warn' : 'ok'}"><b>${eur(f.dovuto_cent)}</b></td><td class="num">${eur(f.cliente_versato_cent)}</td></tr>`).join('')}</tbody></table>` : '<div class="muted small">Nessun fornitore sui servizi (scegli il fornitore in ogni servizio)</div>'}
      <h3 style="margin:14px 0 6px">Pagamenti fatti ai fornitori</h3>
      ${P.pagamenti_fornitore.length ? `<table class="t compatta"><thead><tr><th>Data</th><th>Fornitore</th><th>Descrizione</th><th>Metodo</th><th>Registrato da</th><th class="num">Importo</th><th></th></tr></thead><tbody>
      ${P.pagamenti_fornitore.map(g => `<tr><td>${dataIt(g.data)}</td><td>${esc(g.fornitore)}</td><td>${esc(g.descrizione)}</td><td>${esc(g.metodo)}</td><td class="small">${esc(g.operatore || '')}</td><td class="num">${eur(g.importo_cent)}</td><td class="az"><button type="button" class="btn piccolo" data-pag-mod="${g.id}">modifica</button> <button type="button" class="btn piccolo pericolo" data-pag-del="${g.id}">✕</button></td></tr>`).join('')}</tbody></table>` : '<div class="muted small">Nessun pagamento registrato</div>'}`;
  };
  renderAcconti(); renderFornitori();
  const aggiorna = p => { Object.assign(P, p); renderAcconti(); renderFornitori(); };

  const formMovimento = (m, conFornitore, obbligaFornitore) => `<div class="griglia g2">
    <div class="campo"><label>Data</label><input name="data" type="date" value="${esc(m.data || oggiIso())}"></div>
    <div class="campo"><label>Importo (€)</label><input name="importo" class="num soldi" value="${m.importo_cent ? eur(m.importo_cent) : ''}" placeholder="es. 300 oppure 300,50"></div>
    <div class="campo"><label>Metodo</label><select name="metodo">${opzioni(L.metodi_pagamento, m.metodo || L.metodi_pagamento[0])}</select></div>
    ${conFornitore ? `<div class="campo"><label>${obbligaFornitore ? 'Fornitore *' : 'Riferito al fornitore (facoltativo)'}</label>${selFornitore(m.fornitore_id || '', forn, obbligaFornitore ? '— scegli —' : '— nessuno in particolare —')}</div>` : ''}
    <div class="campo col2"><label>Descrizione</label><input name="descrizione" value="${esc(m.descrizione || '')}" placeholder="es. 1° acconto, saldo…"></div></div>`;

  // ---- salvataggio ----
  const leggiPratica = () => {
    const dati = leggiForm($('#fPratica'));
    dati.id = P.id; dati.anno = P.anno;
    dati.righe = $$('#listaServizi [data-srv]').map(card => ({
      descrizione: $(':scope > .srv-h [data-k=descrizione]', card).value, fornitore_id: $(':scope > .srv-h [name=fornitore_id]', card).value,
      prezzo: $(':scope > .srv-h [data-k=prezzo]', card).value, pax: $(':scope > .srv-h [data-k=pax]', card).value, stampa: $(':scope > .srv-h [data-k=stampa]', card).checked ? 1 : 0,
      figli: $$('[data-figlio]', card).map(f => ({ descrizione: $('[data-k=descrizione]', f).value, prezzo: $('[data-k=prezzo]', f).value, stampa: $('[data-k=stampa]', f).checked ? 1 : 0 }))
    }));
    dati.partecipanti = $$('#tabPart tbody tr').map(tr => ({ nome: $('[data-k=nome]', tr).value, nato_il: $('[data-k=nato_il]', tr).value }));
    return dati;
  };
  // ritorna la pratica salvata oppure null
  const salvaInterno = async () => {
    const dati = leggiPratica();
    if (!dati.cliente_id) { toast('Scegli il cliente intestatario', 'err'); $('#pkCliente').focus(); return null; }
    const b = $('#btnSalva'); b.disabled = true;
    try { const r = await api('pratica_salva', dati); sporca = false; return r; }
    catch (err) { toast(err.message, 'err'); return null; }
    finally { b.disabled = false; }
  };
  const salva = async () => {
    const r = await salvaInterno(); if (!r) return;
    toast(`Pratica ${r.numero}/${r.anno} salvata`, 'ok');
    if (nuova) naviga('#/pratiche/' + r.id); else aggiorna(r);
  };
  $('#btnSalva').onclick = salva;
  document.onkeydown = e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); salva(); } };
  GUARDIA = { route: STATO.route, sporca: () => sporca, salva: salvaInterno, cosa: 'la pratica' };

  $('#btnAddAcc').onclick = async () => {
    if (!P.id) { const r = await salvaInterno(); if (!r) return; naviga('#/pratiche/' + r.id + '/acconto'); return; }
    modale({ titolo: 'Registra acconto', ok: 'Registra', corpo: formMovimento({}, true, false), onOk: async d => aggiorna(await api('acconto_salva', { ...leggiForm(d), pratica_id: P.id })) });
  };
  if (!nuova) {
    $('#btnAddPag').onclick = () => modale({ titolo: 'Pagamento a fornitore', ok: 'Registra', corpo: formMovimento({}, true, true), onOk: async d => aggiorna(await api('pagamento_fornitore_salva', { ...leggiForm(d), pratica_id: P.id })) });
    pg.addEventListener('click', async e => {
      const t = e.target;
      try {
        if (t.dataset.accDel) { if (await conferma('Cancellare l\'acconto?', 'L\'operazione resta nel registro delle operazioni.')) aggiorna(await api('acconto_elimina', { id: +t.dataset.accDel })); }
        if (t.dataset.pagDel) { if (await conferma('Cancellare il pagamento?', '')) aggiorna(await api('pagamento_fornitore_elimina', { id: +t.dataset.pagDel })); }
        if (t.dataset.accMod) { const a = P.acconti.find(x => x.id === +t.dataset.accMod); modale({ titolo: 'Modifica acconto', ok: 'Salva', corpo: formMovimento(a, true, false), onOk: async d => aggiorna(await api('acconto_salva', { ...leggiForm(d), id: a.id, pratica_id: P.id })) }); }
        if (t.dataset.pagMod) { const g = P.pagamenti_fornitore.find(x => x.id === +t.dataset.pagMod); modale({ titolo: 'Modifica pagamento', ok: 'Salva', corpo: formMovimento(g, true, true), onOk: async d => aggiorna(await api('pagamento_fornitore_salva', { ...leggiForm(d), id: g.id, pratica_id: P.id })) }); }
      } catch (err) { toast(err.message, 'err'); }
    });
    $('#btnElimina').onclick = async () => {
      if (!await conferma('Eliminare la pratica?', `La pratica ${P.numero}/${P.anno} verrà cancellata. Se ha acconti, prima cancellali oppure mettila in stato Annullata.`)) return;
      try { sporca = false; await api('pratica_elimina', { id: P.id }); toast('Pratica eliminata'); naviga('#/pratiche'); } catch (err) { toast(err.message, 'err'); }
    };
    if (apriAcconto) { history.replaceState(null, '', '#/pratiche/' + P.id); STATO.route = 'pratiche/' + P.id; GUARDIA.route = STATO.route; setTimeout(() => $('#btnAddAcc').click(), 150); }
  }
}

function euroACent(v) {
  let s = String(v ?? '').trim().replace(/\s|€/g, '');
  if (!s) return 0;
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  const n = parseFloat(s); return Number.isFinite(n) ? Math.round(n * 100) : 0;
}

// Cerca-cliente con elenco a tendina e "nuovo cliente" al volo.
function pickerCliente(input, hidden, lista, onScelto) {
  let timer, risultati = [], sel = -1;
  const mostra = () => {
    lista.innerHTML = risultati.map((c, i) => `<div data-i="${i}" class="${i === sel ? 'sel' : ''}"><b>${esc(c.cognome)} ${esc(c.nome)}</b> <span class="muted small">${esc(c.citta || '')} ${esc(c.cellulare || c.telefono || '')} ${c.cf ? '· ' + esc(c.cf) : ''}</span></div>`).join('') + `<div class="nuovo" data-nuovo>+ Nuovo cliente "${esc(input.value)}"</div>`;
    lista.classList.remove('hidden');
  };
  const scegli = c => { hidden.value = c.id; input.value = `${c.cognome} ${c.nome}`.trim(); lista.classList.add('hidden'); onScelto && onScelto(c); };
  const cerca = async () => { risultati = await api('clienti', undefined, { q: input.value, limite: 12 }); sel = -1; mostra(); };
  input.addEventListener('input', () => { hidden.value = ''; clearTimeout(timer); timer = setTimeout(cerca, 200); });
  input.addEventListener('focus', () => { if (!hidden.value) cerca(); });
  input.addEventListener('keydown', e => {
    if (lista.classList.contains('hidden')) return;
    if (e.key === 'ArrowDown') { sel = Math.min(risultati.length - 1, sel + 1); mostra(); e.preventDefault(); }
    if (e.key === 'ArrowUp') { sel = Math.max(0, sel - 1); mostra(); e.preventDefault(); }
    if (e.key === 'Enter') { e.preventDefault(); if (sel >= 0) scegli(risultati[sel]); else if (!risultati.length) nuovo(); }
    if (e.key === 'Escape') lista.classList.add('hidden');
  });
  lista.addEventListener('mousedown', e => {
    const d = e.target.closest('[data-i]'); if (d) { e.preventDefault(); scegli(risultati[+d.dataset.i]); return; }
    if (e.target.closest('[data-nuovo]')) { e.preventDefault(); nuovo(); }
  });
  input.addEventListener('blur', () => setTimeout(() => lista.classList.add('hidden'), 150));
  const nuovo = () => {
    const parti = input.value.trim().split(/\s+/);
    modale({
      titolo: 'Nuovo cliente', ok: 'Crea',
      corpo: `<div class="griglia g2"><div class="campo"><label>Cognome *</label><input name="cognome" value="${esc(parti[0] || '')}"></div><div class="campo"><label>Nome</label><input name="nome" value="${esc(parti.slice(1).join(' '))}"></div>
        <div class="campo"><label>Cellulare</label><input name="cellulare"></div><div class="campo"><label>Email</label><input name="email"></div>
        <div class="campo"><label>Codice fiscale</label><input name="cf"></div><div class="campo"><label>Nato il</label><input name="nato_il" type="date"></div>
        <div class="campo col2"><label>Città</label><input name="citta"></div></div><div class="small muted" style="margin-top:8px">Gli altri dati si completano dalla scheda cliente.</div>`,
      onOk: async d => { const f = leggiForm(d); const r = await api('cliente_salva', f); scegli({ id: r.id, ...f }); toast('Cliente creato', 'ok'); }
    });
  };
}

// ================= CLIENTI =================
const FILTRI_CL = { q: '' };
async function paginaClienti() {
  shell('clienti', 'Clienti', `<div class="toolbar"><input class="inp cerca" id="clQ" placeholder="Cerca per cognome, nome, azienda, codice fiscale, telefono…" value="${esc(FILTRI_CL.q)}"><span class="muted small" id="clN"></span></div><div class="card" id="clTab"></div>`,
    `<a class="btn primario" href="#/clienti/nuovo">+ Nuovo cliente</a>`);
  let timer;
  const carica = async () => {
    const l = await api('clienti', undefined, { q: FILTRI_CL.q });
    $('#clN').textContent = `${l.length} clienti`;
    $('#clTab').innerHTML = l.length ? `<table class="t"><thead><tr><th>Cognome e nome</th><th>Azienda</th><th>Città</th><th>Cellulare</th><th>Telefono</th><th>Email</th><th>Cod. fiscale</th><th class="num">Pratiche</th><th>Ultimo contatto</th></tr></thead><tbody>
      ${l.map(c => `<tr class="click" onclick="naviga('#/clienti/${c.id}')"><td><b>${esc(c.cognome)}</b> ${esc(c.nome)}</td><td>${esc(c.azienda)}</td><td>${esc(c.citta)}</td><td>${esc(c.cellulare)}</td><td>${esc(c.telefono)}</td><td>${esc(c.email)}</td><td class="small">${esc(c.cf)}</td><td class="num">${c.n_pratiche}</td><td>${dataIt(c.ultimo_contatto)}</td></tr>`).join('')}</tbody></table>` : '<div class="vuoto">Nessun cliente</div>';
  };
  $('#clQ').oninput = e => { FILTRI_CL.q = e.target.value; clearTimeout(timer); timer = setTimeout(carica, 250); };
  await carica(); $('#clQ').focus();
}

async function paginaCliente(id) {
  const nuovo = id === 'nuovo';
  const C = nuovo ? { pratiche: [], statistiche: { perAnno: [], totali: { n: 0, venduto_cent: 0, versato_cent: 0 }, destinazioni: [] } } : await api('cliente', undefined, { id });
  const L = STATO.imp.liste;
  const campo = (n, lab, tipo = 'text', extra = '') => `<div class="campo ${extra}"><label>${lab}</label><input name="${n}" type="${tipo}" value="${esc(C[n] || '')}"></div>`;
  shell('clienti', nuovo ? 'Nuovo cliente' : `${esc(C.cognome)} ${esc(C.nome)}`, `
  <div class="griglia" style="grid-template-columns: 2fr 1fr">
  <div>
  <div id="fCliente">
  <div class="card"><h2>Anagrafica</h2><div class="griglia g4">
    ${campo('cognome', 'Cognome *')}${campo('nome', 'Nome')}${campo('azienda', 'Azienda', 'text', 'col2')}
    ${campo('indirizzo', 'Indirizzo', 'text', 'col2')}${campo('cap', 'CAP')}${campo('citta', 'Città')}
    ${campo('provincia', 'Provincia')}${campo('nazione', 'Nazione')}
    <div class="campo"><label>Sesso</label><select name="sesso">${opzioni([{ value: '', label: '—' }, { value: 'M', label: 'M' }, { value: 'F', label: 'F' }], C.sesso || '')}</select></div>
    ${campo('nato_il', 'Nato il', 'date')}${campo('nato_a', 'Luogo di nascita', 'text', 'col2')}
    ${campo('cf', 'Codice fiscale', 'text')}${campo('piva', 'Partita IVA')}
  </div></div>
  <div class="card"><h2>Contatti</h2><div class="griglia g4">
    ${campo('cellulare', 'Cellulare')}${campo('telefono', 'Telefono')}${campo('telefono2', 'Telefono 2')}${campo('email', 'Email', 'email')}
  </div></div>
  <div class="card"><h2>Documento</h2><div class="griglia g4">
    <div class="campo"><label>Tipo</label><input name="doc_tipo" list="dlDocCl" value="${esc(C.doc_tipo || '')}">${datalist('dlDocCl', L.tipi_documento_cliente)}</div>
    ${campo('doc_numero', 'Numero')}${campo('doc_rilasciato_da', 'Rilasciato da')}${campo('doc_rilasciato_il', 'Rilasciato il', 'date')}${campo('doc_scade', 'Scade il', 'date')}
  </div></div>
  <div class="card"><h2>Note</h2><div class="griglia g2">
    <div class="campo"><label>Note</label><textarea name="note">${esc(C.note || '')}</textarea></div>
    <div class="campo"><label>Viaggia con (compagni abituali)</label><textarea name="viaggia_con">${esc(C.viaggia_con || '')}</textarea></div>
  </div></div>
  </div>
  <div class="sticky-azioni"><button class="btn primario" id="btnSalva">Salva</button><span class="small muted">oppure Ctrl+S</span>${nuovo ? '' : `<a class="btn" href="#/pratiche/nuova/${C.id}">+ Nuova pratica per questo cliente</a>`}<span style="flex:1"></span>${nuovo ? '' : '<button class="btn pericolo" id="btnElimina">Elimina</button>'}<a class="btn" href="#/clienti">Torna all'elenco</a></div>
  </div>
  <div>
    ${nuovo ? '' : `<div class="card"><h2>Statistiche</h2>
      <div class="cards-stat" style="grid-template-columns:1fr 1fr"><div class="stat"><div class="l">Pratiche</div><div class="v">${C.statistiche.totali.n}</div></div><div class="stat"><div class="l">Totale speso</div><div class="v" style="font-size:18px">${eur(C.statistiche.totali.venduto_cent)}</div></div></div>
      ${C.statistiche.perAnno.length ? `<table class="t compatta"><thead><tr><th>Anno</th><th class="num">Pratiche</th><th class="num">Venduto</th><th class="num">Versato</th></tr></thead><tbody>${C.statistiche.perAnno.map(r => `<tr><td>${r.anno}</td><td class="num">${r.n}</td><td class="num">${eur(r.venduto_cent)}</td><td class="num">${eur(r.versato_cent)}</td></tr>`).join('')}</tbody></table>` : '<div class="muted small">Ancora nessuna pratica confermata</div>'}
      <div class="small muted" style="margin-top:8px">Cliente dal ${C.creato_ts ? new Date(C.creato_ts).toLocaleDateString('it-IT') : '—'} · ultimo contatto ${dataIt(C.ultimo_contatto) || '—'}</div></div>
    <div class="card"><h2>Pratiche</h2>${C.pratiche.length ? `<table class="t compatta"><thead><tr><th>N.</th><th>Partenza</th><th>Destinazione</th><th>Stato</th><th class="num">Saldo</th></tr></thead><tbody>
      ${C.pratiche.map(p => `<tr class="click" onclick="naviga('#/pratiche/${p.id}')"><td class="mono">${p.numero}/${p.anno}</td><td>${dataIt(p.partenza)}</td><td class="small">${esc((p.destinazione || '').split('\n')[0]).slice(0, 40)}</td><td>${badgeStato(p.stato)}</td><td class="num">${p.stato === 'Annullata' ? '—' : eur(p.saldo_cent)}</td></tr>`).join('')}</tbody></table>` : '<div class="muted small">Nessuna pratica</div>'}</div>`}
  </div></div>`);
  let sporca = false;
  $('#fCliente').addEventListener('input', () => { sporca = true; });
  const salvaInterno = async () => {
    const d = leggiForm($('#fCliente')); d.id = C.id || 0;
    try { const r = await api('cliente_salva', d); sporca = false; return r; } catch (e) { toast(e.message, 'err'); return null; }
  };
  const salva = async () => { const r = await salvaInterno(); if (!r) return; toast('Cliente salvato', 'ok'); if (nuovo) naviga('#/clienti/' + r.id); };
  GUARDIA = { route: STATO.route, sporca: () => sporca, salva: salvaInterno, cosa: 'la scheda del cliente' };
  $('#btnSalva').onclick = salva;
  document.onkeydown = e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); salva(); } };
  if (!nuovo) $('#btnElimina').onclick = async () => {
    if (!await conferma('Eliminare il cliente?', 'Possibile solo se non ha pratiche.')) return;
    try { await api('cliente_elimina', { id: C.id }); toast('Cliente eliminato'); naviga('#/clienti'); } catch (e) { toast(e.message, 'err'); }
  };
  if (nuovo) $('[name=cognome]').focus();
}

// ================= FORNITORI =================
const FILTRI_FO = { q: '', tutti: 0 };
async function paginaFornitori() {
  shell('fornitori', 'Fornitori', `<div class="toolbar"><input class="inp cerca" id="foQ" placeholder="Cerca per nome, tipo, città…" value="${esc(FILTRI_FO.q)}"><label class="small"><input type="checkbox" id="foTutti" ${FILTRI_FO.tutti ? 'checked' : ''}> mostra anche i disattivati</label><span class="muted small" id="foN"></span></div><div class="card" id="foTab"></div>`,
    `<a class="btn primario" href="#/fornitori/nuovo">+ Nuovo fornitore</a>`);
  let timer;
  const carica = async () => {
    const l = await api('fornitori', undefined, { q: FILTRI_FO.q, tutti: FILTRI_FO.tutti });
    FORNITORI_CACHE = null;
    $('#foN').textContent = `${l.length} fornitori`;
    $('#foTab').innerHTML = l.length ? `<table class="t"><thead><tr><th>Nome</th><th>Tipo</th><th>Città</th><th>Telefono</th><th>Email</th><th>Referente</th><th class="num">Pratiche</th></tr></thead><tbody>
      ${l.map(f => `<tr class="click" onclick="naviga('#/fornitori/${f.id}')"><td><b>${esc(f.nome)}</b> ${f.attivo ? '' : '<span class="badge grigio">disattivato</span>'}</td><td>${esc(f.tipo)}</td><td>${esc(f.citta)}</td><td>${esc(f.telefono)}</td><td>${esc(f.email)}</td><td>${esc(f.referente)}</td><td class="num">${f.n_pratiche}</td></tr>`).join('')}</tbody></table>` : '<div class="vuoto">Nessun fornitore</div>';
  };
  $('#foQ').oninput = e => { FILTRI_FO.q = e.target.value; clearTimeout(timer); timer = setTimeout(carica, 250); };
  $('#foTutti').onchange = e => { FILTRI_FO.tutti = e.target.checked ? 1 : 0; carica(); };
  await carica(); $('#foQ').focus();
}

async function paginaFornitore(id) {
  const nuovo = id === 'nuovo';
  const F = nuovo ? { attivo: 1, situazione: { pratiche: [], totali: {} }, statistiche: [] } : await api('fornitore', undefined, { id });
  const L = STATO.imp.liste;
  const campo = (n, lab, tipo = 'text', extra = '') => `<div class="campo ${extra}"><label>${lab}</label><input name="${n}" type="${tipo}" value="${esc(F[n] || '')}"></div>`;
  const st = F.statistiche || [];
  const max = Math.max(1, ...st.map(r => r.venduto_cent));
  shell('fornitori', nuovo ? 'Nuovo fornitore' : esc(F.nome), `
  <div class="griglia" style="grid-template-columns: 2fr 1fr"><div>
  <div id="fForn">
  <div class="card"><h2>Dati</h2><div class="griglia g4">
    ${campo('nome', 'Nome *', 'text', 'col2')}
    <div class="campo"><label>Tipo</label><input name="tipo" list="dlTipiF" value="${esc(F.tipo || '')}">${datalist('dlTipiF', L.tipi_fornitore)}</div>
    <div class="chk"><input type="checkbox" name="attivo" id="chkAttivo" ${F.attivo ? 'checked' : ''}><label for="chkAttivo">attivo (compare nelle liste)</label></div>
    ${campo('indirizzo', 'Indirizzo', 'text', 'col2')}${campo('cap', 'CAP')}${campo('citta', 'Città')}${campo('provincia', 'Provincia')}${campo('nazione', 'Nazione')}
    ${campo('piva', 'Partita IVA')}${campo('cf', 'Codice fiscale')}
    ${campo('telefono', 'Telefono')}${campo('fax', 'Fax')}${campo('email', 'Email', 'email')}${campo('web', 'Sito web')}
  </div></div>
  <div class="card"><h2>Referente e pagamenti</h2><div class="griglia g4">
    ${campo('referente', 'Referente / promoter')}${campo('referente_tel', 'Telefono referente')}${campo('referente_email', 'Email referente', 'email', 'col2')}
    ${campo('provvigione', 'Provvigione %')}${campo('pagamento', 'Modalità di pagamento')}${campo('iban', 'IBAN', 'text', 'col2')}${campo('banca', 'Banca', 'text', 'col2')}
    <div class="campo col4"><label>Note</label><textarea name="note">${esc(F.note || '')}</textarea></div>
  </div></div></div>
  <div class="sticky-azioni"><button class="btn primario" id="btnSalva">Salva</button><span class="small muted">oppure Ctrl+S</span><span style="flex:1"></span>${nuovo ? '' : '<button class="btn pericolo" id="btnElimina">Elimina</button>'}<a class="btn" href="#/fornitori">Torna all'elenco</a></div>
  ${nuovo ? '' : `<div class="card"><h2>Situazione pratiche</h2>
    <div class="cards-stat" style="grid-template-columns:repeat(4,1fr)">
      <div class="stat"><div class="l">Venduto (pratiche valide)</div><div class="v" style="font-size:18px">${eur(F.situazione.totali.venduto_cent)}</div></div>
      <div class="stat"><div class="l">Costo totale (dovuto)</div><div class="v" style="font-size:18px">${eur(F.situazione.totali.costo_cent)}</div></div>
      <div class="stat"><div class="l">Pagato</div><div class="v" style="font-size:18px">${eur(F.situazione.totali.pagato_cent)}</div></div>
      <div class="stat"><div class="l">Ancora da pagare</div><div class="v ${F.situazione.totali.dovuto_cent > 0 ? 'warn' : 'ok'}" style="font-size:18px">${eur(F.situazione.totali.dovuto_cent)}</div></div></div>
    ${F.situazione.pratiche.length ? `<table class="t compatta"><thead><tr><th>N.</th><th>Cliente</th><th>Partenza</th><th>Stato</th><th class="num">Venduto</th><th class="num">Costo</th><th class="num">Pagato</th><th class="num">Da pagare</th><th class="num">Cliente ha versato</th></tr></thead><tbody>
      ${F.situazione.pratiche.map(p => `<tr class="click" onclick="naviga('#/pratiche/${p.id}')"><td class="mono">${p.numero}/${p.anno}</td><td>${esc(p.cliente)}</td><td>${dataIt(p.partenza)}</td><td>${badgeStato(p.stato)}</td><td class="num">${eur(p.venduto_cent)}</td><td class="num">${eur(p.costo_cent)}</td><td class="num">${eur(p.pagato_cent)}</td><td class="num ${p.dovuto_cent > 0 ? 'warn' : ''}"><b>${eur(p.dovuto_cent)}</b></td><td class="num">${eur(p.cliente_versato_cent)}</td></tr>`).join('')}</tbody></table>` : '<div class="muted small">Nessuna pratica con questo fornitore</div>'}</div>`}
  </div>
  <div>${nuovo ? '' : `<div class="card"><h2>Confronto anni</h2>
    ${st.length ? st.map(r => `<div class="barra-h"><div class="lab"><b>${r.anno}</b> <span class="muted small">${r.n} pratiche</span></div><div class="b"><i style="width:${Math.round(100 * r.venduto_cent / max)}%"></i></div><div class="v">${eur(r.venduto_cent)}</div></div>`).join('') +
      `<table class="t compatta" style="margin-top:10px"><thead><tr><th>Anno</th><th class="num">Pratiche</th><th class="num">Venduto</th><th class="num">Costo</th><th class="num">Pagato</th><th class="num">Margine</th></tr></thead><tbody>${st.map(r => `<tr><td>${r.anno}</td><td class="num">${r.n}</td><td class="num">${eur(r.venduto_cent)}</td><td class="num">${eur(r.costo_cent)}</td><td class="num">${eur(r.pagato_cent)}</td><td class="num">${eur(r.venduto_cent - r.costo_cent)}</td></tr>`).join('')}</tbody></table>` : '<div class="muted small">Nessuna pratica confermata</div>'}</div>`}</div></div>`);
  let sporca = false;
  $('#fForn').addEventListener('input', () => { sporca = true; });
  const salvaInterno = async () => {
    const d = leggiForm($('#fForn')); d.id = F.id || 0;
    try { const r = await api('fornitore_salva', d); FORNITORI_CACHE = null; sporca = false; return r; } catch (e) { toast(e.message, 'err'); return null; }
  };
  const salva = async () => { const r = await salvaInterno(); if (!r) return; toast('Fornitore salvato', 'ok'); if (nuovo) naviga('#/fornitori/' + r.id); };
  GUARDIA = { route: STATO.route, sporca: () => sporca, salva: salvaInterno, cosa: 'la scheda del fornitore' };
  $('#btnSalva').onclick = salva;
  document.onkeydown = e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); salva(); } };
  if (!nuovo) $('#btnElimina').onclick = async () => {
    if (!await conferma('Eliminare il fornitore?', 'Possibile solo se non è usato in nessuna pratica; altrimenti disattivalo.')) return;
    try { await api('fornitore_elimina', { id: F.id }); FORNITORI_CACHE = null; toast('Fornitore eliminato'); naviga('#/fornitori'); } catch (e) { toast(e.message, 'err'); }
  };
  if (nuovo) $('[name=nome]').focus();
}

// ================= STATISTICHE =================
async function paginaStatistiche(annoSel) {
  const d = await api('statistiche', undefined, { anno: annoSel });
  const anno = d.anno;
  const anni = d.anni.length ? d.anni : [anno];
  const cur = d.perAnno.find(r => r.anno === anno) || { n: 0, n_valide: 0, venduto_cent: 0, costo_cent: 0, incassato_cent: 0 };
  // Grafico per mese: anno scelto + fino a 3 precedenti, colori in ordine fisso (anno scelto = serie 1)
  const anniGraf = [anno, anno - 1, anno - 2, anno - 3].filter(a => d.perMese.some(r => r.anno === a));
  if (!anniGraf.includes(anno)) anniGraf.unshift(anno);
  const val = (a, m) => (d.perMese.find(r => r.anno === a && r.mese === m) || {}).venduto_cent || 0;
  const maxV = Math.max(1, ...anniGraf.flatMap(a => MESI.map((_, i) => val(a, i + 1))));
  const W = 1000, H = 300, padL = 70, padB = 30, padT = 10, plotW = W - padL - 10, plotH = H - padT - padB;
  const gruppo = plotW / 12, barW = Math.max(4, (gruppo - 10) / anniGraf.length - 2);
  const scala = v => plotH * v / maxV;
  const tacche = 4;
  let svg = `<svg class="chart" viewBox="0 0 ${W} ${H}" role="img" aria-label="Valore pratiche per mese">`;
  for (let i = 0; i <= tacche; i++) {
    const y = padT + plotH - plotH * i / tacche;
    svg += `<line x1="${padL}" x2="${W - 10}" y1="${y}" y2="${y}" stroke="#e5e7eb"/><text x="${padL - 8}" y="${y + 4}" text-anchor="end" font-size="11" fill="#8a93a0">${eur(maxV * i / tacche).replace(',00', '')}</text>`;
  }
  MESI.forEach((m, mi) => {
    const x0 = padL + mi * gruppo + 5;
    svg += `<text x="${padL + mi * gruppo + gruppo / 2}" y="${H - 8}" text-anchor="middle" font-size="12" fill="#5b6470">${m}</text>`;
    anniGraf.forEach((a, ai) => {
      const v = val(a, mi + 1); if (!v) return;
      const h = scala(v), x = x0 + ai * (barW + 2), y = padT + plotH - h;
      svg += `<rect x="${x}" y="${y}" width="${barW}" height="${h}" rx="3" fill="${SERIE[ai]}"><title>${a} · ${m}: ${eurS(v)}</title></rect>`;
    });
  });
  svg += '</svg>';
  const legenda = `<div class="legenda">${anniGraf.map((a, i) => `<span><i style="background:${SERIE[i]}"></i>${a}</span>`).join('')}</div>`;
  const maxTipo = Math.max(1, ...d.perTipo.map(t => t.venduto_cent));
  const delta = (a, b) => b ? `<span class="${a >= b ? 'ok' : 'err'} small">${a >= b ? '▲' : '▼'} ${Math.round(100 * (a - b) / b)}%</span>` : '<span class="muted small">—</span>';

  shell('statistiche', `Statistiche ${anno}`, `
    <div class="cards-stat">
      <div class="stat"><div class="l">Pratiche aperte nel ${anno}</div><div class="v">${cur.n}</div><div class="s">${cur.n_valide} confermate/chiuse · ${d.perStato.map(s => `${s.n} ${s.stato.toLowerCase()}`).join(' · ')}</div></div>
      <div class="stat"><div class="l">Venduto</div><div class="v">${eur(cur.venduto_cent)}</div><div class="s">pratiche confermate e chiuse (+ penali)</div></div>
      <div class="stat"><div class="l">Incassato (acconti)</div><div class="v">${eur(cur.incassato_cent)}</div><div class="s">da incassare ${eur(d.da_incassare_cent)}</div></div>
      <div class="stat"><div class="l">Costi fornitori</div><div class="v">${eur(cur.costo_cent)}</div><div class="s">da pagare ${eur(d.da_pagare_fornitori_cent)}</div></div>
      <div class="stat"><div class="l">Margine</div><div class="v ${cur.venduto_cent - cur.costo_cent >= 0 ? 'ok' : 'err'}">${eur(cur.venduto_cent - cur.costo_cent)}</div><div class="s">venduto − costi fornitori</div></div>
    </div>
    <div class="card"><h2>Valore pratiche per mese (per data di apertura)</h2>${legenda}${svg}</div>
    <div class="griglia g2">
      <div class="card"><h2>Per anno</h2><table class="t compatta"><thead><tr><th>Anno</th><th class="num">Pratiche</th><th class="num">Venduto</th><th class="num">Costi</th><th class="num">Margine</th><th class="num">Incassato</th></tr></thead><tbody>
        ${d.perAnno.slice().reverse().map(r => `<tr class="click ${r.anno === anno ? '' : ''}" onclick="naviga('#/statistiche/${r.anno}')"><td><b>${r.anno}</b></td><td class="num">${r.n_valide}/${r.n}</td><td class="num">${eur(r.venduto_cent)}</td><td class="num">${eur(r.costo_cent)}</td><td class="num">${eur(r.venduto_cent - r.costo_cent)}</td><td class="num">${eur(r.incassato_cent)}</td></tr>`).join('')}</tbody></table>
        <div class="small muted" style="margin-top:6px">Pratiche: valide/totali. Tocca un anno per vederlo.</div></div>
      <div class="card"><h2>Per tipo pratica (${anno})</h2>${d.perTipo.length ? d.perTipo.map(t => `<div class="barra-h"><div class="lab">${esc(t.tipo)} <span class="muted small">${t.n}</span></div><div class="b"><i style="width:${Math.round(100 * t.venduto_cent / maxTipo)}%"></i></div><div class="v">${eur(t.venduto_cent)}</div></div>`).join('') : '<div class="muted small">Nessun dato</div>'}</div>
    </div>
    <div class="griglia g2">
      <div class="card"><h2>Per fornitore (${anno} contro ${anno - 1})</h2>${d.perFornitore.length ? `<table class="t compatta"><thead><tr><th>Fornitore</th><th class="num">Pratiche</th><th class="num">Venduto ${anno}</th><th class="num">Venduto ${anno - 1}</th><th class="num">Var.</th><th class="num">Margine</th></tr></thead><tbody>
        ${d.perFornitore.map(f => `<tr class="click" onclick="naviga('#/fornitori/${f.id}')"><td>${esc(f.nome)}</td><td class="num">${f.n}${f.n_prec ? ` <span class="muted small">(${f.n_prec})</span>` : ''}</td><td class="num">${eur(f.venduto_cent)}</td><td class="num">${eur(f.venduto_prec_cent)}</td><td class="num">${delta(f.venduto_cent, f.venduto_prec_cent)}</td><td class="num">${eur(f.venduto_cent - f.costo_cent)}</td></tr>`).join('')}</tbody></table>` : '<div class="muted small">Nessun dato</div>'}</div>
      <div>
        <div class="card"><h2>Per operatore (${anno})</h2>${d.perOperatore.length ? `<table class="t compatta"><thead><tr><th>Operatore</th><th class="num">Pratiche</th><th class="num">Venduto</th><th class="num">Margine</th></tr></thead><tbody>${d.perOperatore.map(o => `<tr><td>${esc(o.operatore)}</td><td class="num">${o.n}</td><td class="num">${eur(o.venduto_cent)}</td><td class="num">${eur(o.margine_cent)}</td></tr>`).join('')}</tbody></table>` : '<div class="muted small">Nessun dato</div>'}</div>
        <div class="card"><h2>Migliori clienti (${anno})</h2>${d.topClienti.length ? `<table class="t compatta"><thead><tr><th>Cliente</th><th class="num">Pratiche</th><th class="num">Venduto</th></tr></thead><tbody>${d.topClienti.map(c => `<tr class="click" onclick="naviga('#/clienti/${c.id}')"><td>${esc(c.cliente)}</td><td class="num">${c.n}</td><td class="num">${eur(c.venduto_cent)}</td></tr>`).join('')}</tbody></table>` : '<div class="muted small">Nessun dato</div>'}</div>
      </div>
    </div>`,
    `<select id="stAnno" class="inp">${opzioni(anni.map(String), String(anno))}</select>`);
  $('#stAnno').onchange = e => naviga('#/statistiche/' + e.target.value);
}

// ================= OPZIONI (pannello) =================
async function paginaOpzioni(scheda = 'agenzia') {
  if (!STATO.pannello) {
    shell('opzioni', 'Opzioni', `<div class="card" style="max-width:460px"><h2>Pannello opzioni</h2><p class="muted">Qui si cambiano i dati dell'agenzia, i testi legali della stampa, la numerazione, le liste, il backup e gli utenti. Serve la password del pannello.</p>
      <form id="fPan"><div class="campo"><label>Password del pannello</label><input name="password" type="password" autofocus></div><button class="btn primario" style="margin-top:12px">Sblocca</button></form></div>`);
    $('#fPan').onsubmit = async e => { e.preventDefault(); try { await api('pannello_sblocca', leggiForm($('#fPan'))); STATO.pannello = true; render(); } catch (err) { toast(err.message, 'err'); } };
    return;
  }
  const imp = STATO.imp = await api('impostazioni');
  const SCHEDE = [['agenzia', 'Agenzia'], ['testi', 'Testi della stampa'], ['liste', 'Liste'], ['numerazione', 'Numerazione'], ['backup', 'Backup'], ['utenti', 'Utenti'], ['log', 'Registro operazioni']];
  const tabs = `<div class="tabs">${SCHEDE.map(([k, l]) => `<a href="#/opzioni/${k}" class="${scheda === k ? 'attivo' : ''}">${l}</a>`).join('')}</div>`;
  let corpo = '';
  const A = imp.agenzia, T = imp.testi;
  const campo = (obj, n, lab, extra = '', tipo = 'input') => `<div class="campo ${extra}"><label>${lab}</label>${tipo === 'textarea' ? `<textarea name="${n}" style="min-height:${n === 'privacy' || n === 'dichiara' ? 160 : 60}px">${esc(obj[n] || '')}</textarea>` : `<input name="${n}" value="${esc(obj[n] || '')}">`}</div>`;
  if (scheda === 'agenzia') corpo = `<div class="card" id="fOpz"><h2>Intestazione della stampa</h2><div class="griglia g4">
    ${campo(A, 'insegna', 'Insegna (titolo grande)', 'col2')}${campo(A, 'ragione_sociale', 'Ragione sociale', 'col2')}${campo(A, 'titolare', 'Titolare ("di …")', 'col2')}
    ${campo(A, 'indirizzo', 'Indirizzo', 'col2')}${campo(A, 'cap', 'CAP')}${campo(A, 'citta', 'Città')}${campo(A, 'provincia', 'Provincia')}${campo(A, 'piva', 'Partita IVA')}
    ${campo(A, 'cf', 'Codice fiscale')}${campo(A, 'telefono', 'Telefono')}${campo(A, 'email', 'Email', 'col2')}${campo(A, 'web', 'Sito web', 'col2')}${campo(A, 'sede_legale', 'Sede legale (riga in piccolo)', 'col2')}${campo(A, 'iban', 'IBAN per i bonifici (in stampa)', 'col2')}
    <div class="campo col4"><label>Logo per la stampa (PNG/JPG, facoltativo)</label><div class="riga"><input type="file" id="inLogo" accept="image/*" style="width:auto">${A.logo ? `<img src="${A.logo}" style="height:48px;border:1px solid var(--border);border-radius:4px"> <button class="btn piccolo" type="button" id="btnLogoVia">togli logo</button>` : '<span class="muted small">nessun logo</span>'}</div><input type="hidden" name="logo" value="${esc(A.logo || '')}"></div>
    </div><div class="azioni" style="margin-top:14px"><button class="btn primario" id="btnSalvaOpz">Salva</button></div></div>`;
  if (scheda === 'testi') corpo = `<div class="card" id="fOpz"><h2>Testi legali della conferma di prenotazione</h2><div class="griglia g2">
    ${campo(T, 'titolo', 'Titolo', 'col2')}${campo(T, 'sottotitolo', 'Sottotitolo', 'col2', 'textarea')}${campo(T, 'diritto_copia', 'Diritto alla copia', 'col2', 'textarea')}
    ${campo(T, 'in_nome', 'Frase "in nome e per conto…"', 'col2')}${campo(T, 'dichiara', 'Dichiarazioni (una per riga)', 'col2', 'textarea')}
    ${campo(T, 'propone', 'Propone…')}${campo(T, 'stipula', '…la stipulazione')}${campo(T, 'validita', 'Validità della proposta', 'col2', 'textarea')}
    ${campo(T, 'firma', 'Etichetta firma')}${campo(T, 'firma_nota', 'Nota sotto la firma')}${campo(T, 'privacy', 'Informativa privacy', 'col2', 'textarea')}
    </div><div class="azioni" style="margin-top:14px"><button class="btn primario" id="btnSalvaOpz">Salva</button></div></div>`;
  if (scheda === 'liste') {
    const NOMI = { tipi_pratica: 'Tipi di pratica', tipi_fornitore: 'Tipi di fornitore', metodi_pagamento: 'Metodi di pagamento', documenti_necessari: 'Documenti necessari (pratica)', descrizioni_servizi: 'Descrizioni servizi (suggerimenti)', tipi_documento_cliente: 'Tipi di documento (cliente)' };
    corpo = `<div class="card" id="fOpz"><h2>Liste a tendina</h2><p class="muted small">Una voce per riga. Sono suggerimenti: nei campi si può comunque scrivere a mano.</p><div class="griglia g3">
      ${Object.keys(NOMI).map(k => `<div class="campo"><label>${NOMI[k]}</label><textarea name="${k}" style="min-height:160px">${esc((imp.liste[k] || []).join('\n'))}</textarea></div>`).join('')}
      </div><div class="azioni" style="margin-top:14px"><button class="btn primario" id="btnSalvaOpz">Salva</button></div></div>`;
  }
  if (scheda === 'numerazione') corpo = `<div class="card" id="fOpz" style="max-width:560px"><h2>Numerazione pratiche</h2><p class="muted small">Il numero è progressivo e riparte da 1 ogni anno. Solo per l'anno indicato qui si può partire da un numero diverso (es. per continuare la numerazione del vecchio programma). Vale finché non esistono pratiche in quell'anno.</p>
    <div class="griglia g2"><div class="campo"><label>Anno</label><input name="anno_iniziale" class="num" value="${imp.numerazione.anno_iniziale}"></div><div class="campo"><label>Primo numero di quell'anno</label><input name="numero_iniziale" class="num" value="${imp.numerazione.numero_iniziale}"></div></div>
    <div class="azioni" style="margin-top:14px"><button class="btn primario" id="btnSalvaOpz">Salva</button></div></div>`;
  if (scheda === 'backup') corpo = `<div class="card" id="fOpz" style="max-width:640px"><h2>Backup automatico</h2><p class="muted small">Ogni giorno, all'ora indicata, il programma copia l'archivio nella cartella scelta (es. una chiavetta o una cartella di rete: <code>D:\\Backup agenzia</code> oppure <code>\\\\ALTRO-PC\\backup</code>). Tiene gli ultimi 30 file. Per ripristinare: fermare il programma e copiare il file al posto di <code>_dati\\agenzia.db</code>.</p>
    <div class="griglia g2"><div class="campo col2"><label>Cartella dei backup (sul PC centrale)</label><input name="cartella" value="${esc(imp.backup.cartella)}"></div><div class="campo"><label>Ora (HH:MM)</label><input name="ora" value="${esc(imp.backup.ora)}"></div>
    <div class="campo"><label>Ultimo backup</label><input readonly value="${imp.backup.ultimo ? new Date(imp.backup.ultimo).toLocaleString('it-IT') : 'mai'}"></div></div>
    <div class="azioni" style="margin-top:14px"><button class="btn primario" id="btnSalvaOpz">Salva</button><button class="btn" id="btnBackupOra">Backup adesso</button></div></div>
    <div class="card" style="max-width:640px"><h2>Copia dei dati: scarica / importa</h2>
      <p class="muted small">Per portare i dati su un altro PC, mandarli all'assistenza o fare una copia a mano. Il file contiene TUTTO (clienti, pratiche, acconti, utenti, impostazioni).</p>
      <div class="azioni"><a class="btn" href="/api/esporta_db">⬇ Scarica una copia dei dati (.db)</a>
        <label class="btn pericolo" for="inImporta">⬆ Importa un file di dati…</label><input type="file" id="inImporta" accept=".db" class="hidden"></div>
      <p class="small muted" style="margin-top:8px">Importare <b>sostituisce tutti i dati attuali</b> con quelli del file. I dati attuali vengono comunque messi da parte in <code>_dati\\prima-import-….db</code>. Dopo l'importazione bisogna rientrare con gli utenti del file importato.</p></div>
    <div class="card" style="max-width:640px"><h2>Password del pannello</h2><div class="griglia g2"><div class="campo"><label>Nuova password del pannello</label><input id="inPanPw" type="password"></div><div class="campo"><label>&nbsp;</label><button class="btn" id="btnPanPw">Cambia</button></div></div></div>`;
  if (scheda === 'utenti') {
    const ut = await api('utenti');
    corpo = `<div class="card"><h2>Utenti <span class="spazio"></span><button class="btn piccolo primario" id="btnNuovoUt">+ Nuovo utente</button></h2>
      <table class="t compatta"><thead><tr><th>Nome</th><th>Username</th><th>Password</th><th>Stato</th><th></th></tr></thead><tbody>${ut.map(u => `<tr><td>${esc(u.nome)}</td><td class="mono">${esc(u.username)}</td><td>${u.pass_chiaro ? `<span class="pw-nascosta mono" data-pw="${esc(u.pass_chiaro)}">••••••</span> <button class="btn piccolo" data-occhio>👁</button>` : '<span class="muted small">non nota: reimpostala da "modifica"</span>'}</td><td>${u.attivo ? '<span class="badge Confermata">attivo</span>' : '<span class="badge grigio">disattivato</span>'}</td><td class="az"><button class="btn piccolo" data-ut="${u.id}">modifica</button></td></tr>`).join('')}</tbody></table>
      <p class="muted small">Tutti gli utenti hanno gli stessi permessi. Ogni operazione viene registrata col nome di chi l'ha fatta. Un utente disattivato non può più entrare ma resta nello storico.</p></div>`;
  }
  if (scheda === 'log') {
    const lg = await api('log', undefined, { limite: 300 });
    corpo = `<div class="card"><h2>Ultime operazioni</h2><table class="t compatta"><thead><tr><th>Quando</th><th>Chi</th><th>Operazione</th><th>Su</th><th>Dettaglio</th></tr></thead><tbody>${lg.map(r => `<tr><td class="nowrap">${new Date(r.ts).toLocaleString('it-IT')}</td><td>${esc(r.utente)}</td><td>${esc(r.azione)}</td><td>${r.entita ? `<a href="#/${r.entita === 'pratica' ? 'pratiche' : r.entita === 'cliente' ? 'clienti' : r.entita === 'fornitore' ? 'fornitori' : 'opzioni/utenti'}/${r.entita_id}">${esc(r.entita)} ${r.entita_id || ''}</a>` : ''}</td><td class="small">${esc(r.dettaglio)}</td></tr>`).join('')}</tbody></table></div>`;
  }
  shell('opzioni', 'Opzioni', tabs + corpo, `<button class="btn" id="btnBlocca">🔒 Blocca pannello</button>`);
  $('#btnBlocca').onclick = async () => { await api('pannello_blocca', {}); STATO.pannello = false; render(); };

  const salvaOpz = async () => {
    const f = leggiForm($('#fOpz'));
    let payload = {};
    if (scheda === 'liste') { payload.liste = {}; for (const k of Object.keys(f)) payload.liste[k] = f[k].split('\n').map(s => s.trim()).filter(Boolean); }
    else payload[scheda] = f;
    try { STATO.imp = await api('impostazioni_salva', payload); toast('Impostazioni salvate', 'ok'); if (scheda === 'agenzia') render(); } catch (e) { toast(e.message, 'err'); }
  };
  if ($('#btnSalvaOpz')) $('#btnSalvaOpz').onclick = salvaOpz;
  if ($('#inLogo')) $('#inLogo').onchange = e => {
    const f = e.target.files[0]; if (!f) return;
    const img = new Image(); const url = URL.createObjectURL(f);
    img.onload = () => {
      const cv = document.createElement('canvas'); const sc = Math.min(1, 400 / img.width, 200 / img.height);
      cv.width = Math.round(img.width * sc); cv.height = Math.round(img.height * sc);
      cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
      $('[name=logo]').value = cv.toDataURL('image/png'); toast('Logo pronto: premi Salva');
    };
    img.src = url;
  };
  if ($('#btnLogoVia')) $('#btnLogoVia').onclick = () => { $('[name=logo]').value = ''; toast('Logo tolto: premi Salva'); };
  if ($('#btnBackupOra')) $('#btnBackupOra').onclick = async () => { try { const r = await api('backup_ora', { cartella: $('[name=cartella]').value }); toast('Backup fatto: ' + r.file, 'ok'); } catch (e) { toast(e.message, 'err'); } };
  $$('[data-occhio]').forEach(b => b.onclick = () => { const sp = b.previousElementSibling; const on = sp.textContent === '••••••'; sp.textContent = on ? sp.dataset.pw : '••••••'; });
  if ($('#inImporta')) $('#inImporta').onchange = async e => {
    const f = e.target.files[0]; if (!f) return;
    e.target.value = '';
    if (!await conferma('Importare questo file?', `Tutti i dati attuali verranno sostituiti da quelli di "${f.name}" (una copia di quelli attuali resta nella cartella _dati). Dovrai rientrare con un utente del file importato.`)) return;
    try {
      const r = await fetch('/api/importa_db', { method: 'POST', headers: { 'Content-Type': 'application/octet-stream' }, body: f });
      const d = await r.json(); if (!r.ok) throw new Error(d.errore || 'Errore');
      await modale({ titolo: 'Importazione fatta', senzaOk: true, annulla: 'OK', corpo: `<p>Importate <b>${d.pratiche}</b> pratiche. I dati precedenti sono in:<br><code>${esc(d.copia_precedente)}</code></p><p>Ora rientra con un utente del file importato.</p>` });
      location.reload();
    } catch (err) { toast(err.message, 'err'); }
  };
  if ($('#btnPanPw')) $('#btnPanPw').onclick = async () => { try { await api('pannello_password', { nuova: $('#inPanPw').value }); toast('Password del pannello cambiata', 'ok'); $('#inPanPw').value = ''; } catch (e) { toast(e.message, 'err'); } };
  const formUtente = u => `<div class="griglia g2"><div class="campo"><label>Nome (compare nelle pratiche)</label><input name="nome" value="${esc(u.nome || '')}"></div><div class="campo"><label>Username</label><input name="username" value="${esc(u.username || '')}"></div>
    <div class="campo"><label>${u.id ? 'Nuova password (vuoto = non cambia)' : 'Password'}</label><input name="password" type="password"></div>${u.id ? `<div class="chk"><input type="checkbox" name="attivo" id="chkUt" ${u.attivo ? 'checked' : ''}><label for="chkUt">attivo</label></div>` : ''}</div>`;
  if ($('#btnNuovoUt')) $('#btnNuovoUt').onclick = () => modale({ titolo: 'Nuovo utente', ok: 'Crea', corpo: formUtente({}), onOk: async d => { await api('utenti_salva', leggiForm(d)); toast('Utente creato', 'ok'); render(); } });
  $$('[data-ut]').forEach(b => b.onclick = async () => {
    const u = (await api('utenti')).find(x => x.id === +b.dataset.ut);
    modale({ titolo: 'Modifica utente', ok: 'Salva', corpo: formUtente(u), onOk: async d => { await api('utenti_salva', { ...leggiForm(d), id: u.id }); toast('Utente salvato', 'ok'); render(); } });
  });
}

avvio();
