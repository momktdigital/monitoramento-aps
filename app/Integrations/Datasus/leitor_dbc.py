#!/usr/bin/env python3
"""Leitor de arquivos .dbc do DATASUS (DBF comprimido com o algoritmo PKWare DCL "implode").

Usa apenas a biblioteca padrão do Python (3.8+): não há nada para instalar nem compilar.

Uso:
    python leitor_dbc.py ARQUIVO.dbc CAMPO1,CAMPO2,...     # um registro por linha, valores separados por TAB
    python leitor_dbc.py ARQUIVO.dbc --info                # só o cabeçalho (campos e quantidade de registros)

Saída do modo de registros: a 1ª linha traz os nomes dos campos pedidos; as seguintes, os valores
(já sem espaços nas pontas). Registros marcados como excluídos no DBF são ignorados. Se o arquivo
terminar antes do número de registros declarado no cabeçalho, o programa falha (código 3).

O arquivo .dbc é: [cabeçalho do DBF][CRC32 de 4 bytes][registros comprimidos]. O tamanho do cabeçalho
fica nos bytes 8-9 (little-endian).
"""

import struct
import sys

# Comprimentos de código em forma compacta: cada byte = (repetições - 1) << 4 | comprimento em bits.
_COMPRIMENTO_LITERAIS = [
    11, 124, 8, 7, 28, 7, 188, 13, 76, 4, 10, 8, 12, 10, 12, 10, 8, 23, 8, 9, 7, 6, 7, 8, 7, 6, 55, 8, 23, 24, 12,
    11, 7, 9, 11, 12, 6, 7, 22, 5, 7, 24, 6, 11, 9, 6, 7, 22, 7, 11, 38, 7, 9, 8, 25, 11, 8, 11, 9, 12, 8, 12, 5,
    38, 5, 38, 5, 11, 7, 5, 6, 21, 6, 10, 53, 8, 7, 24, 10, 27, 44, 253, 253, 253, 252, 252, 252, 13, 12, 45, 12,
    45, 12, 61, 12, 45, 44, 173,
]
_COMPRIMENTO_TAMANHOS = [2, 35, 36, 53, 38, 23]
_COMPRIMENTO_DISTANCIAS = [2, 20, 53, 230, 247, 151, 248]
_BASE_DOS_TAMANHOS = [3, 2, 4, 5, 6, 7, 8, 9, 10, 12, 16, 24, 40, 72, 136, 264]
_BITS_EXTRAS_DOS_TAMANHOS = [0, 0, 0, 0, 0, 0, 0, 0, 1, 2, 3, 4, 5, 6, 7, 8]

_BITS_MAXIMOS = 13
_JANELA = 4096
_DESCARGA = 1 << 22


def _tabela_de_huffman(compacto, simbolos_esperados):
    """Monta uma tabela de consulta indexada pelos próximos 13 bits do fluxo: valor = (símbolo << 4) | comprimento.

    Os códigos são canônicos e o fluxo os traz invertidos e com o 1º bit como o mais significativo,
    como no `blast.c` de Mark Adler (zlib/contrib).
    """
    comprimentos = []
    for byte in compacto:
        comprimentos.extend([byte & 15] * ((byte >> 4) + 1))

    if len(comprimentos) != simbolos_esperados:
        raise ValueError('tabela de Huffman inconsistente')

    contagem = [0] * (_BITS_MAXIMOS + 2)
    for comprimento in comprimentos:
        contagem[comprimento] += 1
    contagem[0] = 0

    simbolos_por_comprimento = {}
    for simbolo, comprimento in enumerate(comprimentos):
        if comprimento:
            simbolos_por_comprimento.setdefault(comprimento, []).append(simbolo)

    tabela = [0] * (1 << _BITS_MAXIMOS)
    primeiro = 0

    for comprimento in range(1, _BITS_MAXIMOS + 1):
        for posicao, simbolo in enumerate(simbolos_por_comprimento.get(comprimento, [])):
            codigo = primeiro + posicao
            fluxo = 0
            for i in range(comprimento):
                fluxo |= (((codigo >> (comprimento - 1 - i)) & 1) ^ 1) << i

            for indice in range(fluxo, 1 << _BITS_MAXIMOS, 1 << comprimento):
                tabela[indice] = (simbolo << 4) | comprimento

        primeiro = (primeiro + contagem[comprimento]) << 1

    return tabela


_TABELA_LITERAIS = _tabela_de_huffman(_COMPRIMENTO_LITERAIS, 256)
_TABELA_TAMANHOS = _tabela_de_huffman(_COMPRIMENTO_TAMANHOS, 16)
_TABELA_DISTANCIAS = _tabela_de_huffman(_COMPRIMENTO_DISTANCIAS, 64)


