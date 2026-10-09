<?php

use App\Http\Controllers\Admin\ExportarAuditoriaController;
use App\Http\Controllers\MalhaController;
use App\Http\Controllers\MetodologiaController;
use App\Livewire\Admin\Auditoria;
use App\Livewire\Admin\Indicadores;
use App\Livewire\Admin\Indices;
use App\Livewire\Admin\Inicio;
use App\Livewire\Admin\Municipios;
use App\Livewire\Admin\Sistema;
use App\Livewire\Admin\Usuarios;
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

Route::middleware(['auth', 'auth.session', 'senha-trocada', 'two-factor-setup'])->group(function (): void {
    Route::get('/painel', Painel::class)->name('painel');
    Route::get('/matriz', Matriz::class)->name('matriz');
    Route::get('/comparar', Comparar::class)->name('comparar');
    Route::get('/mapa', Mapa::class)->name('mapa');
    Route::get('/dados/malha/{uf}', MalhaController::class)->whereNumber('uf')->middleware('throttle:60,1')->name('malha');
    Route::get('/conta/seguranca', Seguranca::class)->name('conta.seguranca');

    // Endereço antigo da tela de integrações, que agora faz parte da administração.
    Route::redirect('/integracoes', '/admin/integracoes');

    // Administração: só administradores ativos (Gate "administrar"). Cada ação sensível ainda pede a senha de novo.
    Route::middleware('can:administrar')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', Inicio::class)->name('inicio');
        Route::get('/usuarios', Usuarios::class)->name('usuarios');
        Route::get('/integracoes', PainelDeIntegracoes::class)->name('integracoes');
        Route::get('/municipios', Municipios::class)->name('municipios');
        Route::get('/indicadores', Indicadores::class)->name('indicadores');
        Route::get('/indices', Indices::class)->name('indices');
        Route::get('/auditoria', Auditoria::class)->name('auditoria');
        Route::get('/auditoria/exportar', ExportarAuditoriaController::class)->middleware('throttle:10,1')->name('auditoria.exportar');
        Route::get('/sistema', Sistema::class)->name('sistema');
    });
});
