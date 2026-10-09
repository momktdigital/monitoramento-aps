import Sortable from 'sortablejs';

// Componentes Alpine registrados por nome (o Livewire usa o build compatível com CSP estrita,
// que não aceita expressões inline: o comportamento fica aqui e os templates só o referenciam).
document.addEventListener('alpine:init', () => {
    // Balão do botão "?": abre e fecha a explicação de um visual.
    window.Alpine.data('ajuda', () => ({
        aberto: false,
        alternar() {
            this.aberto = !this.aberto;
        },
        fechar() {
            this.aberto = false;
        },
        // Clique fora do balão fecha. O próprio botão "?" fica de fora dessa regra: ele já alterna o balão, e fechar de novo
        // no mesmo clique faria o balão abrir e fechar na hora.
        fecharFora(evento) {
            if (!this.$root.contains(evento.target)) {
                this.aberto = false;
            }
        },
        get oculto() {
            return !this.aberto;
        },
    }));

    // Leva o foco do teclado para o campo marcado com x-ref="campo" assim que o diálogo aparece (o autofocus do HTML
    // só vale quando a página carrega, não para diálogos que o Livewire insere depois).
    window.Alpine.data('focoInicial', () => ({
        init() {
            setTimeout(() => this.$refs.campo?.focus(), 50);
        },
    }));

    // Botão "Copiar" de um valor mostrado uma única vez (senha temporária): copia o texto do elemento marcado com x-ref="valor".
    window.Alpine.data('copiar', () => ({
        copiado: false,
        async copiar() {
            const texto = this.$refs.valor?.value ?? this.$refs.valor?.textContent ?? '';

            try {
                await navigator.clipboard.writeText(texto);
            } catch {
                this.$refs.valor?.select?.();
                document.execCommand('copy');
            }

            this.copiado = true;
            setTimeout(() => (this.copiado = false), 2500);
        },
        get rotulo() {
            return this.copiado ? 'Copiado!' : 'Copiar';
        },
    }));

    // Cartão de um visual: alterna entre gráfico e tabela, amplia o cartão sobre a página e baixa imagem ou planilha.
    window.Alpine.data('cartao', () => ({
        expandido: false,
        vista: 'grafico',
        get classes() {
            return this.expandido ? '!fixed inset-3 z-50 !h-auto overflow-y-auto shadow-2xl sm:inset-8' : '';
        },
        get estado() {
            return this.expandido ? 'sim' : 'nao';
        },
        get fechado() {
            return !this.expandido;
        },
        get mostraGrafico() {
            return this.vista === 'grafico';
        },
        get mostraTabela() {
            return this.vista === 'tabela';
        },
        alternarVista() {
            this.vista = this.mostraGrafico ? 'tabela' : 'grafico';
        },
        alternarExpansao() {
            const abrindo = !this.expandido;

            // Enquanto o cartão flutua, a grade guarda o lugar dele para o resto da página não pular.
            this.$root.style.minHeight = abrindo ? `${this.$root.offsetHeight}px` : '';
            this.expandido = abrindo;
            document.body.classList.toggle('overflow-hidden', abrindo);
            setTimeout(() => window.dispatchEvent(new Event('resize')), 50);
        },
        fechar() {
            if (this.expandido) {
                this.alternarExpansao();
            }
        },
        async baixarImagem() {
            const area = this.$root.querySelector('[data-area-grafico]');

            if (area) {
                (await import('./graficos.js')).baixarImagem(area, this.$root.dataset.nome);
            }
        },
        baixarCsv() {
            const tabela = this.$root.querySelector('table[data-tabela]');

            if (!tabela) {
                return;
            }

            const celula = (elemento) => `"${elemento.textContent.trim().replace(/\s+/g, ' ').replace(/"/g, '""')}"`;
            const linhas = [...tabela.querySelectorAll('tr')].map((linha) => [...linha.children].map(celula).join(';'));
            // BOM no início: o Excel em português abre os acentos corretamente.
            const arquivo = new Blob([`﻿${linhas.join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
            const endereco = URL.createObjectURL(arquivo);
            const ligacao = document.createElement('a');

            ligacao.href = endereco;
            ligacao.download = `${this.$root.dataset.nome}.csv`;
            ligacao.click();
            setTimeout(() => URL.revokeObjectURL(endereco), 1000);
        },
        destroy() {
            document.body.classList.remove('overflow-hidden');
        },
    }));

    // Grade de visuais reordenável por arrastar e soltar. Só é criada no modo de edição.
    window.Alpine.data('reordenavel', () => ({
        init() {
            Sortable.create(this.$el, {
                animation: 150,
                handle: '[data-arrastar]',
                draggable: '[data-id]',
                ghostClass: 'opacity-40',
                onEnd: () => {
                    const ids = [...this.$el.querySelectorAll(':scope > [data-id]')].map((item) => Number(item.dataset.id));

                    this.$wire.reordenar(ids);
                },
            });
        },
    }));

    // Gráfico ECharts. O módulo do ECharts só é baixado quando o primeiro gráfico aparece.
    window.Alpine.data('grafico', () => ({
        grafico: null,
        observador: null,
        redimensionador: null,
        async init() {
            const modulo = await import('./graficos.js');
            const area = this.$refs.area;
            const ler = () => JSON.parse(this.$el.dataset.dados);

            this.grafico = await modulo.criarGrafico(area, ler());

            // Gráficos clicáveis (ranking, mapa e matriz): o clique em um município o coloca em foco em toda a página.
            if (this.$el.dataset.clicavel !== undefined) {
                this.grafico.on('click', (evento) => {
                    const id = Number(evento?.data?.id);

                    if (Number.isInteger(id) && id > 0) {
                        window.Livewire.dispatch('municipio-escolhido', { id });
                    }
                });
            }

            // Quando o Livewire atualiza os dados (outro município, outra janela), o atributo muda: redesenha.
            this.observador = new MutationObserver(() => modulo.atualizarGrafico(this.grafico, ler()));
            this.observador.observe(this.$el, { attributes: true, attributeFilter: ['data-dados'] });

            this.redimensionador = new ResizeObserver(() => this.grafico.resize());
            this.redimensionador.observe(area);
        },
        destroy() {
            this.observador?.disconnect();
            this.redimensionador?.disconnect();
            this.grafico?.dispose();
        },
    }));
});