def descomprimir(dados):
    """Gera pedaços (bytes) do conteúdo descomprimido, mantendo na memória só a janela de 4 KB e o pedaço atual."""
    entrada = dados + b'\x00' * 16
    total = len(dados)
    posicao = 2
    bits = 0
    quantidade = 0

    literais_codificados = entrada[0]
    bits_da_distancia = entrada[1]

    if literais_codificados > 1:
        raise ValueError('cabeçalho de compressão inválido (literais)')
    if bits_da_distancia < 4 or bits_da_distancia > 6:
        raise ValueError('cabeçalho de compressão inválido (dicionário)')

    saida = bytearray()
    tabela_literais = _TABELA_LITERAIS
    tabela_tamanhos = _TABELA_TAMANHOS
    tabela_distancias = _TABELA_DISTANCIAS
    base = _BASE_DOS_TAMANHOS
    extras = _BITS_EXTRAS_DOS_TAMANHOS
    mascara_max = (1 << _BITS_MAXIMOS) - 1

    while True:
        while quantidade < 32:
            bits |= entrada[posicao] << quantidade
            posicao += 1
            quantidade += 8

        if posicao > total + 8:
            raise ValueError('arquivo comprimido truncado')

        if bits & 1:
            bits >>= 1
            quantidade -= 1

            entrada_tabela = tabela_tamanhos[bits & mascara_max]
            comprimento_do_codigo = entrada_tabela & 15
            bits >>= comprimento_do_codigo
            quantidade -= comprimento_do_codigo
            simbolo = entrada_tabela >> 4

            bits_extras = extras[simbolo]
            tamanho = base[simbolo] + (bits & ((1 << bits_extras) - 1))
            bits >>= bits_extras
            quantidade -= bits_extras

            if tamanho == 519:
                break

            baixos = 2 if tamanho == 2 else bits_da_distancia
            entrada_tabela = tabela_distancias[bits & mascara_max]
            comprimento_do_codigo = entrada_tabela & 15
            bits >>= comprimento_do_codigo
            quantidade -= comprimento_do_codigo
            distancia = ((entrada_tabela >> 4) << baixos) + (bits & ((1 << baixos) - 1)) + 1
            bits >>= baixos
            quantidade -= baixos

            if distancia > len(saida):
                raise ValueError('referência anterior ao início dos dados')

            inicio = len(saida) - distancia

            if distancia >= tamanho:
                saida += saida[inicio:inicio + tamanho]
            else:
                trecho = bytes(saida[inicio:])
                saida += (trecho * (tamanho // distancia + 1))[:tamanho]
        else:
            bits >>= 1
            quantidade -= 1

            if literais_codificados:
                entrada_tabela = tabela_literais[bits & mascara_max]
                comprimento_do_codigo = entrada_tabela & 15
                bits >>= comprimento_do_codigo
                quantidade -= comprimento_do_codigo
                saida.append(entrada_tabela >> 4)
            else:
                saida.append(bits & 255)
                bits >>= 8
                quantidade -= 8

        if len(saida) > _DESCARGA:
            yield bytes(saida[:-_JANELA])
            del saida[:-_JANELA]

    yield bytes(saida)


def _ler_cabecalho_dbf(cabecalho):
    quantidade_de_registros = struct.unpack('<I', cabecalho[4:8])[0]
    tamanho_do_cabecalho, tamanho_do_registro = struct.unpack('<HH', cabecalho[8:12])
    campos = []
    deslocamento = 1

    for inicio in range(32, tamanho_do_cabecalho - 1, 32):
        descritor = cabecalho[inicio:inicio + 32]

        if descritor[0] == 0x0D:
            break

        nome = descritor[:11].split(b'\x00')[0].decode('ascii', 'replace')
        comprimento = descritor[16]
        campos.append((nome, deslocamento, comprimento))
        deslocamento += comprimento

    return quantidade_de_registros, tamanho_do_registro, campos


def _blocos_do_dbc(caminho):
    with open(caminho, 'rb') as arquivo:
        conteudo = arquivo.read()

    tamanho_do_cabecalho = struct.unpack('<H', conteudo[8:10])[0]

    return conteudo[:tamanho_do_cabecalho], descomprimir(conteudo[tamanho_do_cabecalho + 4:])


def main(argumentos):
    if len(argumentos) != 3:
        sys.stderr.write(__doc__)
        return 2

    caminho, pedido = argumentos[1], argumentos[2]

    try:
        cabecalho, blocos = _blocos_do_dbc(caminho)
        quantidade, tamanho_do_registro, campos = _ler_cabecalho_dbf(cabecalho)
    except (OSError, ValueError, struct.error) as erro:
        sys.stderr.write('Falha ao abrir o arquivo .dbc: %s\n' % erro)
        return 1

    if pedido == '--info':
        sys.stdout.write('registros\t%d\ntamanho_do_registro\t%d\n' % (quantidade, tamanho_do_registro))
        sys.stdout.write('campos\t%s\n' % ','.join('%s:%d' % (nome, comprimento) for nome, _, comprimento in campos))
        return 0

    por_nome = {nome: (deslocamento, comprimento) for nome, deslocamento, comprimento in campos}
    desejados = pedido.split(',')
    ausentes = [nome for nome in desejados if nome not in por_nome]

    if ausentes:
        sys.stderr.write('Campos inexistentes no arquivo: %s\n' % ', '.join(ausentes))
        return 4

    posicoes = [por_nome[nome] for nome in desejados]
    escrever = sys.stdout.write
    escrever('\t'.join(desejados) + '\n')

    pendente = b''
    lidos = 0

    try:
        for bloco in blocos:
            dados = pendente + bloco
            completos = len(dados) // tamanho_do_registro

            for indice in range(completos):
                inicio = indice * tamanho_do_registro
                registro = dados[inicio:inicio + tamanho_do_registro]
                lidos += 1

                if registro[:1] == b'*':
                    continue

                escrever('\t'.join(registro[o:o + c].decode('latin-1').strip().replace('\t', ' ') for o, c in posicoes) + '\n')

            pendente = dados[completos * tamanho_do_registro:]

            if lidos >= quantidade:
                break
    except ValueError as erro:
        sys.stderr.write('Arquivo .dbc corrompido: %s\n' % erro)
        return 3

    if lidos < quantidade:
        sys.stderr.write('Arquivo incompleto: %d de %d registros\n' % (lidos, quantidade))
        return 3

    return 0


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8', newline='\n')

    sys.exit(main(sys.argv))
