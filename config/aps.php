<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies confiáveis
    |--------------------------------------------------------------------------
    |
    | Atrás de um proxy ou balanceador com HTTPS (nginx, Cloudflare, painel de hospedagem), o PHP recebe a requisição
    | em http e o proxy avisa o esquema original por cabeçalho. Informe aqui o IP do proxy (vários, separados por
    | vírgula) ou * para confiar no que estiver chamando. Vazio = não confiar em nenhum (padrão seguro, sem proxy).
    |
    */

    'proxies_confiaveis' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Fontes de dados (lista de hosts permitidos)
    |--------------------------------------------------------------------------
    |
    | Os conectores só falam com os endereços declarados aqui. Nenhuma URL vem de
    | formulário ou de dados externos, o que impede requisições a destinos arbitrários (SSRF).
    |
    */

    'fontes' => [
        'ibge_localidades' => 'https://servicodados.ibge.gov.br/api/v1/localidades',
        'ibge_sidra' => 'https://apisidra.ibge.gov.br',
        'ibge_malhas' => 'https://servicodados.ibge.gov.br/api/v3/malhas',
        'dados_abertos' => 'https://apidadosabertos.saude.gov.br',
        'egestor' => 'https://relatorioaps-prd.saude.gov.br',
        'transparencia' => 'https://api.portaldatransparencia.gov.br/api-de-dados',
    ],

    // O Portal da Transparência limita o número de requisições por minuto
    'transparencia' => [
        'pausa_ms' => 700,
        'espera_apos_limite_s' => 60,
        'tentativas_apos_limite' => 5,
    ],

    /*
    | DATASUS: arquivos .dbc por UF no FTP público. O servidor é fixo (nunca vem de formulário).
    | O Python 3 (só biblioteca padrão) descomprime os .dbc; informe o executável em APS_PYTHON se não for "python".
    */
    'datasus' => [
        'ftp_host' => 'ftp.datasus.gov.br',
        'ftp_timeout' => 120,
        'python' => env('APS_PYTHON', 'python'),
        'python_timeout' => 900,
        'diretorio_temporario' => storage_path('app/datasus'),
    ],

    'http' => [
        'timeout' => 30,
        'tentativas' => 3,
        'espera_ms' => 1000,
        'user_agent' => 'MonitoramentoAPS/1.0',
        // Pausa entre requisições, por cortesia com os serviços públicos e para respeitar limites de uso
        'pausa_ms' => 150,
    ],

    /*
    |--------------------------------------------------------------------------
    | Abrangência
    |--------------------------------------------------------------------------
    |
    | `ufs` define quais estados são carregados e usados como universo de comparação.
    | `municipios_piloto` (códigos IBGE de 7 dígitos) só marca o piloto na primeira
    | sincronização; depois, o campo `municipios.piloto` é a fonte da verdade.
    |
    */

    'ufs' => ['RJ'],

    'municipios_piloto' => [
        3300308, // Barra do Piraí
        3300407, // Barra Mansa
        3301801, // Engenheiro Paulo de Frontin
        3302254, // Itatiaia
        3302809, // Mendes
        3302908, // Miguel Pereira
        3303856, // Paty do Alferes
        3303955, // Pinheiral
        3304003, // Piraí
        3304110, // Porto Real
        3304128, // Quatis
        3304201, // Resende
        3304409, // Rio Claro
        3304508, // Rio das Flores
        3306107, // Valença
        3306206, // Vassouras
        3306305, // Volta Redonda
    ],

    // Trilha de auditoria: registros mais antigos que isto são apagados todo dia (mínimo de 30 dias).
    'auditoria' => [
        'retencao_dias' => (int) env('AUDITORIA_RETENCAO_DIAS', 365),
    ],

];
