// Gráficos do painel. Este módulo é carregado sob demanda (import dinâmico) apenas quando a página tem gráfico.
// Cores: paleta categórica validada (slots 1 a 3) e tokens de texto/grade neutros.
import * as echarts from 'echarts/core';
import { BarChart, LineChart, MapChart, ScatterChart } from 'echarts/charts';
import { AriaComponent, GeoComponent, GridComponent, LegendComponent, MarkAreaComponent, MarkLineComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { opcoesDeComparacao, opcoesDeMapa, opcoesDeMatriz } from './graficos-analise.js';

echarts.use([BarChart, LineChart, MapChart, ScatterChart, AriaComponent, GeoComponent, GridComponent, LegendComponent, MarkAreaComponent, MarkLineComponent, TooltipComponent, CanvasRenderer]);

const COR = {
    municipio: '#2a78d6',
    regiao: '#eb6834',
    estado: '#1baf7a',
    barraNeutra: '#b9b8b0',
    superficie: '#ffffff',
    texto: '#0b0b0b',
    textoSecundario: '#52514e',
    grade: '#e6e5e0',
};

const FONTE = 'Instrument Sans, ui-sans-serif, system-ui, sans-serif';

const escapar =(texto) => String(texto).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

function formatador(dados) {
    const numero = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: dados.casas, maximumFractionDigits: dados.casas });

    return (valor) => (valor === null || valor === undefined ? '—' : `${dados.prefixo}${numero.format(valor)}${dados.sufixo}`);
}

function eixoDeValores(dados, formatar, ajustarAosDados = false) {
    const compacto = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: dados.casas > 0 ? 1 : 0 });

    return {
        type: 'value',
        scale: ajustarAosDados,
        axisLabel: { color: COR.textoSecundario, fontFamily: FONTE, formatter: (v) => `${dados.prefixo}${compacto.format(v)}${dados.sufixo.trim() === '%' ? '%' : ''}` },
        splitLine: { lineStyle: { color: COR.grade, width: 1, type: 'solid' } },
        axisLine: { show: false },
        axisTick: { show: false },
    };
}

function tooltipPorX(formatar, cores) {
    return {
        trigger: 'axis',
        axisPointer: { type: 'line', lineStyle: { color: COR.textoSecundario, width: 1 } },
        backgroundColor: COR.superficie,
        borderColor: COR.grade,
        borderWidth: 1,
        padding: [8, 10],
        textStyle: { color: COR.texto, fontFamily: FONTE, fontSize: 12 },
        extraCssText: 'box-shadow: 0 4px 16px rgba(0,0,0,.12); border-radius: 8px;',
        formatter: (itens) => {
            const linhas = itens
                .filter((item) => item.value !== null && item.value !== undefined)
                .map((item) => `<div style="display:flex;align-items:center;gap:8px;margin-top:4px"><span style="display:inline-block;width:14px;height:0;border-top:2px solid ${cores[item.seriesIndex]}"></span><strong>${escapar(formatar(item.value))}</strong><span style="color:${COR.textoSecundario}">${escapar(item.seriesName)}</span></div>`)
                .join('');

            return `<div style="color:${COR.textoSecundario}">${escapar(itens[0].axisValueLabel)}</div>${linhas}`;
        },
    };
}

export function opcoesDeEvolucao(dados) {
    const formatar = formatador(dados);
    const cores = dados.series.map((serie) => COR[serie.papel] ?? COR.municipio);
    const ultimo = dados.categorias.length - 1;

    return {
        animation: false,
        aria: { enabled: true },
        color: cores,
        grid: { left: 8, right: 56, top: 36, bottom: 8, containLabel: true },
        legend: { top: 0, left: 0, icon: 'roundRect', itemWidth: 14, itemHeight: 3, itemGap: 16, textStyle: { color: COR.textoSecundario, fontFamily: FONTE, fontSize: 12 } },
        tooltip: tooltipPorX(formatar, cores),
        xAxis: {
            type: 'category',
            data: dados.categorias,
            boundaryGap: false,
            axisLabel: { color: COR.textoSecundario, fontFamily: FONTE, hideOverlap: true },
            axisLine: { lineStyle: { color: COR.grade } },
            axisTick: { show: false },
        },
        yAxis: eixoDeValores(dados, formatar, true),
        series: dados.series.map((serie, indice) => ({
            name: serie.nome,
            type: 'line',
            data: serie.dados,
            connectNulls: true,
            smooth: false,
            symbol: 'circle',
            symbolSize: 8,
            showSymbol: dados.categorias.length === 1,
            lineStyle: { width: 2, type: serie.papel === 'municipio' ? 'solid' : 'dashed', cap: 'round', join: 'round' },
            itemStyle: { color: cores[indice], borderColor: COR.superficie, borderWidth: 2 },
            emphasis: { focus: 'none', scale: true },
            endLabel: serie.papel === 'municipio'
                ? { show: true, formatter: () => formatar(serie.dados[ultimo]), color: COR.texto, fontFamily: FONTE, fontWeight: 600, distance: 6 }
                : undefined,
        })),
    };
}

