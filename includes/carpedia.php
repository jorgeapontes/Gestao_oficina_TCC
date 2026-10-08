<?php
// ─── CATÁLOGO DA CARPEDIA (https://www.carpedia.com.br/desenvolvedores/docs) ─────
// Marcas, modelos e versões para o cadastro de veículos. A versão só ajuda a escolher o
// ano-modelo (a lista de anos vem dela); o que se grava no veículo é marca, modelo e ano.
//
//  • A chave (CARPEDIA_KEY) só é usada no cabeçalho da requisição. Nunca é gravada em
//    log, em mensagem de erro, nem enviada ao navegador: o navegador só chama o nosso PHP.
//  • Tudo que vira caminho de URL é validado antes (slug: /^[a-z0-9-]+$/).
//  • A cota da API é pequena: consulta sob demanda + cache em `carpedia_cache`
//    (inclusive o "não encontrado"). Nunca se varre o catálogo.
//  • Sem chave, sem tabela de cache, 404, 402/429 ou timeout: o sistema segue normal e o
//    formulário de veículo volta ao modo manual (marca escolhida e modelo digitado).
//  • Onde houver dado da Carpedia, exibe-se "Dados: Carpedia" (termos de uso).

const CARPEDIA_BASE = 'https://www.carpedia.com.br/api/v1';
// Validade do cache, em dias.
const CARPEDIA_TTL_DIAS = ['marcas' => 30, 'modelos' => 30, 'versoes' => 90];
const CARPEDIA_PAUSA_SEG = 900;    // depois de uma falha, não tenta de novo por este tempo

function carpedia_ativa(): bool { return CARPEDIA_KEY !== ''; }

function carpedia_slug_ok($s): bool {
    return is_string($s) && strlen($s) <= 80 && preg_match('/^[a-z0-9-]+$/', $s) === 1;
}
// Id de versão (aceita negativo: lançamentos "manuais" da Carpedia) que cabe num INT.
function carpedia_versao_id_ok($id): bool {
    if (is_int($id)) return $id >= -2147483648 && $id <= 2147483647;
    return is_string($id) && preg_match('/^-?\d{1,10}$/', $id) === 1 && carpedia_versao_id_ok((int)$id);
}
function carpedia_ano_ok($ano): bool {
    return (is_int($ano) || is_string($ano)) && preg_match('/^\d{4}$/', (string)$ano) === 1;
}

// Caminho do recurso (lista branca de ações); null se algum parâmetro for inválido.
function carpedia_caminho(string $recurso, array $a = []): ?string {
    return match ($recurso) {
        'marcas'  => '/marcas',
        'modelos' => carpedia_slug_ok($a[0] ?? null) ? '/marcas/' . $a[0] . '/modelos' : null,
        'versoes' => carpedia_slug_ok($a[0] ?? null) && carpedia_slug_ok($a[1] ?? null)
                         ? '/modelos/' . $a[0] . '/' . $a[1] . '/versoes' : null,
        default   => null,
    };
}

// ─── REQUISIÇÃO + CACHE ──────────────────────────────────────────────────────
// Faz a requisição. Retorna [status HTTP, corpo decodificado]; status 0 = falha de rede/timeout.
function carpedia_http(string $caminho, string $query = ''): array {
    if (!function_exists('curl_init')) return [0, null];
    $ch = curl_init(CARPEDIA_BASE . $caminho . $query);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . CARPEDIA_KEY, 'Accept: application/json'],
    ]);
    $corpo  = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($corpo === false) {
        // Só o código do erro: a mensagem do curl pode citar a URL/cabeçalhos.
        error_log('[IMM] Carpedia: falha de rede (curl ' . curl_errno($ch) . ') em ' . $caminho);
        return [0, null];
    }
    $dados = json_decode($corpo, true);
    return [$status, is_array($dados) ? $dados : null];
}

function carpedia_pausada(): bool {
    return (bool)db_val('SELECT 1 FROM carpedia_cache WHERE caminho = ? AND buscadoEm > NOW() - INTERVAL ? SECOND',
                        ['_pausa', CARPEDIA_PAUSA_SEG]);
}
function carpedia_pausar(string $motivo): void {
    error_log('[IMM] Carpedia indisponível (' . $motivo . '); nova tentativa em ' . (CARPEDIA_PAUSA_SEG / 60) . ' min.');
    db_exec('REPLACE INTO carpedia_cache (caminho, resposta, buscadoEm) VALUES (?, ?, NOW())', ['_pausa', $motivo]);
}

