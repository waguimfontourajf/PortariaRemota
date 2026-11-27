<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

class AtualizacaoDoSistemaTest extends CadastrosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(storage_path('app/atualizacao'));
        File::delete(storage_path('logs/atualizacao.log'));
        config(['portaria.atualizacao.habilitada' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/atualizacao'));
        File::delete(storage_path('logs/atualizacao.log'));
        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $this->entrarComo($admin)->withSession(['auth.password_confirmed_at' => time()]);

        return $admin;
    }

    public function test_somente_super_admin(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Sindico))->withSession(['auth.password_confirmed_at' => time()]);

        $this->get(route('sistema.atualizacao'))->assertForbidden();
        $this->get(route('sistema.atualizacao.estado'))->assertForbidden();
    }

    public function test_pede_a_senha_de_novo_antes_de_abrir(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $this->entrarComo($admin);

        $this->get(route('sistema.atualizacao'))->assertRedirect(route('password.confirm'));
    }

    public function test_orienta_quando_a_tarefa_nao_foi_instalada(): void
    {
        Process::fake(['schtasks /query*' => Process::result(exitCode: 1)]);
        $this->admin();

        $this->get(route('sistema.atualizacao'))->assertOk()->assertSee('instalar-atualizacao-pelo-painel.bat');
    }

    public function test_botao_dispara_a_tarefa_agendada_do_windows(): void
    {
        Process::fake();
        $this->admin();

        Livewire::test('pages::sistema.atualizacao')
            ->assertSee('Atualizar agora')
            ->call('atualizar')
            ->assertDispatched('atualizacao-iniciada');

        Process::assertRan(fn ($p) => $p->command === ['schtasks', '/run', '/tn', 'PortariaRemota-Atualizar']);
        $this->assertSame('executando', trim(File::get(storage_path('app/atualizacao/estado.txt'))));
        $this->assertDatabaseHas('activity_log', ['log_name' => 'sistema']);
    }

    public function test_nao_dispara_duas_vezes(): void
    {
        Process::fake();
        $this->admin();
        File::ensureDirectoryExists(storage_path('app/atualizacao'));
        File::put(storage_path('app/atualizacao/estado.txt'), 'executando');

        Livewire::test('pages::sistema.atualizacao')->call('atualizar')->assertNotDispatched('atualizacao-iniciada');

        Process::assertDidntRun(fn ($p) => in_array('/run', (array) $p->command, true));
    }

    public function test_falha_do_windows_e_informada(): void
    {
        Process::fake([
            'schtasks /query*' => Process::result(),
            'schtasks /run*' => Process::result(errorOutput: 'ERRO: tarefa nao encontrada', exitCode: 1),
        ]);
        $this->admin();

        Livewire::test('pages::sistema.atualizacao')->call('atualizar')->assertNotDispatched('atualizacao-iniciada');

        $this->assertSame('erro', trim(File::get(storage_path('app/atualizacao/estado.txt'))));
    }

    public function test_estado_responde_mesmo_em_manutencao(): void
    {
        $this->admin();
        File::ensureDirectoryExists(storage_path('app/atualizacao'));
        File::put(storage_path('app/atualizacao/estado.txt'), "executando\r\n");
        File::put(storage_path('app/atualizacao/versao.txt'), "abc1234|2026-11-27T18:00:00-03:00|feat: algo\r\n");
        File::put(storage_path('logs/atualizacao.log'), "[1/7] Verificando...\r\n[2/7] Baixando...\r\n");

        $this->artisan('down');
        try {
            $this->getJson(route('sistema.atualizacao.estado'))
                ->assertOk()
                ->assertJson(['estado' => 'executando', 'versao' => 'abc1234 — feat: algo'])
                ->assertJsonPath('registro', "[1/7] Verificando...\n[2/7] Baixando...");

            $this->get(route('dashboard'))->assertStatus(503);
        } finally {
            $this->artisan('up');
        }
    }

    public function test_indisponivel_fora_do_windows(): void
    {
        config(['portaria.atualizacao.habilitada' => false]);
        $this->admin();

        $this->get(route('sistema.atualizacao'))->assertOk()->assertSee('Disponível apenas no servidor Windows');
    }
}
