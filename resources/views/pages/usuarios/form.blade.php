<?php

use App\Enums\Perfil;
use App\Enums\Permissao;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Pessoa;
use App\Models\User;
use App\Support\RegrasDeUsuario;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use OperaNoCondominioAtual;

    #[Locked]
    public ?int $usuarioId = null;

    public string $name = '';
    public string $email = '';
    public string $password = '';

    /** @var list<string> */
    public array $perfis = [];

    public string $pessoa_id = '';
    public bool $super_admin = false;
    public bool $ativo = true;

    public function mount(?User $usuario = null): void
    {
        $this->exigirPermissao(Permissao::UsuariosGerenciar);

        if ($usuario?->exists) {
            $this->autorizarAlvo($usuario);
            $this->usuarioId = $usuario->id;
            $this->name = $usuario->name;
            $this->email = $usuario->email;
            $this->super_admin = $usuario->super_admin;
            $this->ativo = $usuario->ativo;
            $this->perfis = array_map(fn (Perfil $p) => $p->value, $usuario->perfisNo($this->condominio()));
            $this->pessoa_id = (string) $this->condominio()->pessoas()->where('user_id', $usuario->id)->value('id');
        } else {
            $this->perfis = [Perfil::Morador->value];
        }
    }

    public function render()
    {
        return $this->view()->title($this->usuarioId ? __('Editar usuário') : __('Novo usuário'));
    }

    #[Computed]
    public function alvo(): ?User
    {
        return $this->usuarioId ? User::find($this->usuarioId) : null;
    }

    #[Computed]
    public function podeEditarConta(): bool
    {
        return $this->alvo === null || RegrasDeUsuario::podeEditarConta(auth()->user(), $this->alvo, $this->condominio());
    }

    #[Computed]
    public function podeEditarPerfis(): bool
    {
        return $this->alvo === null || RegrasDeUsuario::podeEditarPerfis(auth()->user(), $this->alvo);
    }

    #[Computed]
    public function podeAdministrar(): bool
    {
        return $this->alvo === null
            ? auth()->user()->isSuperAdmin()
            : RegrasDeUsuario::podeAdministrarConta(auth()->user(), $this->alvo);
    }

    /**
     * @return list<Perfil>
     */
    #[Computed]
    public function perfisDisponiveis(): array
    {
        return RegrasDeUsuario::perfisAtribuiveis(auth()->user());
    }

    #[Computed]
    public function pessoas()
    {
        return $this->condominio()->pessoas()
            ->where('ativo', true)
            ->where(fn ($q) => $q->whereNull('user_id')->when($this->usuarioId, fn ($q) => $q->orWhere('user_id', $this->usuarioId)))
            ->orderBy('nome')
            ->get(['id', 'nome']);
    }

    public function gerarSenha(): void
    {
        $this->password = Str::password(12, symbols: false);
    }

    public function salvar(): void
    {
        $this->exigirPermissao(Permissao::UsuariosGerenciar);
        $ator = auth()->user();
        $condominio = $this->condominio();
        $alvo = $this->usuarioId ? User::findOrFail($this->usuarioId) : null;

        if ($alvo) {
            $this->autorizarAlvo($alvo);
        }

        $this->email = Str::lower(trim($this->email));
        $existente = $alvo ? null : User::where('email', $this->email)->first();
        $editaConta = $existente === null && ($alvo === null || RegrasDeUsuario::podeEditarConta($ator, $alvo, $condominio));

        $this->validate([
            'name' => $editaConta ? ['required', 'string', 'max:255'] : [],
            'email' => $editaConta ? ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->usuarioId)] : [],
            'password' => $editaConta ? [$alvo ? 'nullable' : 'required', Password::min(8)] : [],
            'perfis' => ['array'],
            'perfis.*' => [Rule::in(array_map(fn (Perfil $p) => $p->value, Perfil::cases()))],
            'pessoa_id' => ['nullable', Rule::exists('pessoas', 'id')->where('condominio_id', $condominio->id)],
        ], attributes: ['name' => 'nome', 'password' => 'senha', 'pessoa_id' => 'pessoa']);

        if (! $ator->isSuperAdmin() && ! $this->super_admin && $this->perfis === [] && ! $alvo) {
            $this->addError('perfis', __('Selecione ao menos um perfil.'));

            return;
        }

        // Conta
        if ($existente) {
            $usuario = $existente;
        } else {
            $usuario = $alvo ?? new User;

            if ($editaConta) {
                $usuario->name = $this->name;
                $usuario->email = $this->email;
                if ($this->password !== '') {
                    $usuario->password = $this->password;
                }
            }

            if ($this->podeAdministrar) {
                $usuario->super_admin = $this->super_admin;
                $usuario->ativo = $this->ativo;
            }

            $usuario->email_verified_at ??= now();
            $usuario->save();
        }

        // Perfis neste condomínio
        if ($existente || RegrasDeUsuario::podeEditarPerfis($ator, $usuario)) {
            $atribuiveis = RegrasDeUsuario::perfisAtribuiveis($ator);
            $atuais = $usuario->perfisNo($condominio);

            // Mantém os perfis que o usuário logado não pode alterar (ex.: operador).
            $novos = collect($atuais)->reject(fn (Perfil $p) => in_array($p, $atribuiveis, true))
                ->merge(collect($this->perfis)->map(fn ($v) => Perfil::from($v))->filter(fn (Perfil $p) => in_array($p, $atribuiveis, true)))
                ->unique(fn (Perfil $p) => $p->value)
                ->values()
                ->all();

            $usuario->sincronizarPerfis($novos, $condominio);
        }

        // Pessoa vinculada (neste condomínio)
        $condominio->pessoas()->where('user_id', $usuario->id)->where('id', '!=', (int) $this->pessoa_id)->update(['user_id' => null]);
        if ($this->pessoa_id !== '') {
            Pessoa::query()->doCondominio($condominio)->whereKey((int) $this->pessoa_id)->update(['user_id' => $usuario->id]);
        }

        Flux::toast(variant: 'success', text: $existente
            ? __('Já existia um usuário com este e-mail: ele foi adicionado a este condomínio com os perfis escolhidos.')
            : __('Usuário salvo.'));

        $this->redirectRoute('usuarios.index', navigate: true);
    }

    public function redefinirDoisFatores(): void
    {
        $this->exigirPermissao(Permissao::UsuariosGerenciar);
        $alvo = User::findOrFail($this->usuarioId);
        abort_unless(RegrasDeUsuario::podeAdministrarConta(auth()->user(), $alvo), 403);

        $alvo->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        activity('usuario')->causedBy(auth()->user())->performedOn($alvo)->log('2FA redefinido pelo administrador');

        Flux::toast(variant: 'success', text: __('2FA redefinido. No próximo acesso o usuário precisará configurar de novo.'));
    }

    /**
     * O usuário editado precisa ter acesso ao condomínio atual
     * (exceto quando quem edita é o super admin).
     */
    private function autorizarAlvo(User $alvo): void
    {
        if (auth()->user()->isSuperAdmin()) {
            return;
        }

        abort_unless($alvo->podeAcessarCondominio($this->condominio()) && ! $alvo->isSuperAdmin(), 404);
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-cabecalho-pagina :titulo="$usuarioId ? __('Editar usuário') : __('Novo usuário')" :subtitulo="$usuarioId ? $email : __('Dá acesso ao sistema neste condomínio.')">
        <x-slot:acoes>
            <flux:button variant="ghost" icon="arrow-left" :href="route('usuarios.index')" wire:navigate>{{ __('Voltar') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <form wire:submit="salvar" class="space-y-8">
        <flux:fieldset>
            <flux:legend>{{ __('Conta de acesso') }}</flux:legend>

            @if (! $this->podeEditarConta)
                <flux:callout icon="lock-closed" variant="secondary" class="mb-4">
                    <flux:callout.text>{{ __('Este usuário também tem acesso a outros condomínios. Nome, e-mail e senha só podem ser alterados por ele mesmo ou pelo administrador da plataforma.') }}</flux:callout.text>
                </flux:callout>
            @elseif (! $usuarioId)
                <flux:description class="mb-4">{{ __('Se o e-mail já tiver cadastro (por exemplo, em outro condomínio), a pessoa apenas ganha acesso a este condomínio, com a mesma senha de sempre.') }}</flux:description>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Nome')" :disabled="! $this->podeEditarConta" required />
                <flux:input wire:model="email" type="email" :label="__('E-mail (login)')" :disabled="! $this->podeEditarConta" required />
                @if ($this->podeEditarConta)
                    <flux:input wire:model="password" type="text" :label="$usuarioId ? __('Nova senha') : __('Senha inicial')" :description="$usuarioId ? __('Deixe em branco para manter a atual.') : __('Mínimo de 8 caracteres. Passe para a pessoa por um canal seguro.')" autocomplete="new-password">
                        <x-slot name="iconTrailing">
                            <flux:button size="sm" variant="subtle" icon="sparkles" class="-me-1" wire:click="gerarSenha" :tooltip="__('Gerar senha')" />
                        </x-slot>
                    </flux:input>
                @endif
                <flux:select wire:model="pessoa_id" :label="__('Pessoa vinculada')" :description="__('Liga o login ao cadastro do morador ou funcionário.')">
                    <flux:select.option value="">{{ __('Nenhuma') }}</flux:select.option>
                    @foreach ($this->pessoas as $p)
                        <flux:select.option :value="$p->id">{{ $p->nome }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Perfis neste condomínio') }}</flux:legend>
            @if ($this->podeEditarPerfis)
                <flux:checkbox.group wire:model="perfis">
                    @foreach (Perfil::cases() as $p)
                        <flux:checkbox
                            :value="$p->value"
                            :label="$p->label()"
                            :description="collect($p->permissoes())->map->label()->join(' · ').($p->exige2fa() ? ' · '.__('exige 2FA') : '')"
                            :disabled="! in_array($p, $this->perfisDisponiveis, true)"
                        />
                    @endforeach
                </flux:checkbox.group>
                <flux:error name="perfis" />
            @else
                <flux:text>{{ __('Os perfis deste usuário não podem ser alterados por você.') }}</flux:text>
            @endif
        </flux:fieldset>

        @if ($this->podeAdministrar)
            <flux:fieldset>
                <flux:legend>{{ __('Administração da plataforma') }}</flux:legend>
                <div class="space-y-4">
                    <flux:switch wire:model="ativo" :label="__('Usuário ativo')" :description="__('Desativado, não consegue entrar em nenhum condomínio.')" />
                    <flux:switch wire:model="super_admin" :label="__('Super admin')" :description="__('Acesso total a todos os condomínios. Exige 2FA.')" />
                    @if ($usuarioId && $this->alvo?->temDoisFatoresAtivo())
                        <flux:button size="sm" icon="shield-exclamation" wire:click="redefinirDoisFatores" wire:confirm="{{ __('Redefinir o 2FA deste usuário? Use quando ele perder o celular.') }}">{{ __('Redefinir 2FA') }}</flux:button>
                    @endif
                </div>
            </flux:fieldset>
        @endif

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="route('usuarios.index')" wire:navigate>{{ __('Cancelar') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="check">{{ __('Salvar') }}</flux:button>
        </div>
    </form>
</section>