export function opcoesDeRanking(dados) {
    const formatar = formatador(dados);
    const quantidade = dados.categorias.length;

    return {
        animation: false,
        aria: { enabled: true },
        grid: { left: 8, right: 72, top: dados.mediana === null ? 8 : 28, bottom: 8, containLabel: true },
        tooltip: {
            trigger: 'item',
            backgroundColor: COR.superficie,
            borderColor: COR.grade,
            borderWidth: 1,
            padding: [8, 10],
            textStyle: { color: COR.texto, fontFamily: FONTE, fontSize: 12 },
            extraCssText: 'box-shadow: 0 4px 16px rgba(0,0,0,.12); border-radius: 8px;',
            formatter: (item) => `<strong>${escapar(formatar(item.value))}</strong><div style="color:${COR.textoSecundario}">${escapar(item.name)} · ${item.dataIndex + 1}ª de ${quantidade}</div>`,
        },
        xAxis: eixoDeValores(dados, formatar),
        yAxis: {
            type: 'category',
            inverse: true,
            data: dados.categorias,
            axisLabel: { color: COR.textoSecundario, fontFamily: FONTE, width: 120, overflow: 'truncate' },
            axisLine: { lineStyle: { color: COR.grade } },
            axisTick: { show: false },
        },
        series: [
            {
                type: 'bar',
                barMaxWidth: 24,
                data: dados.valores.map((valor, indice) => ({
                    value: valor,
                    itemStyle: {
                        color: indice === dados.destaque ? COR.municipio : COR.barraNeutra,
                        borderRadius: [0, 4, 4, 0],
                    },
                })),
                label: { show: true, position: 'right', formatter: (item) => formatar(item.value), color: COR.texto, fontFamily: FONTE, fontSize: 12 },
                emphasis: { itemStyle: { opacity: 0.85 } },
                markLine: dados.mediana === null ? undefined : {
                    symbol: 'none',
                    silent: true,
                    lineStyle: { color: COR.textoSecundario, width: 1, type: 'dashed' },
                    label: { formatter: () => `Mediana da região: ${formatar(dados.mediana)}`, color: COR.textoSecundario, fontFamily: FONTE, position: 'end', rotate: 0, distance: 6 },
                    data: [{ xAxis: dados.mediana }],
                },
            },
        ],
    };
}

function opcoesPara(dados) {
    switch (dados.modo) {
        case 'ranking':
            return opcoesDeRanking(dados);
        case 'matriz':
            return opcoesDeMatriz(dados);
        case 'mapa':
            return opcoesDeMapa(dados);
        case 'comparacao':
            return opcoesDeComparacao(dados);
        default:
            return opcoesDeEvolucao(dados);
    }
}

// O contorno dos municípios vem do próprio servidor (rota autenticada) e é registrado uma vez por estado.
async function prepararMapa(dados) {
    if (dados.modo !== 'mapa' || echarts.getMap(dados.mapa)) {
        return;
    }

    const resposta = await fetch(dados.malha, { credentials: 'same-origin', headers: { Accept: 'application/geo+json' } });

    if (!resposta.ok) {
        throw new Error('Não foi possível carregar o contorno dos municípios.');
    }

    echarts.registerMap(dados.mapa, await resposta.json());
}

export async function criarGrafico(elemento, dados) {
    await prepararMapa(dados);

    const grafico = echarts.init(elemento, null, { renderer: 'canvas' });

    grafico.setOption(opcoesPara(dados), true);

    return grafico;
}

export async function atualizarGrafico(grafico, dados) {
    await prepararMapa(dados);

    grafico.setOption(opcoesPara(dados), true);
}