// Consulta um recurso (cache primeiro). Retorna os dados (200) ou null em qualquer outro caso.
// O 404 também fica em cache, para não gastar cota repetindo uma consulta que não tem resposta.
function carpedia_get(string $recurso, array $args = []): ?array {
    $caminho = carpedia_caminho($recurso, $args);
    if ($caminho === null || !carpedia_ativa()) return null;
    try {
        $row = db_one('SELECT resposta FROM carpedia_cache WHERE caminho = ? AND buscadoEm > NOW() - INTERVAL ? DAY',
                      [$caminho, CARPEDIA_TTL_DIAS[$recurso]]);
        if ($row) {
            $c = json_decode($row['resposta'], true);
            if (is_array($c) && in_array($c['s'] ?? null, [200, 404], true)) {
                return $c['s'] === 200 && is_array($c['d'] ?? null) ? $c['d'] : null;
            }
        }
        if (carpedia_pausada()) return null;

        // Listas longas vêm paginadas; uma página de 200 cobre marcas e a maioria dos modelos.
        [$status, $dados] = carpedia_http($caminho, $recurso === 'versoes' ? '' : '?porPagina=200');
        if (($status === 200 && $dados !== null) || $status === 404) {
            $json = json_encode(['s' => $status, 'd' => $dados], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            db_exec('REPLACE INTO carpedia_cache (caminho, resposta, buscadoEm) VALUES (?, ?, NOW())', [$caminho, $json]);
            return $status === 200 ? $dados : null;
        }
        if ($status === 400) {
            error_log('[IMM] Carpedia: parâmetro recusado (400) em ' . $caminho);   // bug nosso: não pausa
        } else {
            carpedia_pausar($status === 0 ? 'sem resposta' : 'HTTP ' . $status);
        }
    } catch (mysqli_sql_exception $ex) {
        // Sem a tabela (migração v4 pendente) não se chama a API: sem cache, cada consulta gastaria cota.
        error_log('[IMM] Carpedia: cache indisponível: ' . $ex->getMessage());
    }
    return null;
}

// ─── CATÁLOGO (MARCA → MODELO → VERSÃO) ──────────────────────────────────────
// Cada nível só é consultado se o nível acima (já em cache) o contém: um slug
// inventado nunca gasta requisição.
function carpedia_lista(?array $dados, string $chave): ?array {
    $lista = [];
    foreach (is_array($dados[$chave] ?? null) ? $dados[$chave] : [] as $i) {
        if (is_array($i) && carpedia_slug_ok($i['slug'] ?? null) && is_string($i['nome'] ?? null)) {
            $lista[] = ['slug' => $i['slug'], 'nome' => $i['nome']];
        }
    }
    return $lista ?: null;
}
function carpedia_marcas(): ?array {
    return carpedia_lista(carpedia_get('marcas'), 'marcas');
}
function carpedia_modelos(string $marca): ?array {
    if (!in_array($marca, array_column(carpedia_marcas() ?? [], 'slug'), true)) return null;
    return carpedia_lista(carpedia_get('modelos', [$marca]), 'modelos');
}
// Versões do modelo: [['id', 'nome', 'anos' => ['2026', ...]]]. Só entram anos-modelo (AAAA):
// "0 Km" não é um ano. null = indisponível; [] = o modelo não tem versão com ano-modelo.
function carpedia_versoes(string $marca, string $modelo): ?array {
    if (!in_array($modelo, array_column(carpedia_modelos($marca) ?? [], 'slug'), true)) return null;
    $d = carpedia_get('versoes', [$marca, $modelo]);
    if (!is_array($d['versoes'] ?? null)) return null;
    $lista = [];
    foreach ($d['versoes'] as $v) {
        if (!is_array($v) || !carpedia_versao_id_ok($v['id'] ?? null)) continue;
        $anos = array_values(array_filter(is_array($v['anos'] ?? null) ? $v['anos'] : [], 'carpedia_ano_ok'));
        if (!$anos) continue;
        $nome = is_string($v['versao'] ?? null) && $v['versao'] !== '' ? $v['versao'] : (string)($v['nome'] ?? '');
        $lista[] = ['id' => (int)$v['id'], 'nome' => mb_substr($nome, 0, 120), 'anos' => array_map('strval', $anos)];
    }
    return $lista;
}

// Confere marca e modelo do formulário contra o catálogo e devolve os nomes oficiais (o
// navegador nunca é fonte de nome de marca/modelo). Retorna ['marca', 'modelo'] ou null.
function carpedia_validar_selecao(string $marcaSlug, string $modeloSlug): ?array {
    $marca = $modelo = null;
    foreach (carpedia_marcas() ?? [] as $m) if ($m['slug'] === $marcaSlug) $marca = $m;
    if (!$marca) return null;
    foreach (carpedia_modelos($marcaSlug) ?? [] as $m) if ($m['slug'] === $modeloSlug) $modelo = $m;
    return $modelo ? ['marca' => $marca['nome'], 'modelo' => $modelo['nome']] : null;
}

// ─── FORMULÁRIOS DE VEÍCULO ──────────────────────────────────────────────────
// Interpreta marca/modelo/ano do formulário. No modo "carpedia" marca e modelo vêm do
// catálogo, e o ano é o ano-modelo da versão escolhida (ou digitado, se não houver versão);
// no modo "manual" (padrão) são a marca escolhida e o modelo digitado.
// Retorna ['idModelo', 'ano'] ou ['erro' => mensagem].
function dados_veiculo_do_formulario(): array {
    $anoMax = (int)date('Y') + 1;
    if (post('modo') === 'carpedia') {
        $sel = carpedia_validar_selecao(post('marcaSlug'), post('modeloSlug'));
        if (!$sel) return ['erro' => 'Não foi possível confirmar a marca e o modelo na Carpedia. Tente de novo ou use "Informar manualmente".'];
        $versaoId = post('versaoId');
        if ($versaoId !== '') {
            // O ano tem que ser um dos anos-modelo da versão (confere no catálogo, não no navegador).
            $ano = post('anoVersao');
            $ok = false;
            foreach (carpedia_versoes(post('marcaSlug'), post('modeloSlug')) ?? [] as $v) {
                if (carpedia_versao_id_ok($versaoId) && $v['id'] === (int)$versaoId && in_array($ano, $v['anos'], true)) $ok = true;
            }
            if (!$ok) return ['erro' => 'Esse ano-modelo não existe para a versão escolhida.'];
            $ano = (int)$ano;
        } else {
            $ano = (int)post('ano');
        }
        if (!$ano) return ['erro' => 'Preencha todos os campos.'];
        if ($ano < 1900 || $ano > $anoMax) return ['erro' => 'Ano inválido.'];
        return ['idModelo' => obter_modelo(obter_marca($sel['marca']), $sel['modelo']), 'ano' => $ano];
    }
    $idMarca = post('idMarca'); $modelo = post('modelo'); $ano = (int)post('ano');
    if (!$idMarca || !$modelo || !$ano) return ['erro' => 'Preencha todos os campos.'];
    if ($ano < 1900 || $ano > $anoMax) return ['erro' => 'Ano inválido.'];
    return ['idModelo' => obter_modelo($idMarca, $modelo), 'ano' => $ano];
}

function carpedia_atribuicao(): string {
    return '<a href="https://www.carpedia.com.br" target="_blank" rel="noopener" class="carpedia-credito">Dados: Carpedia</a>';
}

// Campos da seleção em cascata (usados com assets/js/carpedia.js). O bloco "manual" (marca
// e modelo digitado) e o campo "Ano" digitado ficam na própria página; este bloco só
// aparece se a Carpedia responder.
function campos_carpedia_veiculo(): void {
    ?>
      <input type="hidden" name="modo" id="f-modo" value="manual"/>
      <div class="carpedia-bar" id="c-bar" hidden>
        <span class="field-hint"><?= carpedia_atribuicao() ?></span>
        <button type="button" class="act-btn" id="c-toggle"></button>
      </div>
      <fieldset class="blk" id="c-bloco" hidden disabled>
        <div class="field-row">
          <div class="field">
            <label for="c-marca">Marca</label>
            <select name="marcaSlug" id="c-marca" required></select>
          </div>
          <div class="field">
            <label for="c-modelo">Modelo</label>
            <select name="modeloSlug" id="c-modelo" required></select>
          </div>
        </div>
        <div class="field">
          <label for="c-versao">Versão (opcional)</label>
          <select name="versaoId" id="c-versao"></select>
          <span class="field-hint">Escolher a versão lista os anos-modelo dela.</span>
        </div>
        <div class="field" id="c-ano-wrap" hidden>
          <label for="c-ano">Ano-modelo</label>
          <select name="anoVersao" id="c-ano" required></select>
        </div>
        <div class="field-hint" id="c-aviso" hidden></div>
      </fieldset>
    <?php
}
