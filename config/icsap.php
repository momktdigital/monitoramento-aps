<?php

/*
|--------------------------------------------------------------------------
| Lista Brasileira de Internações por Condições Sensíveis à Atenção Primária (ICSAP)
|--------------------------------------------------------------------------
|
| Portaria SAS/MS nº 221, de 17/04/2008 (Anexo). Transcrita do texto oficial publicado no Saúde Legis
| (bvsms.saude.gov.br/bvs/saudelegis/sas/2008/prt0221_17_04_2008.html).
|
| Cada código é um prefixo da CID-10 SEM ponto. Um código de 3 caracteres (ex.: "A37") vale para toda a
| categoria (A37.0, A37.1...); um de 4 caracteres (ex.: "G000") vale só para aquela subcategoria.
| Faixas do texto original foram expandidas (ex.: "A33 a A35" => A33, A34, A35).
|
| Detalhes que o texto oficial define e que aqui são respeitados:
|  - Grupo 1: "Tuberculose pulmonar" cobre A15, A16 e A17.1-A17.9; a meningite tuberculosa (A17.0) é item
|    à parte. Juntos, A15, A16 e A17 inteiros.
|  - Grupo 13: diabetes inclui TODOS os subcódigos E10 a E14 (com coma/cetoacidose, com complicações e sem
|    complicações específicas, ex.: E10.9 e E14.9).
|  - Grupo 17: N70 a N73, N75 e N76 (N74 NÃO consta).
|
*/

return [

    1 => ['nome' => 'Doenças preveníveis por imunização e condições sensíveis', 'cids' => [
        'A37', 'A36', 'A33', 'A34', 'A35', 'B26', 'B06', 'B05', 'A95', 'B16', 'G000', 'A19',
        'A15', 'A16', 'A17', 'A18', 'I00', 'I01', 'I02', 'A51', 'A52', 'A53',
        'B50', 'B51', 'B52', 'B53', 'B54', 'B77',
    ]],

    2 => ['nome' => 'Gastroenterites infecciosas e complicações', 'cids' => [
        'E86', 'A00', 'A01', 'A02', 'A03', 'A04', 'A05', 'A06', 'A07', 'A08', 'A09',
    ]],

    3 => ['nome' => 'Anemia', 'cids' => ['D50']],

    4 => ['nome' => 'Deficiências nutricionais', 'cids' => [
        'E40', 'E41', 'E42', 'E43', 'E44', 'E45', 'E46',
        'E50', 'E51', 'E52', 'E53', 'E54', 'E55', 'E56', 'E57', 'E58', 'E59', 'E60', 'E61', 'E62', 'E63', 'E64',
    ]],

    5 => ['nome' => 'Infecções de ouvido, nariz e garganta', 'cids' => ['H66', 'J00', 'J01', 'J02', 'J03', 'J06', 'J31']],

    6 => ['nome' => 'Pneumonias bacterianas', 'cids' => ['J13', 'J14', 'J153', 'J154', 'J158', 'J159', 'J181']],

    7 => ['nome' => 'Asma', 'cids' => ['J45', 'J46']],

    8 => ['nome' => 'Doenças pulmonares', 'cids' => ['J20', 'J21', 'J40', 'J41', 'J42', 'J43', 'J44', 'J47']],

    9 => ['nome' => 'Hipertensão', 'cids' => ['I10', 'I11']],

    10 => ['nome' => 'Angina', 'cids' => ['I20']],

    11 => ['nome' => 'Insuficiência cardíaca', 'cids' => ['I50', 'J81']],

    12 => ['nome' => 'Doenças cerebrovasculares', 'cids' => ['I63', 'I64', 'I65', 'I66', 'I67', 'I69', 'G45', 'G46']],

    13 => ['nome' => 'Diabetes mellitus', 'cids' => ['E10', 'E11', 'E12', 'E13', 'E14']],

    14 => ['nome' => 'Epilepsias', 'cids' => ['G40', 'G41']],

    15 => ['nome' => 'Infecção no rim e trato urinário', 'cids' => ['N10', 'N11', 'N12', 'N30', 'N34', 'N390']],

    16 => ['nome' => 'Infecção da pele e tecido subcutâneo', 'cids' => ['A46', 'L01', 'L02', 'L03', 'L04', 'L08']],

    17 => ['nome' => 'Doença inflamatória de órgãos pélvicos femininos', 'cids' => ['N70', 'N71', 'N72', 'N73', 'N75', 'N76']],

    18 => ['nome' => 'Úlcera gastrointestinal', 'cids' => ['K25', 'K26', 'K27', 'K28', 'K920', 'K921', 'K922']],

    19 => ['nome' => 'Doenças relacionadas ao pré-natal e parto', 'cids' => ['O23', 'A50', 'P350']],

];
