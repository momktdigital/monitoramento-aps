// Gráficos das telas de análise: matriz INA × IDAPS, mapa dos municípios e comparação entre municípios.
// Seguem as mesmas cores do painel (paleta validada): cinza para o conjunto, laranja só para "prioridade máxima",
// azul para o município em foco, e uma rampa de um único tom azul para o mapa.

const COR = {
    municipio: '#2a78d6',
    prioridade: '#eb6834',
    neutro: '#8d8c86',
    superficie: '#ffffff',
    texto: '#0b0b0b',
    textoSecundario: '#52514e',
    grade: '#e6e5e0',
    semDado: '#e8e6df',
};

// Cores dos municípios na comparação: slots 1 a 4 da paleta categórica (cada município mantém o mesmo slot).
const SLOTS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100'];

const FONTE = 'Instrument Sans, ui-sans-serif, system-ui, sans-serif';

const SIMBOLO_DO_QUADRANTE = { 1: 'circle', 2: 'diamond', 3: 'triangle', 4: 'rect' };

const escapar = (texto) => String(texto).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

function tooltipDeItem(conteudo) {
    return {
        trigger: 'item',
        backgroundColor: COR.superficie,
        borderColor: COR.grade,
        borderWidth: 1,
        padding: [8, 10],
        textStyle: { color: COR.texto, fontFamily: FONTE, fontSize: 12 },
        extraCssText: 'box-shadow: 0 4px 16px rgba(0,0,0,.12); border-radius: 8px;',
        formatter: conteudo,
    };
}

function eixoDe(nome) {
    return {
        type: 'value',
        min: 0,
        max: 100,
        name: nome,
        nameLocation: 'middle',
        nameGap: 30,
        nameTextStyle: { color: COR.textoSecundario, fontFamily: FONTE, fontSize: 12 },
        axisLabel: { color: COR.textoSecundario, fontFamily: FONTE, hideOverlap: true },
        splitLine: { lineStyle: { color: COR.grade, width: 1, type: 'solid' } },
        axisLine: { show: false },
        axisTick: { show: false },
    };
}

export function opcoesDeMatriz(dados) {
    const numero = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 });
    const { cortes, pontos } = dados;
    const dePonto = (ponto) => ({ value: [ponto.ina, ponto.idaps], id: ponto.id, nome: ponto.nome, regiao: ponto.regiao, quadrante: ponto.quadrante });
    const zona = (nome, de, ate, posicao, destaque) => [
        { name: nome, coord: de, label: { position: posicao }, itemStyle: { color: destaque ? 'rgba(235, 104, 52, 0.08)' : 'rgba(0, 0, 0, 0)' } },
        { coord: ate },
    ];

    const series = [1, 2, 3, 4].map((quadrante) => ({
        name: dados.quadrantes[quadrante],
        type: 'scatter',
        symbol: SIMBOLO_DO_QUADRANTE[quadrante],
        symbolSize: quadrante === 3 ? 12 : 11,
        silent: true,
        z: 2,
        itemStyle: { color: quadrante === 1 ? COR.prioridade : COR.neutro, borderColor: COR.superficie, borderWidth: 1.5 },
        data: pontos.filter((ponto) => ponto.quadrante === quadrante).map(dePonto),
    }));

    // Série sem pontos: só carrega as linhas de corte e os nomes dos quadrantes.
    if (cortes) {
        series.push({
            type: 'scatter',
            silent: true,
            data: [],
            z: 1,
            markLine: {
                symbol: 'none',
                silent: true,
                lineStyle: { color: COR.textoSecundario, width: 1, type: 'dashed' },
                label: { show: false },
                data: [{ xAxis: cortes.ina }, { yAxis: cortes.idaps }],
            },
            markArea: {
                silent: true,
                label: { show: true, color: COR.textoSecundario, fontFamily: FONTE, fontSize: 11, fontWeight: 600, distance: 8 },
                data: [
                    zona('Estrutura consolidada', [0, cortes.idaps], [cortes.ina, 100], 'insideTopLeft', false),
                    zona('Grande potencial', [cortes.ina, cortes.idaps], [100, 100], 'insideTopRight', false),
                    zona('Oportunidade moderada', [0, 0], [cortes.ina, cortes.idaps], 'insideBottomLeft', false),
                    zona('Prioridade máxima', [cortes.ina, 0], [100, cortes.idaps], 'insideBottomRight', true),
                ],
            },
        });
    }

    const selecionado = pontos.find((ponto) => ponto.id === dados.selecionado);

    if (selecionado) {
        series.push({
            type: 'scatter',
            silent: true,
            symbol: 'circle',
            symbolSize: 22,
            z: 5,
            itemStyle: { color: 'rgba(42, 120, 214, 0)', borderColor: COR.municipio, borderWidth: 2.5 },
            label: { show: true, position: 'top', distance: 8, formatter: () => selecionado.nome, color: COR.texto, fontFamily: FONTE, fontWeight: 600, fontSize: 12 },
            data: [dePonto(selecionado)],
        });
    }

    // Área de clique e de dica bem maior que o ponto visível (24 px ou mais); só aparece, como um anel, ao receber o foco.
    series.push({
        type: 'scatter',
        symbol: 'circle',
        symbolSize: 28,
        z: 10,
        itemStyle: { opacity: 0 },
        emphasis: { scale: false, itemStyle: { opacity: 1, color: 'rgba(0, 0, 0, 0)', borderColor: COR.texto, borderWidth: 1.5 } },
        tooltip: tooltipDeItem((item) => `<strong>${escapar(item.data.nome)}</strong><div style="color:${COR.textoSecundario}">${escapar(dados.quadrantes[item.data.quadrante])}</div><div style="margin-top:4px">Necessidade (INA) <strong>${numero.format(item.data.value[0])}</strong> · Desempenho (IDAPS) <strong>${numero.format(item.data.value[1])}</strong></div>`),
        data: pontos.map(dePonto),
    });

    return {
        animation: false,
        aria: { enabled: true },
        // Os nomes dos eixos ficam fora da área de rótulos: as margens abaixo reservam o espaço deles.
        grid: { left: 36, right: 16, top: 12, bottom: 36, containLabel: true },
        tooltip: { trigger: 'item' },
        xAxis: eixoDe('Necessidade da população (INA) →'),
        yAxis: eixoDe('Desempenho da Atenção Primária (IDAPS) →'),
        series,
    };
}

