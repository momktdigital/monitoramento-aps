<?php

/*
|--------------------------------------------------------------------------
| Painel padrão: áreas prontas
|--------------------------------------------------------------------------
|
| Todo usuário começa com estas áreas (abas), cada uma reunindo os visuais de um assunto. Para cada indicador
| da lista entram os visuais de `visuais_por_indicador`, nessa ordem; destaque + evolução e ranking + mapa
| completam uma linha de três colunas. O usuário pode renomear, remover, reordenar e criar áreas, e restaurar
| uma área ou o painel inteiro a qualquer momento.
|
| Indicadores que ainda não estão ativos no catálogo são omitidos (entram sozinhos quando a fonte for ativada).
| Áreas sem nenhum indicador ativo não são criadas. Indicador ativo que não conste em nenhuma área vai para
| "Outros indicadores".
|
*/

return [
    'maximo_de_areas' => 12,

    'visuais_por_indicador' => ['destaque', 'evolucao', 'ranking', 'mapa'],

    'outros' => ['chave' => 'outros', 'nome' => 'Outros indicadores', 'descricao' => 'Indicadores que ainda não pertencem a nenhum assunto.'],

    'areas' => [
        'visao_geral' => [
            'nome' => 'Visão geral',
            'descricao' => 'Os três índices: necessidade, desempenho e prioridade de apoio, com a matriz que cruza os dois.',
            'inicio' => [['matriz', 'indice_prioridade']],
            'indicadores' => ['indice_prioridade', 'indice_ina', 'indice_idaps'],
        ],
        'populacao' => [
            'nome' => 'População',
            'descricao' => 'Quantas pessoas vivem no município e como elas se distribuem por idade.',
            'indicadores' => ['populacao_total', 'pct_menores_5_anos', 'pct_60_anos_ou_mais', 'densidade_demografica', 'pct_sus_dependente'],
        ],
        'vulnerabilidade' => [
            'nome' => 'Vulnerabilidade social',
            'descricao' => 'Benefícios sociais, renda e pobreza: o quanto a população depende do apoio público.',
            'indicadores' => ['pct_bolsa_familia', 'pct_bpc', 'renda_per_capita', 'pct_pobreza'],
        ],
        'cobertura' => [
            'nome' => 'Cobertura e estrutura',
            'descricao' => 'Quanto da população tem equipe de saúde, e quantas equipes e unidades existem.',
            'indicadores' => ['cobertura_aps', 'cobertura_esf', 'cobertura_acs', 'cobertura_saude_bucal', 'equipes_esf', 'equipes_esf_por_10mil', 'ubs_por_10mil', 'profissionais_aps_por_10mil'],
        ],
        'materno_infantil' => [
            'nome' => 'Mulher e criança',
            'descricao' => 'Pré-natal, peso ao nascer, mortalidade infantil e vacinação.',
            'indicadores' => ['pre_natal_7_consultas', 'baixo_peso_nascer', 'mortalidade_infantil', 'cobertura_vacinal_criancas'],
        ],
        'internacoes' => [
            'nome' => 'Internações e mortalidade',
            'descricao' => 'Resultados finais da rede: internações que a Atenção Primária poderia evitar e mortes evitáveis.',
            'indicadores' => ['icsap_taxa', 'icsap_percentual', 'mortalidade_evitavel'],
        ],
        'cronicos' => [
            'nome' => 'Crônicos e cadastro',
            'descricao' => 'Acompanhamento de hipertensão e diabetes, e cadastro da população.',
            'indicadores' => ['hipertensao_acompanhada', 'diabetes_acompanhada', 'pct_populacao_cadastrada'],
        ],
        'financiamento' => [
            'nome' => 'Financiamento',
            'descricao' => 'Quanto o município investe e recebe para a saúde.',
            'indicadores' => ['gasto_proprio_saude_pct', 'repasse_aps_per_capita'],
        ],
    ],
];
