// Gráficos do painel. Este módulo é carregado sob demanda (import dinâmico) apenas quando a página tem gráfico.
// Cores: paleta categórica validada (slots 1 a 3) e tokens de texto/grade neutros.
import * as echarts from 'echarts/core';
import { BarChart, LineChart, MapChart, ScatterChart } from 'echarts/charts';
import { AriaComponent, DataZoomComponent, GeoComponent, GridComponent, LegendComponent, MarkAreaComponent, MarkLineComponent, MarkPointComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { opcoesDeComparacao, opcoesDeMapa, opcoesDeMatriz } from './graficos-analise.js';

echarts.use([BarChart, LineChart, MapChart, ScatterChart, AriaComponent, DataZoomComponent, GeoComponent, GridComponent, LegendComponent, MarkAreaComponent, MarkLineComponent, MarkPointComponent, TooltipComponent, CanvasRenderer]);

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

/** A partir de quantos pontos a evolução ganha a barra de zoom. */
const PONTOS_PARA_ZOOM = 12;

const escapar = (texto) => String(texto).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

function formatador(dados) {
    const numero = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: dados.casas, maximumFractionDigits: dados.casas });

    return (valor) => (valor === null || valor === undefined ? '—' : `${dados.prefixo}${numero.format(valor)}${dados.sufixo}`);
}

/**
 * Variação entre dois valores, no mesmo padrão do servidor: pontos percentuais em indicadores de %, e variação relativa nos demais.
 */
function textoDaVariacao(dados, atual, anterior) {
    if (atual === null || atual === undefined || anterior === null || anterior === undefined) {
        return null;
    }

    const diferenca = atual - anterior;
    const sinal = diferenca > 0 ? '+' : diferenca < 0 ? '−' : '';
    const numero = (valor, casas) => new Intl.NumberFormat('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas }).format(valor);

    if (Math.abs(diferenca) < 0.5 * 10 ** -Math.max(1, dados.casas)) {
        return 'sem variação';
    }

    if (dados.sufixo.trim() === '%') {
        return `${sinal}${numero(Math.abs(diferenca), Math.max(1, dados.casas))} p.p.`;
    }

    if (anterior === 0) {
        return `${sinal}${numero(Math.abs(diferenca), dados.casas)}`;
    }

    return `${sinal}${numero(Math.abs((diferenca / anterior) * 100), 1)}%`;
}

function eixoDeValores(dados, formatar, ajustarAosDados = false, maximo = undefined, minimo = undefined) {
    const compacto = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: dados.casas > 0 ? 1 : 0 });

    return {
        type: 'value',
        scale: ajustarAosDados,
        max: maximo,
        min: minimo,
        axisLabel: { color: COR.textoSecundario, fontFamily: FONTE, formatter: (v) => `${dados.prefixo}${compacto.format(v)}${dados.sufixo.trim() === '%' ? '%' : ''}` },
        splitLine: { lineStyle: { color: COR.grade, width: 1, type: 'solid' } },
        axisLine: { show: false },
        axisTick: { show: false },
    };
}

function tooltipPorX(dados, formatar, cores) {
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

            // Quanto o município variou em relação ao ponto anterior da linha.
            const indice = itens[0].dataIndex;
            const variacao = indice > 0 ? textoDaVariacao(dados, dados.series[0].dados[indice], dados.series[0].dados[indice - 1]) : null;
            const rodape = variacao === null
                ? ''
                : `<div style="margin-top:6px;padding-top:6px;border-top:1px solid ${COR.grade};color:${COR.textoSecundario}">${escapar(dados.series[0].nome)}: <strong style="color:${COR.texto}">${escapar(variacao)}</strong> desde ${escapar(dados.categorias[indice - 1])}</div>`;

            return `<div style="color:${COR.textoSecundario}">${escapar(itens[0].axisValueLabel)}</div>${linhas}${rodape}`;
        },
    };
}

