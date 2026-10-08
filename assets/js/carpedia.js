// ═════════════════════════════════════════════════════════════════════════════
// IMM — seleção marca → modelo → versão → ano pelo catálogo da Carpedia (formulários de veículo)
// O navegador só chama comum/carpedia.php; se a Carpedia estiver fora do ar, o formulário
// continua no modo manual (marca escolhida, modelo e ano digitados).
//
// Modos (campo hidden #f-modo): 'manual' e 'carpedia'. Cada modo fica num <fieldset>;
// o que está desativado não é enviado nem validado. A versão só serve para listar os
// anos-modelo; sem versão escolhida, o ano é digitado.
// ═════════════════════════════════════════════════════════════════════════════
const Carpedia = (() => {
  const ENDPOINT = '../comum/carpedia.php';
  let marcas;                   // undefined = ainda não carregou; null = indisponível; array = lista
  let anosDaVersao = new Map(); // id da versão → anos-modelo
  const seq = { modelos: 0, versoes: 0 };   // descarta respostas atrasadas

  const el = id => document.getElementById(id);

  async function chamar(params) {
    try {
      const r = await fetch(ENDPOINT + '?' + new URLSearchParams(params), { headers: { Accept: 'application/json' } });
      if (!r.ok || r.redirected) return null;     // sessão expirada vira HTML no login
      const j = await r.json();
      return j && j.ok && Array.isArray(j.itens) ? j.itens : null;
    } catch (err) { return null; }
  }

  function preencher(select, itens, vazio, valor, rotulo) {
    select.replaceChildren(new Option(vazio, ''));
    itens.forEach(i => select.appendChild(new Option(rotulo(i), valor(i))));
    select.disabled = false;
  }
  function limpar(select, texto) {
    select.replaceChildren(new Option(texto, ''));
    select.disabled = true;
  }
  function aviso(texto) {
    el('c-aviso').textContent = texto || '';
    el('c-aviso').hidden = !texto;
  }

  // Ano digitado (modo manual, ou catálogo sem versão) x ano-modelo da versão escolhida.
  function atualizarAno() {
    const comVersao = el('f-modo').value === 'carpedia' && el('c-versao').value !== '';
    el('c-ano-wrap').hidden = !comVersao;
    el('c-ano').disabled = !comVersao;
    el('ano-wrap').hidden = comVersao;
    el('f-ano').disabled = comVersao;
  }

  function definirModo(modo) {
    const carp = modo === 'carpedia';
    el('f-modo').value = modo;
    el('f-manual').hidden = carp;  el('f-manual').disabled = carp;
    el('c-bloco').hidden = !carp;  el('c-bloco').disabled = !carp;
    el('c-toggle').textContent = carp ? 'Informar manualmente' : 'Escolher pela Carpedia';
    atualizarAno();
  }

  function zerar() {
    seq.modelos++; seq.versoes++;
    aviso('');
    el('c-marca').value = '';
    limpar(el('c-modelo'), 'Selecione a marca');
    limpar(el('c-versao'), 'Selecione o modelo');
    el('c-ano').replaceChildren();
    atualizarAno();
  }

  // Passa para o modo manual aproveitando o que já foi escolhido.
  function irParaManual() {
    const marca = el('c-marca').selectedOptions[0]?.textContent || '';
    const modelo = el('c-modelo').selectedOptions[0];
    const ano = el('c-ano').value;
    if (modelo && modelo.value) el('f-modelo').value = modelo.textContent;
    if (ano && el('c-versao').value) el('f-ano').value = ano;
    const alvo = Array.from(el('f-marca').options)
      .find(o => o.value && o.textContent.trim().toLowerCase() === marca.trim().toLowerCase());
    if (alvo) el('f-marca').value = alvo.value;
    definirModo('manual');
  }

  async function aoMudarMarca() {
    const marca = el('c-marca').value;
    const minha = ++seq.modelos; seq.versoes++;
    aviso('');
    limpar(el('c-versao'), 'Selecione o modelo');
    el('c-ano').replaceChildren();
    atualizarAno();
    if (!marca) { limpar(el('c-modelo'), 'Selecione a marca'); return; }
    limpar(el('c-modelo'), 'Carregando...');
    const itens = await chamar({ acao: 'modelos', marca });
    if (minha !== seq.modelos) return;
    if (!itens) {
      limpar(el('c-modelo'), 'Indisponível');
      aviso('Não foi possível carregar os modelos agora. Use "Informar manualmente".');
      return;
    }
    preencher(el('c-modelo'), itens, 'Selecione o modelo', i => i.slug, i => i.nome);
  }

  async function aoMudarModelo() {
    const marca = el('c-marca').value, modelo = el('c-modelo').value;
    const minha = ++seq.versoes;
    aviso('');
    el('c-ano').replaceChildren();
    atualizarAno();
    if (!modelo) { limpar(el('c-versao'), 'Selecione o modelo'); return; }
    limpar(el('c-versao'), 'Carregando...');
    const itens = await chamar({ acao: 'versoes', marca, modelo });
    if (minha !== seq.versoes) return;
    if (!itens || !itens.length) {
      // Sem versões (ou fora do ar): marca e modelo continuam valendo, e o ano é digitado.
      limpar(el('c-versao'), 'Sem versões');
      aviso(itens ? 'A Carpedia não tem versões com ano-modelo para este modelo. Digite o ano.'
                  : 'Não foi possível carregar as versões agora. Digite o ano.');
      return;
    }
    anosDaVersao = new Map(itens.map(i => [String(i.id), i.anos]));
    preencher(el('c-versao'), itens, 'Não sei / não listada', i => String(i.id), i => i.nome);
  }

  function aoMudarVersao() {
    const anos = anosDaVersao.get(el('c-versao').value) || [];
    el('c-ano').replaceChildren(new Option('Selecione o ano', ''));
    anos.forEach(a => el('c-ano').appendChild(new Option(a, a)));
    if (anos.length === 1) el('c-ano').value = anos[0];
    atualizarAno();
  }

  async function carregarMarcas() {
    if (marcas === undefined) marcas = await chamar({ acao: 'marcas' });
    return marcas;
  }

  // Chamado pelo openModal da página. `edicao` = veículo em edição (ou nada, ao cadastrar).
  async function abrir(edicao) {
    definirModo('manual');
    el('c-bar').hidden = true;
    zerar();
    const lista = await carregarMarcas();
    if (!lista || !lista.length) return;        // Carpedia fora do ar: segue no modo manual
    preencher(el('c-marca'), lista, 'Selecione a marca', i => i.slug, i => i.nome);
    limpar(el('c-modelo'), 'Selecione a marca');
    limpar(el('c-versao'), 'Selecione o modelo');
    el('c-bar').hidden = false;
    if (!edicao) definirModo('carpedia');       // ao editar, só usa o catálogo se o usuário pedir
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (!el('c-bloco')) return;
    el('c-marca').addEventListener('change', aoMudarMarca);
    el('c-modelo').addEventListener('change', aoMudarModelo);
    el('c-versao').addEventListener('change', aoMudarVersao);
    el('c-toggle').addEventListener('click', () => {
      if (el('f-modo').value === 'carpedia') irParaManual();
      else { definirModo('carpedia'); zerar(); }
    });
  });

  return { abrir };
})();
