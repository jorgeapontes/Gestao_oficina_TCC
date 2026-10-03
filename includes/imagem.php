<?php
// ─── LIMPEZA DE METADADOS DE IMAGENS ─────────────────────────────────────────
// Fotos de celular carregam dados EXIF/XMP: localização GPS, modelo do aparelho,
// data/hora e às vezes miniaturas. Antes de salvar, esses blocos são removidos
// (sem depender da extensão GD). Da EXIF só a orientação é mantida, para a foto
// não aparecer girada. Dados anexados após o fim da imagem também são descartados.

// Retorna o conteúdo limpo ou null se o arquivo não tiver a estrutura esperada.
function imagem_sem_metadados(string $dados, int $tipo, ?int $orientacao = null): ?string {
    $limpo = match ($tipo) {
        IMAGETYPE_JPEG => jpeg_sem_metadados($dados, $orientacao),
        IMAGETYPE_PNG  => png_sem_metadados($dados),
        IMAGETYPE_WEBP => webp_sem_metadados($dados),
        IMAGETYPE_GIF  => $dados,   // GIF não tem EXIF/GPS
        default        => null,
    };
    // Confere se o resultado continua sendo uma imagem válida do mesmo tipo.
    if ($limpo === null) return null;
    $info = @getimagesizefromstring($limpo);
    return $info && $info[2] === $tipo ? $limpo : null;
}

// Lê a orientação EXIF (1 a 8) de um JPEG, se a extensão exif estiver disponível.
function orientacao_jpeg(string $caminho): ?int {
    if (!function_exists('exif_read_data')) return null;
    $exif = @exif_read_data($caminho);
    $o = (int)($exif['Orientation'] ?? 0);
    return $o >= 2 && $o <= 8 ? $o : null;
}

// Segmento APP1 mínimo contendo apenas a tag Orientation (0x0112).
function app1_orientacao(int $o): string {
    $tiff = "II*\x00" . pack('V', 8)                          // cabeçalho TIFF (little endian), IFD0 no byte 8
          . pack('v', 1)                                      // 1 entrada
          . pack('v', 0x0112) . pack('v', 3) . pack('V', 1)   // Orientation, SHORT, 1 valor
          . pack('v', $o) . "\x00\x00"
          . pack('V', 0);                                     // sem próximo IFD
    $payload = "Exif\x00\x00" . $tiff;
    return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
}

function jpeg_sem_metadados(string $d, ?int $orientacao): ?string {
    if (substr($d, 0, 2) !== "\xFF\xD8") return null;
    $saida = "\xFF\xD8";
    $orient = $orientacao ? app1_orientacao($orientacao) : '';
    $pos = 2;
    $len = strlen($d);
    while ($pos < $len) {
        if ($d[$pos] !== "\xFF") return null;
        while ($pos < $len && $d[$pos] === "\xFF") $pos++;   // bytes de preenchimento
        if ($pos >= $len) return null;
        $marca = ord($d[$pos++]);

        if ($marca === 0xD9) return $saida . $orient . "\xFF\xD9";             // fim sem imagem
        if (($marca >= 0xD0 && $marca <= 0xD7) || $marca === 0x01) { $saida .= "\xFF" . chr($marca); continue; }

        if ($marca === 0xDA) {                               // início dos dados da imagem
            $resto = substr($d, $pos);
            $fim = strrpos($resto, "\xFF\xD9");              // descarta o que vier depois do fim
            if ($fim === false) return null;
            return $saida . $orient . "\xFF\xDA" . substr($resto, 0, $fim + 2);
        }

        if ($pos + 2 > $len) return null;
        $tam = unpack('n', substr($d, $pos, 2))[1];
        if ($tam < 2 || $pos + $tam > $len) return null;
        $segmento = "\xFF" . chr($marca) . substr($d, $pos, $tam);
        $pos += $tam;

        // APP0 (JFIF) fica no início; a orientação entra logo depois dele.
        if ($marca === 0xE0) { $saida .= $segmento; continue; }
        if ($orient !== '') { $saida .= $orient; $orient = ''; }
        // Remove APP1–APP15 (EXIF, XMP, IPTC/Photoshop...) e comentários (COM),
        // mantendo APP2 (perfil de cor ICC) e os segmentos da imagem em si.
        $remover = ($marca >= 0xE1 && $marca <= 0xEF && $marca !== 0xE2) || $marca === 0xFE;
        if (!$remover) $saida .= $segmento;
    }
    return null;
}

function png_sem_metadados(string $d): ?string {
    if (substr($d, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    $saida = substr($d, 0, 8);
    $pos = 8;
    $len = strlen($d);
    while ($pos + 12 <= $len) {
        $n = unpack('N', substr($d, $pos, 4))[1];
        $tipo = substr($d, $pos + 4, 4);
        if ($pos + 12 + $n > $len) return null;
        $chunk = substr($d, $pos, 12 + $n);
        $pos += 12 + $n;
        // eXIf = EXIF; tEXt/zTXt/iTXt = textos livres (inclusive XMP); tIME = data.
        if (!in_array($tipo, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) $saida .= $chunk;
        if ($tipo === 'IEND') return $saida;
    }
    return null;
}

function webp_sem_metadados(string $d): ?string {
    if (substr($d, 0, 4) !== 'RIFF' || substr($d, 8, 4) !== 'WEBP') return null;
    $saida = '';
    $pos = 12;
    $len = strlen($d);
    while ($pos + 8 <= $len) {
        $tipo = substr($d, $pos, 4);
        $n = unpack('V', substr($d, $pos + 4, 4))[1];
        if ($pos + 8 + $n > $len) return null;
        $chunk = substr($d, $pos, 8 + $n + ($n & 1));
        $pos += 8 + $n + ($n & 1);
        if ($tipo === 'EXIF' || $tipo === 'XMP ') continue;
        // No cabeçalho estendido, desliga as flags que anunciam EXIF (0x08) e XMP (0x04).
        if ($tipo === 'VP8X' && $n >= 1) $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
        $saida .= $chunk;
    }
    return $saida === '' ? null : 'RIFF' . pack('V', strlen($saida) + 4) . 'WEBP' . $saida;
}
