<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Enums\StatusMonitoramento;
use App\Models\Configuracao;
use App\Models\Dispositivo;
use App\Models\LigacaoRede;
use App\Models\User;
use App\Monitoramento\Monitor;
use App\Monitoramento\Verificador;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

class MonitoramentoTest extends CadastrosTestCase
{
    private const RESPOSTA_OK = "Disparando 192.168.0.1 com 32 bytes de dados:\r\nResposta de 192.168.0.1: bytes=32 tempo=3ms TTL=64\r\n";

    private const INACESSIVEL = "Disparando 192.168.0.9 com 32 bytes de dados:\r\nResposta de 192.168.0.5: Host de destino inacessível.\r\n";

    private function roteador(array $atributos = []): Dispositivo
    {
        return Dispositivo::factory()->for($this->condominio)->create([
            'nome' => 'Roteador principal', 'tipo' => 'roteador', 'driver' => null,
            'host' => '192.168.0.1', 'monitorar' => true, 'metodo_monitoramento' => 'ping', ...$atributos,
        ]);
    }

    private function configurarTelegram(): void
    {
        Configuracao::definir('telegram.token', '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        Configuracao::definir('telegram.chat_id', '-100123');
    }

    public function test_entende_o_ping_do_windows_em_portugues(): void
    {
        $v = app(Verificador::class);

        $ok = $v->interpretarPing(true, self::RESPOSTA_OK);
        $this->assertTrue($ok->respondeu);
        $this->assertSame(3, $ok->latenciaMs);

        // O Windows devolve "sucesso" para "inacessível": precisa olhar o TTL.
        $falha = $v->interpretarPing(true, mb_convert_encoding(self::INACESSIVEL, 'CP850', 'UTF-8'));
        $this->assertFalse($falha->respondeu);
        $this->assertStringContainsString('inacessível', $falha->erro);

        $this->assertSame(0, $v->interpretarPing(true, 'Reply from 10.0.0.1: bytes=32 time<1ms TTL=128')->latenciaMs);
        $this->assertSame(12, $v->interpretarPing(true, '64 bytes from 10.0.0.1: icmp_seq=1 ttl=64 time=12.3 ms')->latenciaMs);
    }

    public function test_queda_so_apos_falhas_seguidas_avisa_no_telegram_e_registra_a_volta(): void
    {
        $this->configurarTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $roteador = $this->roteador();
        $this->condominio->update(['telegram_chat_id' => '-100999']);

        Process::fake(['*' => Process::result(self::RESPOSTA_OK)]);
        app(Monitor::class)->executar();
        $this->assertSame(StatusMonitoramento::Online, $roteador->fresh()->status);
        $this->assertSame(3, $roteador->fresh()->latencia_ms);

        Process::fake(['*' => Process::result('Esgotado o tempo limite do pedido.', exitCode: 1)]);
        app(Monitor::class)->executar();
        $this->assertSame(StatusMonitoramento::Online, $roteador->fresh()->status, 'uma falha só não derruba');
        Http::assertNothingSent();

        app(Monitor::class)->executar();
        $this->assertSame(StatusMonitoramento::Offline, $roteador->fresh()->status);
        $this->assertSame(1, $roteador->quedas()->whereNull('fim')->count());
        Http::assertSentCount(2); // chat geral + chat do condomínio
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Roteador principal fora do ar') && $r['chat_id'] === '-100999');

        app(Monitor::class)->executar();
        Http::assertSentCount(2); // continua fora: não repete o alerta

        Process::fake(['*' => Process::result(self::RESPOSTA_OK)]);
        app(Monitor::class)->executar();
        $this->assertSame(StatusMonitoramento::Online, $roteador->fresh()->status);
        $this->assertNotNull($roteador->quedas()->first()->fim);
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'voltou'));
    }

    public function test_resposta_demorada_fica_como_lento(): void
    {
        $roteador = $this->roteador();
        Process::fake(['*' => Process::result('Resposta de 192.168.0.1: bytes=32 tempo=850ms TTL=64')]);

        app(Monitor::class)->executar();

        $this->assertSame(StatusMonitoramento::Lento, $roteador->fresh()->status);
    }

    public function test_monitora_por_porta_tcp(): void
    {
        $servidor = stream_socket_server('tcp://127.0.0.1:0');
        $porta = (int) substr(strrchr(stream_socket_get_name($servidor, false), ':'), 1);
        $aberta = $this->roteador(['metodo_monitoramento' => 'tcp', 'host' => '127.0.0.1', 'porta_monitoramento' => $porta]);
        fclose(stream_socket_server('tcp://127.0.0.1:0'));

        app(Monitor::class)->executar();

        $this->assertSame(StatusMonitoramento::Online, $aberta->fresh()->status);
        fclose($servidor);
    }

    public function test_ignora_equipamento_nao_monitorado_e_host_invalido_nao_vira_comando(): void
    {
        Process::fake();
        $this->roteador(['monitorar' => false]);
        $this->roteador(['host' => '-n 1 & calc']);

        app(Monitor::class)->executar();

        Process::assertNothingRan();
    }

    public function test_falha_no_telegram_nao_quebra_o_monitoramento(): void
    {
        $this->configurarTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);
        Process::fake(['*' => Process::result('', exitCode: 1)]);
        $roteador = $this->roteador();

        app(Monitor::class)->executar();
        app(Monitor::class)->executar();

        $this->assertSame(StatusMonitoramento::Offline, $roteador->fresh()->status);
    }

    public function test_mapa_salva_posicao_e_ligacoes_somente_no_proprio_condominio(): void
    {
        $a = $this->roteador();
        $b = $this->roteador(['nome' => 'Switch', 'tipo' => 'switch']);
        $alheio = Dispositivo::factory()->for($this->outro)->create();
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        $mapa = Livewire::test('pages::rede.mapa')
            ->call('mover', $a->id, 300, 150)
            ->call('ligar', $a->id, $b->id, 'wireless', 'SSID Portaria');

        $this->assertSame([300, 150], [$a->fresh()->mapa_x, $a->fresh()->mapa_y]);
        $this->assertDatabaseHas('ligacoes_rede', ['origem_id' => $a->id, 'destino_id' => $b->id, 'tipo' => 'wireless', 'descricao' => 'SSID Portaria']);

        // ligar de novo (na direção contrária) atualiza a mesma linha
        $mapa->call('ligar', $b->id, $a->id, 'cabo', null);
        $this->assertSame(1, LigacaoRede::count());
        $this->assertSame('cabo', LigacaoRede::first()->tipo->value);

        $mapa->call('ligar', $a->id, $alheio->id, 'cabo', null)->assertStatus(422);

        $dados = Livewire::test('pages::rede.mapa')->instance()->dados();
        $this->assertCount(2, $dados['nos']);
        $this->assertCount(1, $dados['ligacoes']);
    }

    public function test_sindico_ve_o_mapa_mas_nao_altera(): void
    {
        $a = $this->roteador();
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        $this->get(route('rede.mapa'))->assertOk();
        Livewire::test('pages::rede.mapa')->call('mover', $a->id, 10, 10)->assertForbidden();
    }

    public function test_morador_nao_ve_o_mapa(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Morador))->get(route('rede.mapa'))->assertForbidden();
    }

    public function test_mapa_geral_resume_cada_condominio(): void
    {
        $this->roteador(['status' => 'online']);
        $this->roteador(['nome' => 'AP', 'status' => 'offline']);
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        $dados = Livewire::test('pages::rede.geral')->instance()->dados();
        $no = collect($dados['nos'])->firstWhere('id', $this->condominio->id);

        $this->assertSame('parcial', $no['status']);
        $this->assertSame(['AP'], $no['offline']->all());
        $this->assertSame('central', $dados['nos'][0]['id']);
    }

    public function test_mapa_geral_e_da_equipe_da_central(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Sindico))->get(route('rede.geral'))->assertForbidden();
        $this->entrarComo($this->usuarioCom(Perfil::Operador))->get(route('rede.geral'))->assertOk();
    }

    public function test_configuracao_do_telegram_guarda_o_token_criptografado_e_envia_teste(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        Livewire::test('pages::sistema.alertas')
            ->set('token', '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ')
            ->set('chat_id', '-100123')
            ->call('salvar')
            ->assertHasNoErrors()
            ->call('testar');

        $this->assertSame('123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ', Configuracao::obter('telegram.token'));
        $this->assertStringNotContainsString('ABCDEFGHIJ', (string) \DB::table('configuracoes')->where('chave', 'telegram.token')->value('valor'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/bot123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ/sendMessage') && $r['chat_id'] === '-100123');
    }

    public function test_cadastra_equipamento_de_rede_sem_driver(): void
    {
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        Livewire::test('pages::dispositivos.form')
            ->set('tipo', 'switch')
            ->set('nome', 'Switch 1º andar')
            ->set('host', '192.168.0.2')
            ->set('mac', 'aa-bb-cc-dd-ee-ff')
            ->set('metodo_monitoramento', 'ping')
            ->call('salvar')
            ->assertHasNoErrors();

        $d = Dispositivo::where('nome', 'Switch 1º andar')->firstOrFail();
        $this->assertNull($d->driver);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $d->mac);
        $this->assertTrue($d->monitorar);
    }
}
