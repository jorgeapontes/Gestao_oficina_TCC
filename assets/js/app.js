// ═════════════════════════════════════════════════════════════════════════════
// IMM — comportamentos comuns a todas as páginas
//   • menu lateral no celular        • tema claro/escuro
//   • painel de notificações         • linhas de tabela clicáveis (data-href)
//   • modais (abrirModal/fecharModal) • confirmação estilizada (data-confirm)
//   • visualizador de fotos (data-lightbox) • toast que some sozinho
// ═════════════════════════════════════════════════════════════════════════════

const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

// ─── TEMA ────────────────────────────────────────────────────────────────────
function temaAtual() {
  const salvo = document.documentElement.dataset.theme;
  if (salvo) return salvo;
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}
function atualizarRotuloTema() {
  $$('[data-theme-toggle] .theme-label').forEach(el => {
    el.textContent = temaAtual() === 'dark' ? 'Modo claro' : 'Modo escuro';
  });
}
document.addEventListener('click', e => {
  if (!e.target.closest('[data-theme-toggle]')) return;
  const novo = temaAtual() === 'dark' ? 'light' : 'dark';
  document.documentElement.dataset.theme = novo;
  try { localStorage.setItem('imm-tema', novo); } catch (err) { /* modo privado */ }
  atualizarRotuloTema();
  document.dispatchEvent(new CustomEvent('temachange', { detail: novo }));
});

// ─── MENU LATERAL (CELULAR) ──────────────────────────────────────────────────
function fecharMenu() { document.body.classList.remove('sidebar-open'); }
document.addEventListener('click', e => {
  if (e.target.closest('[data-sidebar-toggle]')) {
    document.body.classList.toggle('sidebar-open');
    fecharNotificacoes();
  } else if (e.target.closest('[data-sidebar-close]')) {
    fecharMenu();
  }
});

// ─── NOTIFICAÇÕES ────────────────────────────────────────────────────────────
function fecharNotificacoes() { $('#notif-panel')?.classList.remove('open'); }
document.addEventListener('click', e => {
  const painel = $('#notif-panel');
  if (!painel) return;
  if (e.target.closest('[data-notif-toggle]')) {
    painel.classList.toggle('open');
    fecharMenu();
  } else if (!e.target.closest('#notif-panel')) {
    painel.classList.remove('open');
  }
});

// ─── LINHAS CLICÁVEIS ────────────────────────────────────────────────────────
// <tr data-href="..."> navega ao clicar, exceto em links, botões e formulários.
document.addEventListener('click', e => {
  const tr = e.target.closest('tr[data-href]');
  if (!tr || e.target.closest('a, button, form, input, select, textarea, label')) return;
  location.href = tr.dataset.href;
});

// ─── MODAIS ──────────────────────────────────────────────────────────────────
function abrirModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  m.classList.add('open');
  setTimeout(() => m.querySelector('input:not([type=hidden]):not([disabled]), select, textarea')?.focus(), 60);
}
function fecharModal(id) { document.getElementById(id)?.classList.remove('open'); }

// Clique no fundo escuro fecha o modal.
document.addEventListener('mousedown', e => {
  if (e.target.classList?.contains('modal-backdrop') && !e.target.dataset.static) e.target.classList.remove('open');
});
document.addEventListener('click', e => {
  const btn = e.target.closest('[data-close-modal]');
  if (btn) btn.closest('.modal-backdrop')?.classList.remove('open');
});

// Esc fecha o que estiver aberto (lightbox → modal → notificações → menu).
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  const lb = $('.lightbox.open');
  if (lb) { lb.classList.remove('open'); return; }
  const abertos = $$('.modal-backdrop.open');
  if (abertos.length) { abertos[abertos.length - 1].classList.remove('open'); return; }
  fecharNotificacoes();
  fecharMenu();
});

// ─── CONFIRMAÇÃO ESTILIZADA ──────────────────────────────────────────────────
// Uso: <form data-confirm="Mensagem"> ou <button type="submit" data-confirm="...">
// Opcionais: data-confirm-title, data-confirm-ok (rótulo do botão), data-confirm-danger.
const ICONE_ALERTA = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>';
const ICONE_PERGUNTA = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3M12 17h.01"/></svg>';