export function opcoesDeMapa(dados) {
    return {
        animation: false,
        aria: { enabled: true },
        tooltip: tooltipDeItem((item) => (item.data ? `<strong>${escapar(item.data.texto)}</strong><div style="color:${COR.textoSecundario}">${escapar(item.data.nome)}</div>` : '')),
        series: [
            {
                type: 'map',
                map: dados.mapa,
                nameProperty: 'codarea',
                roam: false,
                selectedMode: false,
                aspectScale: 0.92,
                label: { show: false },
                emphasis: { label: { show: false } },
                itemStyle: { areaColor: COR.semDado, borderColor: COR.superficie, borderWidth: 0.8 },
                data: dados.regioes.map((regiao) => {
                    const emFoco = regiao.name === dados.selecionado;

                    return {
                        name: regiao.name,
                        id: regiao.id,
                        nome: regiao.nome,
                        texto: regiao.texto,
                        value: regiao.valor,
                        itemStyle: { areaColor: regiao.cor, borderColor: emFoco ? COR.texto : COR.superficie, borderWidth: emFoco ? 2.5 : 0.8 },
                        emphasis: { itemStyle: { areaColor: regiao.cor, borderColor: COR.texto, borderWidth: 1.5 } },
                    };
                }),
            },
        ],
    };
}

export function opcoesDeComparacao(dados) {
    const numero = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 });
    const cores = dados.series.map((serie) => SLOTS[serie.cor] ?? COR.municipio);

    return {
        animation: false,
        aria: { enabled: true },
        color: cores,
        grid: { left: 8, right: 36, top: 8, bottom: 8, containLabel: true },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            backgroundColor: COR.superficie,
            borderColor: COR.grade,
            borderWidth: 1,
            padding: [8, 10],
            textStyle: { color: COR.texto, fontFamily: FONTE, fontSize: 12 },
            extraCssText: 'box-shadow: 0 4px 16px rgba(0,0,0,.12); border-radius: 8px;',
            formatter: (itens) => `<div style="color:${COR.textoSecundario}">${escapar(itens[0].axisValueLabel)}</div>${itens
                .filter((item) => item.value !== null && item.value !== undefined)
                .map((item) => `<div style="display:flex;align-items:center;gap:8px;margin-top:4px"><span style="display:inline-block;width:14px;height:0;border-top:2px solid ${cores[item.seriesIndex]}"></span><strong>${numero.format(item.value)}</strong><span style="color:${COR.textoSecundario}">${escapar(item.seriesName)}</span></div>`)
                .join('')}`,
        },
        xAxis: {
            type: 'value',
            min: 0,
            max: 100,
            interval: 25,
            axisLabel: { color: COR.textoSecundario, fontFamily: FONTE, hideOverlap: true },
            splitLine: { lineStyle: { color: COR.grade, width: 1, type: 'solid' } },
            axisLine: { show: false },
            axisTick: { show: false },
        },
        yAxis: {
            type: 'category',
            inverse: true,
            data: dados.categorias,
            axisLabel: { color: COR.textoSecundario, fontFamily: FONTE, width: 130, overflow: 'break' },
            axisLine: { lineStyle: { color: COR.grade } },
            axisTick: { show: false },
        },
        series: dados.series.map((serie) => ({
            name: serie.nome,
            type: 'bar',
            barMaxWidth: 16,
            barGap: '30%',
            data: serie.dados,
            itemStyle: { borderRadius: [0, 4, 4, 0] },
            label: { show: true, position: 'right', formatter: (item) => (item.value === null || item.value === undefined ? '' : numero.format(item.value)), color: COR.texto, fontFamily: FONTE, fontSize: 11 },
        })),
    };
}