export function opcoesDeEvolucao(dados) {
    const formatar = formatador(dados);
    const cores = dados.series.map((serie) => COR[serie.papel] ?? COR.municipio);
    const ultimo = dados.categorias.length - 1;
    const valoresDoMunicipio = dados.series[0].dados.filter((v) => v !== null && v !== undefined);
    const maximo = Math.max(...valoresDoMunicipio);
    const minimo = Math.min(...valoresDoMunicipio);
    const comZoom = dados.categorias.length > PONTOS_PARA_ZOOM;
    const comExtremos = dados.categorias.length >= 4 && maximo !== minimo;
    // O patamar pleno só entra no gráfico quando está perto dos valores (senão esmagaria a linha).
    const comTeto = dados.teto !== null && dados.teto !== undefined && dados.teto <= maximo * 1.3 && dados.teto >= minimo * 0.7;

    return {
        animation: false,
        aria: { enabled: true },
        color: cores,
        grid: { left: 8, right: 56, top: 36, bottom: comZoom ? 40 : 8, containLabel: true },
        legend: {
            top: 0,
            left: 0,
            icon: 'roundRect',
            itemWidth: 14,
            itemHeight: 3,
            itemGap: 16,
            inactiveColor: '#c9c8c0',
            textStyle: { color: COR.textoSecundario, fontFamily: FONTE, fontSize: 12 },
        },
        tooltip: tooltipPorX(dados, formatar, cores),
        dataZoom: comZoom
            ? [
                // Ctrl + roda do mouse (ou pinça no celular) amplia; a roda sozinha continua rolando a página.
                { type: 'inside', filterMode: 'none', zoomOnMouseWheel: 'ctrl', moveOnMouseWheel: false },
                {
                    type: 'slider',
                    filterMode: 'none',
                    height: 18,
                    bottom: 4,
                    showDetail: false,
                    brushSelect: false,
                    borderColor: 'transparent',
                    backgroundColor: '#f3f2ee',
                    fillerColor: 'rgba(42, 120, 214, 0.15)',
                    handleSize: '90%',
                    dataBackground: { lineStyle: { color: '#cfcdc6' }, areaStyle: { color: '#ebe9e3' } },
                },
            ]
            : undefined,
        xAxis: {
            type: 'category',
            data: dados.categorias,
            boundaryGap: false,
            axisLabel: { color: COR.textoSecundario, fontFamily: FONTE, hideOverlap: true },
            axisLine: { lineStyle: { color: COR.grade } },
            axisTick: { show: false },
        },
        yAxis: eixoDeValores(
            dados,
            formatar,
            true,
            comTeto ? Math.max(maximo, dados.teto) * 1.04 : undefined,
            // Com o patamar pleno no gráfico, o eixo desce até ele (com folga) para a linha aparecer.
            comTeto ? (limites) => { const base = Math.min(limites.min, dados.teto); return base - (limites.max - base) * 0.08; } : undefined,
        ),
        series: dados.series.map((serie, indice) => {
            const doMunicipio = serie.papel === 'municipio';

            return {
                name: serie.nome,
                type: 'line',
                data: serie.dados,
                connectNulls: true,
                smooth: false,
                symbol: 'circle',
                symbolSize: 8,
                showSymbol: dados.categorias.length === 1,
                lineStyle: { width: doMunicipio ? 2.5 : 2, type: doMunicipio ? 'solid' : 'dashed', cap: 'round', join: 'round' },
                itemStyle: { color: cores[indice], borderColor: COR.superficie, borderWidth: 2 },
                areaStyle: doMunicipio ? { color: cores[indice], opacity: 0.07 } : undefined,
                emphasis: { focus: 'series', scale: true },
                endLabel: doMunicipio
                    ? { show: true, formatter: () => formatar(serie.dados[ultimo]), color: COR.texto, fontFamily: FONTE, fontWeight: 600, distance: 6 }
                    : undefined,
                markPoint: doMunicipio && comExtremos
                    ? {
                        symbol: 'circle',
                        symbolSize: 9,
                        silent: true,
                        itemStyle: { color: COR.municipio, borderColor: COR.superficie, borderWidth: 2 },
                        label: { show: true, color: COR.texto, fontFamily: FONTE, fontSize: 11, fontWeight: 600, formatter: (ponto) => formatar(ponto.value) },
                        data: [
                            { type: 'max', name: 'Maior', label: { position: 'top', distance: 6 } },
                            { type: 'min', name: 'Menor', label: { position: 'bottom', distance: 6 } },
                        ],
                    }
                    : undefined,
                markLine: doMunicipio && comTeto
                    ? {
                        symbol: 'none',
                        silent: true,
                        lineStyle: { color: COR.textoSecundario, width: 1, type: 'dashed' },
                        label: { formatter: () => `Patamar pleno (${formatar(dados.teto)})`, color: COR.textoSecundario, fontFamily: FONTE, fontSize: 11, position: 'insideStartTop' },
                        data: [{ yAxis: dados.teto }],
                    }
                    : undefined,
            };
        }),
    };
}

