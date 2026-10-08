<?php

namespace App\Http\Controllers;

use App\Integrations\Ibge\MalhaMunicipal;
use App\Models\Municipio;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrega o contorno dos municípios de uma UF para os mapas. Só serve UFs que o sistema acompanha.
 */
class MalhaController extends Controller
{
    public function __invoke(Request $request, int $uf, MalhaMunicipal $malha): Response
    {
        abort_unless(Municipio::query()->where('codigo_uf', $uf)->exists(), 404);

        $geojson = $malha->daUf($uf);

        abort_if($geojson === null, 503, 'A malha de municípios está indisponível no momento.');

        $resposta = response($geojson, 200, [
            'Content-Type' => 'application/geo+json',
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => '"'.md5($geojson).'"',
        ]);

        $resposta->isNotModified($request);

        return $resposta;
    }
}
