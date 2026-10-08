<?php

use App\Http\Controllers\MalhaController;
use App\Http\Controllers\MetodologiaController;
use App\Livewire\Analises\Comparar;
use App\Livewire\Analises\Mapa;
use App\Livewire\Analises\Matriz;
use App\Livewire\Conta\Seguranca;
use App\Livewire\Integracoes\Painel as PainelDeIntegracoes;
use App\Livewire\Painel;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/painel');

// Página pública: explica os índices, os pesos e as fontes. Não contém dados de usuários.
Route::get('/metodologia', MetodologiaController::class)->middleware('throttle:60,1')->name('metodologia');

Route::middleware(['auth', 'auth.session', 'two-factor-setup'])->group(function (): void {
    Route::get('/painel', Painel::class)->name('painel');
    Route::get('/matriz', Matriz::class)->name('matriz');
    Route::get('/comparar', Comparar::class)->name('comparar');
    Route::get('/mapa', Mapa::class)->name('mapa');
    Route::get('/dados/malha/{uf}', MalhaController::class)->whereNumber('uf')->middleware('throttle:60,1')->name('malha');
    Route::get('/conta/seguranca', Seguranca::class)->name('conta.seguranca');
    Route::get('/integracoes', PainelDeIntegracoes::class)->middleware('can:gerenciar-integracoes')->name('integracoes');
});
