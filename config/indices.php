<?php

/*
|--------------------------------------------------------------------------
| Metodologia dos índices INA, IDAPS e IPF
|--------------------------------------------------------------------------
|
| Este arquivo é a metodologia PADRÃO. Ao calcular, o sistema compara o conteúdo com a versão ativa na tabela
| `metodologias`: se mudou (pesos, indicadores, limiares), cria uma nova versão e recalcula tudo. Cada linha de
| `indices_municipio` guarda a versão usada, então qualquer número pode ser reproduzido.
|
| ATENÇÃO: os pesos abaixo são PROVISÓRIOS. Devem ser validados com os gestores (oficina) antes do uso oficial.
|
| Como cada índice é calculado, para cada município e mês de referência:
|   1. cada indicador vira uma nota de 0 a 100 = posição (percentil) do município entre os pares do mesmo estado;
|      `sentido` diz se valor alto dá nota alta ("alto") ou baixa ("baixo");
|   2. as notas do pilar são a média ponderada dos indicadores disponíveis (`peso` relativo dentro do pilar);
|   3. o índice é a média ponderada dos pilares (`peso` do pilar). Dado ausente não vira zero: o peso é redistribuído
|      e a `confianca` (0 a 1) informa que parcela da metodologia foi de fato coberta;
|   4. um pilar `obrigatorio` sem nenhum dado impede o cálculo do índice (nota "sem dados suficientes").
|
| Um indicador só entra se: estiver ativo no catálogo e tiver valor para pelo menos `cobertura_minima_dos_pares`
| dos municípios do estado. Assim, fontes parciais (ex.: só o piloto carregado) não distorcem a comparação.
*/

return [
    // Universo de comparação: os municípios do mesmo estado.
    'pares' => 'uf',

    // Quantos meses de histórico calcular, terminando no último mês do indicador-âncora.
    'janela_meses' => 36,

    // Indicador mensal que define o último mês de referência (a série principal do e-Gestor).
    'ancora' => 'cobertura_esf',

    // Menos que isso de municípios no estado e a comparação não faz sentido.
    'minimo_de_pares' => 10,

    // Parcela mínima dos municípios do estado que precisa ter o indicador para ele entrar no cálculo.
    'cobertura_minima_dos_pares' => 0.8,

    // Abaixo desta confiança o índice não é calculado. A partir de `confianca_alta`, é rotulada "alta".
    'confianca_minima' => 0.7,
    'confianca_alta' => 0.9,

    // Até quantos meses antes do mês de referência um valor ainda vale ("último dado conhecido").
    // Pode ser sobrescrito por indicador com `validade_meses`.
    'validade_meses' => ['mensal' => 6, 'quadrimestral' => 12, 'anual' => 36],

    'ina' => [
        'nome' => 'Índice de Necessidade da APS',
        'pilares' => [
            'demografia' => [
                'nome' => 'Perfil demográfico',
                'peso' => 35,
                'componentes' => [
                    // Censo 2022: dado estrutural, vale por muitos anos.
                    'pct_menores_5_anos' => ['peso' => 1, 'sentido' => 'alto', 'validade_meses' => 120],
                    'pct_60_anos_ou_mais' => ['peso' => 1, 'sentido' => 'alto', 'validade_meses' => 120],
                ],
            ],
            'vulnerabilidade' => [
                'nome' => 'Vulnerabilidade social',
                'peso' => 45,
                'obrigatorio' => true,
                'componentes' => [
                    'pct_bolsa_familia' => ['peso' => 3, 'sentido' => 'alto'],
                    'pct_bpc' => ['peso' => 2, 'sentido' => 'alto'],
                    'pct_pobreza' => ['peso' => 3, 'sentido' => 'alto', 'validade_meses' => 120],
                    'renda_per_capita' => ['peso' => 3, 'sentido' => 'baixo', 'validade_meses' => 120],
                ],
            ],
            'dependencia_sus' => [
                'nome' => 'Dependência do SUS',
                'peso' => 20,
                'componentes' => [
                    'pct_sus_dependente' => ['peso' => 1, 'sentido' => 'alto'],
                ],
            ],
        ],
    ],

    'idaps' => [
        'nome' => 'Índice de Desempenho da APS',
        'pilares' => [
            'estrutura' => [
                'nome' => 'Estrutura e cobertura',
                'peso' => 35,
                'obrigatorio' => true,
                'componentes' => [
                    'cobertura_esf' => ['peso' => 3, 'sentido' => 'alto'],
                    'cobertura_aps' => ['peso' => 2, 'sentido' => 'alto'],
                    'cobertura_acs' => ['peso' => 1, 'sentido' => 'alto'],
                    'cobertura_saude_bucal' => ['peso' => 1, 'sentido' => 'alto'],
                    'equipes_esf_por_10mil' => ['peso' => 2, 'sentido' => 'alto'],
                    'ubs_por_10mil' => ['peso' => 1, 'sentido' => 'alto'],
                    'profissionais_aps_por_10mil' => ['peso' => 1, 'sentido' => 'alto'],
                ],
            ],
            'processo' => [
                'nome' => 'Acompanhamento e cuidado',
                'peso' => 25,
                'componentes' => [
                    'pre_natal_7_consultas' => ['peso' => 2, 'sentido' => 'alto'],
                    'pct_populacao_cadastrada' => ['peso' => 1, 'sentido' => 'alto'],
                    'hipertensao_acompanhada' => ['peso' => 1, 'sentido' => 'alto'],
                    'diabetes_acompanhada' => ['peso' => 1, 'sentido' => 'alto'],
                    'cobertura_vacinal_criancas' => ['peso' => 1, 'sentido' => 'alto'],
                ],
            ],
            'resultado' => [
                'nome' => 'Resultados em saúde',
                'peso' => 40,
                'obrigatorio' => true,
                'componentes' => [
                    'icsap_taxa' => ['peso' => 3, 'sentido' => 'baixo'],
                    'icsap_percentual' => ['peso' => 2, 'sentido' => 'baixo'],
                    'mortalidade_infantil' => ['peso' => 3, 'sentido' => 'baixo'],
                    'baixo_peso_nascer' => ['peso' => 1, 'sentido' => 'baixo'],
                    'mortalidade_evitavel' => ['peso' => 2, 'sentido' => 'baixo'],
                ],
            ],
        ],
    ],

    // Matriz INA × IDAPS. O corte divide "alto" e "baixo": 'mediana' (dos municípios calculados no mês) ou um número de 0 a 100.
    'ipf' => [
        'corte' => 'mediana',
        'quadrantes' => [
            'necessidade_alta_desempenho_baixo' => 'prioridade_maxima',
            'necessidade_alta_desempenho_alto' => 'grande_potencial',
            'necessidade_baixa_desempenho_baixo' => 'oportunidade_moderada',
            'necessidade_baixa_desempenho_alto' => 'estrutura_consolidada',
        ],
    ],

    // Regra de efetividade: estrutura forte e resultado fraco (notas de 0 a 100).
    'efetividade' => [
        'pilar_estrutura' => 'estrutura',
        'pilar_resultado' => 'resultado',
        'estrutura_minima' => 60,
        'resultado_maximo' => 40,
    ],
];
