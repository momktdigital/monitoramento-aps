@php
    $campo = 'mt-1.5 block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30';
@endphp

<div class="space-y-5">
    <header>
        <h1 class="text-2xl font-semibold">Mapa</h1>
        <p class="mt-1 text-sm text-tinta-suave">Veja como um indicador ou índice se distribui pelos municípios do estado.</p>
    </header>

    <div class="flex flex-wrap items-end gap-4 rounded-2xl border border-linha bg-white p-4 shadow-sm">
        <div class="min-w-56 grow sm:grow-0">
            <label for="indicador" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">O que mostrar no mapa</label>
            <select id="indicador" wire:model.live="indicador" class="{{ $campo }}">
                @foreach ($opcoes as $rotuloDoGrupo => $doGrupo)
                    <optgroup label="{{ $rotuloDoGrupo }}">
                        @foreach ($doGrupo as $opcao)
                            <option value="{{ $opcao->codigo }}" @selected($opcao->codigo === $indicadorAtual?->codigo)>{{ $opcao->nome }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>

        <div class="min-w-56 grow sm:grow-0">
            <label for="municipio" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">Município em foco</label>
            <select id="municipio" wire:model.live="municipioId" class="{{ $campo }}">
                @foreach ($municipios as $regiao => $doGrupo)
                    <optgroup label="{{ $regiao ?: 'Sem região de saúde' }}">
                        @foreach ($doGrupo as $item)
                            <option value="{{ $item->id }}">{{ $item->nome }}{{ $item->piloto ? ' ★' : '' }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
    </div>

    @if ($semDados)
        <div class="rounded-2xl border border-dashed border-linha bg-white p-10 text-center">
            <p class="font-medium">Ainda não há dados para desenhar o mapa.</p>
            <p class="mt-1 text-sm text-tinta-suave">Os mapas aparecem automaticamente depois que as integrações carregam os dados dos indicadores.</p>
        </div>
    @else
        <div class="grid items-start gap-5 lg:grid-cols-3">
            <div class="min-w-0 lg:col-span-2">
                <x-analise.cartao id="titulo-mapa" :titulo="$indicadorAtual->nome" :subtitulo="'Dado de '.$competenciaRotulo.' · '.$municipio->uf" :secoes="$secoes" :analise="$analise" :fonte="$indicadorAtual->fonteRotulo()">
                    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs" aria-label="Legenda do mapa">
                        <span class="font-medium text-tinta-suave">Menor</span>
                        @foreach ($grafico['faixas'] as $faixa)
                            <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="inline-block size-3.5 rounded-sm" style="background: {{ $faixa['cor'] }}"></span>{{ $faixa['rotulo'] }}</span>
                        @endforeach
                        <span class="font-medium text-tinta-suave">Maior</span>
                        @if ($grafico['sem_dado'] > 0)
                            <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="inline-block size-3.5 rounded-sm border border-linha" style="background: #e8e6df"></span>Sem dado</span>
                        @endif
                    </div>

                    <x-painel.grafico :dados="$grafico" :titulo="'Mapa de '.$indicadorAtual->nome" altura="h-[28rem]" :clicavel="true" />

                    <p class="mt-2 text-xs text-tinta-suave">O contorno escuro marca {{ $municipio->nome }}. Clique em um município do mapa para colocá-lo em foco.</p>
                </x-analise.cartao>
            </div>

            <section class="min-w-0 rounded-2xl border border-linha bg-white p-5 shadow-sm" aria-labelledby="titulo-lista">
                <h2 id="titulo-lista" class="text-sm font-semibold">Valores por município</h2>
                <p class="mt-0.5 text-xs text-tinta-suave">{{ $indicadorAtual->polaridade === \App\Enums\Polaridade::Neutra ? 'Do maior para o menor valor.' : 'Da melhor para a pior situação.' }}</p>

                <div class="mt-3 max-h-[34rem] overflow-auto rounded-lg border border-linha">
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 bg-fundo text-xs text-tinta-suave">
                            <tr>
                                <th scope="col" class="px-3 py-2 font-medium">Município</th>
                                <th scope="col" class="px-3 py-2 text-right font-medium">Valor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-linha">
                            @foreach ($tabela['linhas'] as $posicao => $linha)
                                @php $idDaLinha = $tabela['ids'][$posicao]; @endphp
                                <tr wire:key="mapa-{{ $idDaLinha }}" @class(['bg-marca-50' => $idDaLinha === $municipio->id])>
                                    <th scope="row" class="px-3 py-1.5 font-medium">
                                        <button type="button" wire:click="selecionar({{ $idDaLinha }})" class="text-left underline decoration-linha underline-offset-2 hover:decoration-marca-600 focus:outline-2 focus:outline-offset-2 focus:outline-marca-600" @if ($idDaLinha === $municipio->id) aria-current="true" @endif>{{ $linha[0] }}</button>
                                    </th>
                                    <td class="px-3 py-1.5 text-right tabular-nums">{{ $linha[2] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    @endif
</div>
