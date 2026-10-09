{{-- Mensagens de resultado das ações (sucesso, aviso, erro), guardadas na sessão pelas telas da administração. --}}
@if (session('sucesso'))
    <div role="status" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('sucesso') }}</div>
@endif
@if (session('aviso'))
    <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ session('aviso') }}</div>
@endif
@if (session('erro'))
    <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-900">{{ session('erro') }}</div>
@endif