function confirmar({ titulo = 'Confirmar ação', mensagem = '', ok = 'Confirmar', perigo = false } = {}) {
  return new Promise(resolve => {
    const fundo = document.createElement('div');
    fundo.className = 'modal-backdrop';
    fundo.dataset.static = '1';
    fundo.innerHTML = `
      <div class="modal confirm-modal${perigo ? ' danger' : ''}" role="alertdialog" aria-modal="true">
        <div class="modal-body">
          <div class="confirm-icon">${perigo ? ICONE_ALERTA : ICONE_PERGUNTA}</div>
          <div class="confirm-title"></div>
          <div class="confirm-msg"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-outline" data-r="0">Voltar</button>
          <button type="button" class="${perigo ? 'btn-danger' : 'btn-primary'}" data-r="1"></button>
        </div>
      </div>`;
    $('.confirm-title', fundo).textContent = titulo;
    $('.confirm-msg', fundo).textContent = mensagem;
    $('[data-r="1"]', fundo).textContent = ok;
    document.body.appendChild(fundo);
    requestAnimationFrame(() => fundo.classList.add('open'));
    $('[data-r="1"]', fundo).focus();

    const fechar = valor => {
      fundo.classList.remove('open');
      document.removeEventListener('keydown', teclas, true);
      setTimeout(() => fundo.remove(), 200);
      resolve(valor);
    };
    const teclas = ev => {
      if (ev.key === 'Escape') { ev.stopPropagation(); fechar(false); }
    };
    document.addEventListener('keydown', teclas, true);
    fundo.addEventListener('click', ev => {
      const b = ev.target.closest('[data-r]');
      if (b) fechar(b.dataset.r === '1');
      else if (ev.target === fundo) fechar(false);
    });
  });
}

document.addEventListener('submit', e => {
  const form = e.target;
  const origem = (e.submitter && e.submitter.dataset.confirm) ? e.submitter : form;
  if (!origem.dataset.confirm || form.dataset.confirmado) return;
  e.preventDefault();
  confirmar({
    titulo:   origem.dataset.confirmTitle || 'Confirmar ação',
    mensagem: origem.dataset.confirm,
    ok:       origem.dataset.confirmOk || 'Confirmar',
    perigo:   'confirmDanger' in origem.dataset,
  }).then(ok => {
    if (!ok) return;
    form.dataset.confirmado = '1';
    // requestSubmit preserva o botão clicado (name/value) no envio.
    if (e.submitter && form.requestSubmit) form.requestSubmit(e.submitter);
    else form.submit();
  });
});

// ─── VISUALIZADOR DE FOTOS ───────────────────────────────────────────────────
document.addEventListener('click', e => {
  const link = e.target.closest('a[data-lightbox]');
  if (!link) return;
  e.preventDefault();
  let lb = $('#lightbox');
  if (!lb) {
    lb = document.createElement('div');
    lb.id = 'lightbox';
    lb.className = 'lightbox';
    lb.innerHTML = '<button type="button" class="lightbox-close" aria-label="Fechar">×</button><img alt=""/><div class="lightbox-caption"></div>';
    lb.addEventListener('click', ev => { if (ev.target.tagName !== 'IMG') lb.classList.remove('open'); });
    document.body.appendChild(lb);
  }
  $('img', lb).src = link.href;
  $('.lightbox-caption', lb).textContent = link.dataset.caption || '';
  lb.classList.add('open');
});

// ─── MÁSCARAS REUTILIZÁVEIS ──────────────────────────────────────────────────
const mascaraCpf = v => v.replace(/\D/g, '').substring(0, 11)
  .replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
const mascaraTel = v => {
  v = v.replace(/\D/g, '').substring(0, 11);
  if (v.length > 10) return v.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
  if (v.length > 6)  return v.replace(/(\d{2})(\d{4})(\d+)/, '($1) $2-$3');
  if (v.length > 2)  return v.replace(/(\d{2})(\d+)/, '($1) $2');
  return v;
};
// <input data-mask="cpf|tel|placa">
document.addEventListener('input', e => {
  const m = e.target.dataset?.mask;
  if (!m) return;
  if (m === 'cpf') e.target.value = mascaraCpf(e.target.value);
  if (m === 'tel') e.target.value = mascaraTel(e.target.value);
  if (m === 'placa') e.target.value = e.target.value.toUpperCase();
});

// ─── MARCA DO VEÍCULO (lista + "Outra marca") ────────────────────────────────
// Escolher "Outra marca" no #f-marca mostra o campo de texto #f-marca-outra.
function alternarMarcaOutra() {
  const sel = $('#f-marca'), inp = $('#f-marca-outra');
  if (!sel || !inp) return false;
  const outra = sel.value === '__outra';
  inp.hidden = inp.disabled = !outra;
  inp.required = outra;
  return outra;
}
// Preenche a marca ao abrir o formulário: se não estiver na lista, vai para "Outra marca".
function preencherMarca(nome) {
  const sel = $('#f-marca'), inp = $('#f-marca-outra');
  if (!sel || !inp) return;
  const naLista = !nome || Array.from(sel.options).some(o => o.value === nome);
  sel.value = naLista ? (nome || '') : '__outra';
  inp.value = naLista ? '' : nome;
  alternarMarcaOutra();
}
document.addEventListener('change', e => {
  if (e.target.id === 'f-marca' && alternarMarcaOutra()) $('#f-marca-outra').focus();
});

// ─── INICIALIZAÇÃO ───────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  atualizarRotuloTema();
  const toast = $('#toast');
  if (toast) setTimeout(() => { toast.style.opacity = 0; setTimeout(() => toast.remove(), 450); }, 4500);
});
window.matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', atualizarRotuloTema);
