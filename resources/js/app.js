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

            // Gráficos clicáveis (matriz e mapa): o clique em um município o coloca em foco na página.
            if (this.$el.dataset.clicavel !== undefined) {
                this.grafico.on('click', (evento) => {
                    const id = Number(evento?.data?.id);

                    if (Number.isInteger(id) && id > 0) {
                        this.$wire.selecionar(id);
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