export function opcoesDeRanking(dados) {
    const formatar = formatador(dados);
    const total = dados.total ?? dados.categorias.length;
    const posicoes = dados.posicoes ?? dados.categorias.map((_, indice) => indice + 1);
    const rotuloDaMediana = dados.rotulo_da_mediana ?? 'Mediana da região';

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
            formatter: (item) => `<strong>${escapar(formatar(item.value))}</strong><div style="color:${COR.textoSecundario}">${escapar(item.name)} · ${posicoes[item.dataIndex]}ª de ${total}</div><div style="margin-top:4px;color:${COR.textoSecundario}">${item.dataIndex === dados.destaque ? 'Município em foco' : 'Clique para colocar em foco'}</div>`,
        },
        xAxis: eixoDeValores(dados, formatar),
        yAxis: {
            type: 'category',
            inverse: true,
            data: dados.categorias,
            axisLabel: { color: COR.textoSecundario, fontFamily: FONTE, width: 150, overflow: 'truncate', formatter: (nome, indice) => `${posicoes[indice]}º  ${nome}` },
            axisLine: { lineStyle: { color: COR.grade } },
            axisTick: { show: false },
        },
        series: [
            {
                type: 'bar',
                barMaxWidth: 24,
                cursor: 'pointer',
                data: dados.valores.map((valor, indice) => ({
                    value: valor,
                    id: dados.ids ? dados.ids[indice] : undefined,
                    itemStyle: {
                        color: indice === dados.destaque ? COR.municipio : COR.barraNeutra,
                        borderRadius: [0, 4, 4, 0],
                    },
                })),
                label: { show: true, position: 'right', formatter: (item) => formatar(item.value), color: COR.texto, fontFamily: FONTE, fontSize: 12 },
                emphasis: { itemStyle: { shadowBlur: 6, shadowColor: 'rgba(0, 0, 0, 0.25)' } },
                markLine: dados.mediana === null ? undefined : {
                    symbol: 'none',
                    silent: true,
                    lineStyle: { color: COR.textoSecundario, width: 1, type: 'dashed' },
                    label: { formatter: () => `${rotuloDaMediana}: ${formatar(dados.mediana)}`, color: COR.textoSecundario, fontFamily: FONTE, position: 'end', rotate: 0, distance: 6 },
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

/**
 * Salva o gráfico como imagem PNG (fundo branco, em dobro da resolução da tela).
 */
export function baixarImagem(elemento, nome) {
    const grafico = echarts.getInstanceByDom(elemento);

    if (!grafico) {
        return;
    }

    const ligacao = document.createElement('a');

    ligacao.href = grafico.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: '#ffffff' });
    ligacao.download = `${nome}.png`;
    ligacao.click();
}
