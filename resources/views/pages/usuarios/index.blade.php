<?php

use App\Enums\Perfil;
use App\Enums\Permissao;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\User;
use App\Support\RegrasDeUsuario;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Usuários')] class extends Component {
    use OperaNoCondominioAtual, WithPagination;

    #[Url(except: '')]
    public string $busca = '';

    #[Url(except: '')]
    public string $perfil = '';

    public function mount(): void
    {
        $this->exigirPermissao(Permissao::UsuariosGerenciar);
    }

    public function updating(string $campo): void
    {
        if (in_array($campo, ['busca', 'perfil'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function usuarios()
    {
        $condominio = $this->condominio();
        $tabela = config('permission.table_names.model_has_roles');
        $roles = config('permission.table_names.roles');

        $usuarios = User::query()
            ->whereIn('id', fn ($q) => $q->select('model_id')->from($tabela)
                ->where('model_type', (new User)->getMorphClass())
                ->where('condominio_id', $condominio->id)
                ->when($this->perfil !== '', fn ($q) => $q->whereIn('role_id', fn ($q) => $q->select('id')->from($roles)->where('name', $this->perfil))))
            ->when($this->busca !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->busca}%")
                ->orWhere('email', 'like', "%{$this->busca}%")))
            ->orderBy('name')
            ->paginate(30);

        $usuarios->getCollection()->each(fn (User $u) => $u->setAttribute('perfis_aqui', $u->perfisNo($condominio)));

        return $usuarios;
    }

    public function removerDoCondominio(int $id): void
    {
        $this->exigirPermissao(Permissao::UsuariosGerenciar);
        $condominio = $this->condominio();
        $usuario = User::findOrFail($id);

        abort_unless($usuario->podeAcessarCondominio($condominio), 404);

        if (! RegrasDeUsuario::podeEditarPerfis(auth()->user(), $usuario)) {
            Flux::toast(variant: 'danger', text: __('Você não pode remover este usuário.'));

            return;
        }

        if (in_array(Perfil::Operador, $usuario->perfisNo($condominio), true) && ! auth()->user()->isSuperAdmin()) {
            Flux::toast(variant: 'danger', text: __('Operadores da central só podem ser removidos pelo administrador da plataforma.'));

            return;
        }

        $usuario->sincronizarPerfis([], $condominio);
        $condominio->pessoas()->where('user_id', $usuario->id)->update(['user_id' => null]);

        activity('usuario')->causedBy(auth()->user())->performedOn($usuario)
            ->withProperties(['condominio_id' => $condominio->id])
            ->log('Removido do condomínio');

        Flux::toast(variant: 'success', text: __(':nome não tem mais acesso a este condomínio.', ['nome' => $usuario->name]));
        unset($this->usuarios);
    }
}; ?>

<section class="w-full">
    <x-cabecalho-pagina :titulo="__('Usuários')" :subtitulo="__('Quem tem acesso ao sistema neste condomínio e com qual perfil.')">
        <x-slot:acoes>
            <flux:button variant="primary" icon="user-plus" :href="route('usuarios.criar')" wire:navigate>{{ __('Novo usuário') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.400ms="busca" icon="magnifying-glass" :placeholder="__('Nome ou e-mail')" class="sm:max-w-sm" />
        <flux:select wire:model.live="perfil" class="sm:max-w-56">
            <flux:select.option value="">{{ __('Todos os perfis') }}</flux:select.option>
            @foreach (Perfil::cases() as $p)
                <flux:select.option :value="$p->value">{{ $p->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->usuarios">
        <flux:table.columns>
            <flux:table.column>{{ __('Nome') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('E-mail') }}</flux:table.column>
            <flux:table.column>{{ __('Perfis') }}</flux:table.column>
            <flux:table.column class="max-lg:hidden">{{ __('Último acesso') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->usuarios as $usuario)
                <flux:table.row :key="$usuario->id">
                    <flux:table.cell>
                        <div class="flex items-center gap-2">
                            <flux:link variant="subtle" class="font-medium text-zinc-800! dark:text-white!" :href="route('usuarios.editar', $usuario)" wire:navigate>{{ $usuario->name }}</flux:link>
                            @if ($usuario->isSuperAdmin())
                                <flux:badge size="sm" color="violet">{{ __('Super admin') }}</flux:badge>
                            @endif
                            @unless ($usuario->ativo)
                                <flux:badge size="sm" color="red">{{ __('Desativado') }}</flux:badge>
                            @endunless
                            @if ($usuario->temDoisFatoresAtivo())
                                <flux:tooltip :content="__('2FA ativo')"><flux:icon.shield-check variant="micro" class="text-green-600" /></flux:tooltip>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="max-md:hidden">{{ $usuario->email }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($usuario->perfis_aqui as $p)
                                <flux:badge size="sm">{{ $p->label() }}</flux:badge>
                            @endforeach
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="max-lg:hidden">{{ $usuario->ultimo_login_em?->timezone($condominioAtual?->fuso_horario ?? config('app.timezone'))->format('d/m/Y H:i') ?? __('Nunca') }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown position="bottom" align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Ações')" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" :href="route('usuarios.editar', $usuario)" wire:navigate>{{ __('Editar') }}</flux:menu.item>
                                @if (RegrasDeUsuario::podeEditarPerfis(auth()->user(), $usuario))
                                    <flux:menu.separator />
                                    <flux:menu.item variant="danger" icon="user-minus" wire:click="removerDoCondominio({{ $usuario->id }})" wire:confirm="{{ __('Remover o acesso de :nome a este condomínio?', ['nome' => $usuario->name]) }}">{{ __('Remover do condomínio') }}</flux:menu.item>
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-8 text-center">{{ __('Nenhum usuário encontrado.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
