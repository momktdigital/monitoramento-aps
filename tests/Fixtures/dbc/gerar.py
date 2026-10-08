#!/usr/bin/env python3
"""Gera os arquivos .dbc minúsculos usados nos testes (não depende de nada além da biblioteca padrão).

Roda assim, a partir da raiz do projeto:  python tests/Fixtures/dbc/gerar.py

Cria:
  mini.dbc            5 registros, 1 marcado como excluído; usa literais e referências a trechos anteriores
  mini_truncado.dbc   o cabeçalho promete 6 registros, mas o fluxo traz só 5 (deve falhar com "incompleto")

O codificador é de propósito simples (busca gulosa) e reaproveita as tabelas do decodificador, de modo que
o teste exercita as mesmas tabelas de Huffman dos arquivos reais do DATASUS.
"""

import importlib.util
import os
import struct
import zlib

AQUI = os.path.dirname(os.path.abspath(__file__))
RAIZ = os.path.abspath(os.path.join(AQUI, '..', '..', '..'))

especificacao = importlib.util.spec_from_file_location('leitor_dbc', os.path.join(RAIZ, 'app', 'Integrations', 'Datasus', 'leitor_dbc.py'))
leitor = importlib.util.module_from_spec(especificacao)
especificacao.loader.exec_module(leitor)

BITS_DA_DISTANCIA = 6


class Bits:
    def __init__(self):
        self.valor = 0
        self.quantidade = 0

    def escrever(self, valor, quantidade):
        self.valor |= (valor & ((1 << quantidade) - 1)) << self.quantidade
        self.quantidade += quantidade

    def bytes(self):
        return self.valor.to_bytes((self.quantidade + 7) // 8, 'little')


def codigo(tabela, simbolo):
    """Procura na tabela de consulta do decodificador o código (bits do fluxo, comprimento) de um símbolo."""
    for indice, entrada in enumerate(tabela):
        if entrada and entrada >> 4 == simbolo:
            comprimento = entrada & 15
            return indice & ((1 << comprimento) - 1), comprimento
    raise ValueError('símbolo sem código: %d' % simbolo)


def escrever_tamanho(bits, tamanho):
    for simbolo, (base, extras) in enumerate(zip(leitor._BASE_DOS_TAMANHOS, leitor._BITS_EXTRAS_DOS_TAMANHOS)):
        if base <= tamanho < base + (1 << extras):
            valor, comprimento = codigo(leitor._TABELA_TAMANHOS, simbolo)
            bits.escrever(valor, comprimento)
            bits.escrever(tamanho - base, extras)
            return
    raise ValueError('tamanho fora do alcance: %d' % tamanho)


def comprimir(dados):
    bits = Bits()
    posicao = 0

    while posicao < len(dados):
        melhor_tamanho, melhor_distancia = 0, 0

        for distancia in range(1, min(posicao, 1 << (BITS_DA_DISTANCIA + 6)) + 1):
            tamanho = 0
            while posicao + tamanho < len(dados) and tamanho < 518 and dados[posicao + tamanho] == dados[posicao + tamanho - distancia]:
                tamanho += 1
            if tamanho > melhor_tamanho:
                melhor_tamanho, melhor_distancia = tamanho, distancia

        if melhor_tamanho >= 3:
            bits.escrever(1, 1)
            escrever_tamanho(bits, melhor_tamanho)
            d = melhor_distancia - 1
            valor, comprimento = codigo(leitor._TABELA_DISTANCIAS, d >> BITS_DA_DISTANCIA)
            bits.escrever(valor, comprimento)
            bits.escrever(d, BITS_DA_DISTANCIA)
            posicao += melhor_tamanho
        else:
            bits.escrever(0, 1)
            valor, comprimento = codigo(leitor._TABELA_LITERAIS, dados[posicao])
            bits.escrever(valor, comprimento)
            posicao += 1

    bits.escrever(1, 1)
    escrever_tamanho(bits, 519)

    return bytes([1, BITS_DA_DISTANCIA]) + bits.bytes()


CAMPOS = [('ANO_CMPT', 4), ('MES_CMPT', 2), ('MUNIC_RES', 6), ('DIAG_PRINC', 4), ('NOME', 20)]

REGISTROS = [
    (' ', ['2026', '07', '330610', 'I64', 'Valença']),
    (' ', ['2026', '07', '330610', 'S628', 'Valença']),
    ('*', ['2026', '07', '330610', 'EXCL', 'registro excluído']),
    (' ', ['2026', '07', '330420', 'J153', 'Resende']),
    (' ', ['2026', '07', '330420', 'E109', '']),
]


def montar(registros, prometidos):
    tamanho_do_registro = 1 + sum(tamanho for _, tamanho in CAMPOS)
    tamanho_do_cabecalho = 32 + 32 * len(CAMPOS) + 1

    cabecalho = bytearray(32)
    cabecalho[0] = 0x03
    struct.pack_into('<I', cabecalho, 4, prometidos)
    struct.pack_into('<HH', cabecalho, 8, tamanho_do_cabecalho, tamanho_do_registro)

    for nome, tamanho in CAMPOS:
        descritor = bytearray(32)
        descritor[:len(nome)] = nome.encode('ascii')
        descritor[11] = ord('C')
        descritor[16] = tamanho
        cabecalho += descritor

    cabecalho += b'\x0d'

    corpo = b''
    for marca, valores in registros:
        corpo += marca.encode('latin-1') + b''.join(valor.encode('latin-1').ljust(tamanho)[:tamanho] for valor, (_, tamanho) in zip(valores, CAMPOS))

    return bytes(cabecalho) + struct.pack('<I', zlib.crc32(corpo)) + comprimir(corpo)


if __name__ == '__main__':
    with open(os.path.join(AQUI, 'mini.dbc'), 'wb') as arquivo:
        arquivo.write(montar(REGISTROS, len(REGISTROS)))

    with open(os.path.join(AQUI, 'mini_truncado.dbc'), 'wb') as arquivo:
        arquivo.write(montar(REGISTROS, len(REGISTROS) + 1))
