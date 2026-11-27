<?php

use App\Enums\Permissao;
use App\Enums\TipoPessoa;
use App\Enums\VinculoUnidade;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Pessoa;
use App\Models\Unidade;
use App\Rules\Cpf;
use App\Support\Documento;
use App\Support\FotoDePessoa;
use App\Support\SincronizacaoFacial;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use OperaNoCondominioAtual, WithFileUploads;

    #[Locked]
    public ?int $pessoaId = null;

    public string $nome = '';
    public string $tipo = 'morador';
    public string $cpf = '';
    public string $rg = '';
    public string $data_nascimento = '';
    public string $telefone = '';
    public string $email = '';
    public bool $ativo = true;
    public string $observacoes = '';

    /** Foto nova (upload ou câmera do celular). */
    public $foto = null;

    public bool $removerFoto = false;

    public bool $biometria_autorizada = false;

    #[Locked]
    public bool $temFoto = false;

    #[Locked]
    public ?string $versaoFoto = null;

    #[Locked]
    public ?string $pessoaUuid = null;

    /** @var list<array{unidade_id: string, vinculo: string, responsavel: bool}> */
    public array $vinculos = [];

    public function mount(?Pessoa $pessoa = null): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);

        if ($pessoa?->exists) {
            $this->garantirDoCondominio($pessoa);
            $this->pessoaId = $pessoa->id;
            $this->fill(collect($pessoa->only(['nome', 'cpf', 'rg', 'telefone', 'email', 'observacoes']))->map(fn ($v) => (string) $v)->all());
            $this->tipo = $pessoa->tipo->value;
            $this->data_nascimento = (string) $pessoa->data_nascimento?->format('Y-m-d');
            $this->ativo = $pessoa->ativo;
            $this->pessoaUuid = $pessoa->uuid;
            $this->temFoto = $pessoa->foto_path !== null;
            $this->versaoFoto = (string) $pessoa->updated_at?->timestamp;
            $this->biometria_autorizada = $pessoa->biometria_autorizada_em !== null;
            $this->vinculos = $pessoa->unidades->map(fn (Unidade $u) => [
                'unidade_id' => (string) $u->id,
                'vinculo' => $u->pivot->vinculo,
                'responsavel' => (bool) $u->pivot->responsavel,
            ])->values()->all();
        } else {
            $this->vinculos = [['unidade_id' => '', 'vinculo' => VinculoUnidade::Proprietario->value, 'responsavel' => true]];
        }
    }

    public function render()
    {
        return $this->view()->title($this->pessoaId ? __('Editar pessoa') : __('Nova pessoa'));
    }

    #[Computed]
    public function unidades()
    {
        return Unidade::query()->doCondominio($this->condominio())->ordenadas()->with('bloco')->get();
    }

    #[Computed]
    public function usuario()
    {
        return $this->pessoaId ? Pessoa::find($this->pessoaId)?->usuario : null;
    }

    #[Computed]
    public function leitoras()
    {
        return app(SincronizacaoFacial::class)->leitoras($this->condominio());
    }

    /**
     * Situação da pessoa em cada leitora facial.
     */
    #[Computed]
    public function situacaoNasLeitoras()
    {
        return $this->pessoaId ? (Pessoa::find($this->pessoaId)?->leitoras ?? collect())->keyBy('id') : collect();
    }

    public function adicionarVinculo(): void
    {
        $this->vinculos[] = ['unidade_id' => '', 'vinculo' => VinculoUnidade::Proprietario->value, 'responsavel' => false];
    }

    public function removerVinculo(int $indice): void
    {
        unset($this->vinculos[$indice]);
        $this->vinculos = array_values($this->vinculos);
    }

    public function salvar(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
        $condominioId = $this->condominio()->id;

        $this->cpf = (string) Documento::formatarCpf($this->cpf);
        $this->telefone = (string) Documento::formatarTelefone($this->telefone);
        $this->vinculos = array_values(array_filter($this->vinculos, fn ($v) => $v['unidade_id'] !== ''));

        $dados = $this->validate([
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::enum(TipoPessoa::class)],
            'cpf' => ['nullable', new Cpf, Rule::unique('pessoas', 'cpf')->where('condominio_id', $condominioId)->ignore($this->pessoaId)],
            'rg' => ['nullable', 'string', 'max:20'],
            'data_nascimento' => ['nullable', 'date', 'before:today'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'ativo' => ['boolean'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'foto' => ['nullable', 'file', 'max:15360'],
            'biometria_autorizada' => ['boolean'],
            'vinculos' => ['array'],
            'vinculos.*.unidade_id' => ['required', 'distinct', Rule::exists('unidades', 'id')->where('condominio_id', $condominioId)],
            'vinculos.*.vinculo' => ['required', Rule::enum(VinculoUnidade::class)],
            'vinculos.*.responsavel' => ['boolean'],
        ], attributes: [
            'cpf' => 'CPF', 'rg' => 'RG', 'data_nascimento' => 'data de nascimento', 'foto' => 'foto',
            'vinculos.*.unidade_id' => 'unidade', 'vinculos.*.vinculo' => 'vínculo',
        ], messages: [
            'vinculos.*.unidade_id.distinct' => __('Esta unidade foi adicionada mais de uma vez.'),
        ]);

        $vinculos = collect($dados['vinculos'])->mapWithKeys(fn ($v) => [
            (int) $v['unidade_id'] => ['vinculo' => $v['vinculo'], 'responsavel' => (bool) $v['responsavel']],
        ])->all();
        unset($dados['vinculos'], $dados['foto'], $dados['biometria_autorizada']);
        $dados = collect($dados)->map(fn ($v) => $v === '' ? null : $v)->all();

        // Converte a foto antes de gravar qualquer coisa, para avisar se o arquivo não serve.
        $jpeg = null;
        if ($this->foto) {
            try {
                $jpeg = FotoDePessoa::normalizar((string) $this->foto->get());
            } catch (\RuntimeException $e) {
                $this->addError('foto', $e->getMessage());

                return;
            }
        }

        if ($this->pessoaId) {
            $pessoa = Pessoa::query()->doCondominio($condominioId)->findOrFail($this->pessoaId);
            $pessoa->update($dados);
        } else {
            $pessoa = $this->condominio()->pessoas()->create($dados);
        }

        $pessoa->unidades()->sync($vinculos);

        $fotos = app(FotoDePessoa::class);
        if ($jpeg !== null) {
            $fotos->salvar($pessoa, $jpeg);
        } elseif ($this->removerFoto) {
            $fotos->remover($pessoa);
        }

        // Consentimento LGPD para uso da foto na leitora facial (quem e quando).
        if ($this->biometria_autorizada && ! $pessoa->biometria_autorizada_em) {
            $pessoa->forceFill(['biometria_autorizada_em' => now(), 'biometria_autorizada_por' => auth()->id()])->save();
            activity('cadastro')->causedBy(auth()->user())->performedOn($pessoa)->log('Registrou o consentimento de biometria facial');
        } elseif (! $this->biometria_autorizada && $pessoa->biometria_autorizada_em) {
            $pessoa->forceFill(['biometria_autorizada_em' => null, 'biometria_autorizada_por' => null])->save();
            activity('cadastro')->causedBy(auth()->user())->performedOn($pessoa)->log('Retirou o consentimento de biometria facial');
        }

        $this->avisarLeitoras(app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa->refresh()));
        $this->redirectRoute('pessoas.index', navigate: true);
    }

    /**
     * @param  list<array{leitora: string, sucesso: bool, mensagem: string}>  $resultados
     */
    private function avisarLeitoras(array $resultados): void
    {
        $erros = array_filter($resultados, fn ($r) => ! $r['sucesso']);

        if ($erros === []) {
            Flux::toast(variant: 'success', text: __('Pessoa salva.').($resultados !== [] ? ' '.__('Leitoras faciais atualizadas.') : ''));

            return;
        }

        $texto = collect($erros)->map(fn ($r) => "{$r['leitora']}: {$r['mensagem']}")->join(' | ');
        Flux::toast(variant: 'warning', duration: 15000, heading: __('Pessoa salva, mas a leitora facial não foi atualizada'), text: $texto);
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-cabecalho-pagina :titulo="$pessoaId ? __('Editar pessoa') : __('Nova pessoa')" :subtitulo="$pessoaId ? $nome : null">
        <x-slot:acoes>
            <flux:button variant="ghost" icon="arrow-left" :href="route('pessoas.index')" wire:navigate>{{ __('Voltar') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <form wire:submit="salvar" class="space-y-8">
        <flux:fieldset>
            <flux:legend>{{ __('Dados pessoais') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><flux:input wire:model="nome" :label="__('Nome completo')" required /></div>
                <flux:select wire:model="tipo" :label="__('Tipo')">
                    @foreach (TipoPessoa::cases() as $t)
                        <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="data_nascimento" type="date" :label="__('Data de nascimento')" />
                <flux:input wire:model="cpf" :label="__('CPF')" mask="999.999.999-99" placeholder="000.000.000-00" />
                <flux:input wire:model="rg" :label="__('RG')" />
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Contato') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="telefone" :label="__('Celular / telefone')" mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-99999'" placeholder="(00) 00000-0000" />
                <flux:input wire:model="email" type="email" :label="__('E-mail')" />
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Foto e reconhecimento facial') }}</flux:legend>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="flex h-40 w-32 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                    @if ($foto && ! $errors->has('foto') && method_exists($foto, 'isPreviewable') && $foto->isPreviewable())
                        <img src="{{ $foto->temporaryUrl() }}" alt="" class="h-full w-full object-cover">
                    @elseif ($temFoto && ! $removerFoto)
                        <img src="{{ route('pessoas.foto', ['pessoa' => $pessoaUuid, 'v' => $versaoFoto]) }}" alt="" class="h-full w-full object-cover">
                    @else
                        <flux:icon.user class="size-12 text-zinc-300" />
                    @endif
                </div>

                <div class="flex-1 space-y-3">
                    {{-- A foto é reduzida no próprio celular antes do envio (fotos de câmera têm vários MB). --}}
                    <div class="space-y-2" x-data="{
                        enviando: false,
                        async enviar(evento) {
                            const arquivo = evento.target.files[0];
                            evento.target.value = '';
                            if (! arquivo) return;
                            this.enviando = true;
                            let envio = arquivo;
                            try {
                                {{-- Reduz para no máximo 1024 px; o navegador aplica a orientação da câmera. --}}
                                const imagem = await createImageBitmap(arquivo, { imageOrientation: 'from-image' });
                                const escala = Math.min(1, 1024 / Math.max(imagem.width, imagem.height));
                                const tela = document.createElement('canvas');
                                tela.width = Math.round(imagem.width * escala);
                                tela.height = Math.round(imagem.height * escala);
                                tela.getContext('2d').drawImage(imagem, 0, 0, tela.width, tela.height);
                                const blob = await new Promise((ok) => tela.toBlob(ok, 'image/jpeg', 0.9));
                                if (blob) envio = new File([blob], 'foto.jpg', { type: 'image/jpeg' });
                            } catch (e) { /* navegador sem suporte: envia o original */ }
                            $wire.upload('foto', envio, () => { this.enviando = false }, () => { this.enviando = false });
                        },
                    }">
                        <flux:label>{{ __('Foto do rosto') }}</flux:label>
                        <flux:description>{{ __('De frente, sem óculos escuros nem boné, rosto inteiro e bem iluminado, fundo liso.') }}</flux:description>
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" icon="camera" x-on:click="$refs.camera.click()">{{ __('Tirar foto') }}</flux:button>
                            <flux:button size="sm" icon="photo" x-on:click="$refs.arquivo.click()">{{ __('Escolher arquivo') }}</flux:button>
                        </div>
                        <input x-ref="camera" type="file" accept="image/*" capture="user" class="hidden" x-on:change="enviar($event)">
                        <input x-ref="arquivo" type="file" accept="image/*" class="hidden" x-on:change="enviar($event)">
                        <div x-show="enviando" x-cloak class="text-sm text-zinc-500">{{ __('Enviando a foto…') }}</div>
                        <flux:error name="foto" />
                    </div>
                    @if ($temFoto && ! $foto)
                        <flux:checkbox wire:model.live="removerFoto" :label="__('Remover a foto atual')" />
                    @endif

                    <flux:checkbox wire:model="biometria_autorizada" :label="__('A pessoa (ou o responsável legal) autorizou o uso da foto para reconhecimento facial')"
                        :description="__('Exigido pela LGPD (dado biométrico). Sem esta autorização, a foto não é enviada às leitoras. Fica registrado quem marcou e quando.')" />

                    @if ($this->leitoras->isNotEmpty() && $pessoaId)
                        <div class="space-y-1 text-sm">
                            @foreach ($this->leitoras as $leitora)
                                @php($situacao = $this->situacaoNasLeitoras->get($leitora->id))
                                <div class="flex items-center gap-2">
                                    @if ($situacao && ! $situacao->pivot->erro)
                                        <flux:icon.check-circle variant="mini" class="text-green-600" />
                                        <span>{{ $leitora->nome }}: {{ __('enviada em :d', ['d' => \Illuminate\Support\Carbon::parse($situacao->pivot->sincronizado_em)->format('d/m/Y H:i')]) }}</span>
                                    @elseif ($situacao)
                                        <flux:icon.exclamation-triangle variant="mini" class="text-amber-500" />
                                        <span>{{ $leitora->nome }}: {{ $situacao->pivot->erro }}</span>
                                    @else
                                        <flux:icon.minus-circle variant="mini" class="text-zinc-400" />
                                        <span>{{ $leitora->nome }}: {{ __('não está na leitora') }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Unidades') }}</flux:legend>
            <flux:description class="mb-4">{{ __('Em quais unidades esta pessoa mora ou trabalha.') }}</flux:description>

            @if ($this->unidades->isEmpty())
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.text>
                        {{ __('Nenhuma unidade cadastrada ainda.') }}
                        <flux:callout.link :href="route('unidades.index')" wire:navigate>{{ __('Cadastrar unidades') }}</flux:callout.link>
                    </flux:callout.text>
                </flux:callout>
            @else
                <div class="space-y-3">
                    @foreach ($vinculos as $i => $vinculo)
                        <div class="grid gap-3 rounded-lg border border-zinc-200 p-3 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end dark:border-zinc-700" wire:key="vinculo-{{ $i }}">
                            <flux:select wire:model="vinculos.{{ $i }}.unidade_id" :label="__('Unidade')">
                                <flux:select.option value="">{{ __('Selecione…') }}</flux:select.option>
                                @foreach ($this->unidades as $u)
                                    <flux:select.option :value="$u->id">{{ $u->identificacao }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:select wire:model="vinculos.{{ $i }}.vinculo" :label="__('Vínculo')">
                                @foreach (VinculoUnidade::cases() as $v)
                                    <flux:select.option :value="$v->value">{{ $v->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <div class="pb-2"><flux:checkbox wire:model="vinculos.{{ $i }}.responsavel" :label="__('Responsável')" /></div>
                            <flux:button variant="ghost" icon="x-mark" wire:click="removerVinculo({{ $i }})" :aria-label="__('Remover')" />
                        </div>
                    @endforeach

                    <flux:button size="sm" icon="plus" wire:click="adicionarVinculo">{{ __('Adicionar unidade') }}</flux:button>
                </div>
            @endif
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Outros') }}</flux:legend>
            <div class="space-y-4">
                <flux:textarea wire:model="observacoes" :label="__('Observações')" rows="3" />
                <flux:switch wire:model="ativo" :label="__('Cadastro ativo')" :description="__('Pessoas inativas não aparecem para abertura nem para autorizações.')" />
                @if ($this->usuario)
                    <flux:callout icon="key" variant="secondary">
                        <flux:callout.text>{{ __('Esta pessoa tem acesso ao sistema com o login :email.', ['email' => $this->usuario->email]) }}</flux:callout.text>
                    </flux:callout>
                @endif
            </div>
        </flux:fieldset>

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="route('pessoas.index')" wire:navigate>{{ __('Cancelar') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="check">{{ __('Salvar') }}</flux:button>
        </div>
    </form>
</section>

