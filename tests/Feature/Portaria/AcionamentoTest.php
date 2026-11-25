<?php

namespace Tests\Feature\Portaria;

use App\Acoes\AcionarPontoDeAcesso;
use App\Enums\OrigemEvento;
use App\Enums\Perfil;
use App\Enums\ResultadoEvento;
use App\Models\Dispositivo;
use App\Models\EventoAcesso;
use App\Models\PontoAcesso;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use LogicException;

class AcionamentoTest extends CadastrosTestCase
{
    private function ponto(array $atributos = [], array $dispositivo = []): PontoAcesso
    {
        $d = Dispositivo::factory()->for($this->condominio)->create($dispositivo);

        return PontoAcesso::factory()->for($this->condominio)->create(['dispositivo_id' => $d->id, ...$atributos]);
    }

    public function test_operador_abre_e_fica_registrado(): void
    {
        $ponto = $this->ponto(['nome' => 'Portão Social']);
        $operador = $this->usuarioCom(Perfil::Operador);

        $evento = app(AcionarPontoDeAcesso::class)($ponto, $operador, OrigemEvento::Central, '10.0.0.1');

        $this->assertSame(ResultadoEvento::Sucesso, $evento->resultado);
        $this->assertDatabaseHas('eventos_acesso', [
            'ponto_acesso_id' => $ponto->id, 'user_id' => $operador->id, 'ponto_nome' => 'Portão Social',
            'origem' => 'central', 'resultado' => 'sucesso', 'ip' => '10.0.0.1',
        ]);
        $this->assertTrue($ponto->dispositivo->fresh()->online);
    }

    public function test_morador_so_abre_ponto_liberado_para_moradores(): void
    {
        $morador = $this->usuarioCom(Perfil::Morador);
        $restrito = $this->ponto(['liberado_moradores' => false]);
        $liberado = $this->ponto(['liberado_moradores' => true]);

        $this->assertSame(ResultadoEvento::Negado, app(AcionarPontoDeAcesso::class)($restrito, $morador, OrigemEvento::Web)->resultado);
        $this->assertSame(ResultadoEvento::Sucesso, app(AcionarPontoDeAcesso::class)($liberado, $morador, OrigemEvento::Web)->resultado);
    }

    public function test_usuario_de_outro_condominio_e_negado(): void
    {
        $ponto = $this->ponto(['liberado_moradores' => true]);
        $alheio = $this->usuarioCom(Perfil::Sindico, $this->outro);

        $this->assertSame(ResultadoEvento::Negado, app(AcionarPontoDeAcesso::class)($ponto, $alheio, OrigemEvento::Web)->resultado);
    }

    public function test_falha_do_equipamento_e_registrada(): void
    {
        $ponto = $this->ponto([], ['config' => ['falhar' => true]]);

        $evento = app(AcionarPontoDeAcesso::class)($ponto, $this->usuarioCom(Perfil::Porteiro), OrigemEvento::Web);

        $this->assertSame(ResultadoEvento::Falha, $evento->resultado);
        $this->assertFalse($ponto->dispositivo->fresh()->online);
        $this->assertNotNull($ponto->dispositivo->fresh()->ultimo_erro);
    }

    public function test_comando_repetido_enquanto_outro_esta_em_andamento_e_ignorado(): void
    {
        $ponto = $this->ponto();
        $trava = Cache::lock('acionamento:ponto:'.$ponto->id, 10);
        $trava->get();

        $evento = app(AcionarPontoDeAcesso::class)($ponto, $this->usuarioCom(Perfil::Operador), OrigemEvento::Web);

        $this->assertSame(ResultadoEvento::Ocupado, $evento->resultado);
        $trava->release();
    }

    public function test_ponto_inativo_nao_abre(): void
    {
        $ponto = $this->ponto(['ativo' => false]);

        $this->assertSame(ResultadoEvento::Falha, app(AcionarPontoDeAcesso::class)($ponto, $this->usuarioCom(Perfil::Operador), OrigemEvento::Web)->resultado);
    }

    public function test_historico_nao_pode_ser_alterado(): void
    {
        $evento = app(AcionarPontoDeAcesso::class)($this->ponto(), $this->usuarioCom(Perfil::Operador), OrigemEvento::Web);

        $this->expectException(LogicException::class);
        $evento->update(['resultado' => 'falha']);
    }

    public function test_tela_abrir_mostra_so_pontos_permitidos_e_aciona(): void
    {
        $liberado = $this->ponto(['nome' => 'Pedestres', 'liberado_moradores' => true]);
        $this->ponto(['nome' => 'Garagem Restrita', 'liberado_moradores' => false]);
        $this->entrarComo($morador = $this->usuarioCom(Perfil::Morador));

        Livewire::test('pages::acessos.abrir')
            ->assertSee('Pedestres')
            ->assertDontSee('Garagem Restrita')
            ->call('abrir', $liberado->uuid)
            ->assertSee('Aberto às');

        $this->assertSame(1, EventoAcesso::where('user_id', $morador->id)->where('resultado', 'sucesso')->count());
    }

    public function test_morador_ve_somente_o_proprio_historico(): void
    {
        $ponto = $this->ponto(['liberado_moradores' => true]);
        $eu = $this->usuarioCom(Perfil::Morador);
        $outro = $this->usuarioCom(Perfil::Morador);
        $outro->forceFill(['name' => 'Vizinho Curioso'])->save();
        app(AcionarPontoDeAcesso::class)($ponto, $eu, OrigemEvento::Web);
        app(AcionarPontoDeAcesso::class)($ponto, $outro, OrigemEvento::Web);

        $this->entrarComo($eu)->get(route('acessos.historico'))
            ->assertOk()->assertSee($eu->name)->assertDontSee('Vizinho Curioso');
    }

    public function test_somente_super_admin_configura_dispositivos(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));
        $this->get(route('dispositivos.index'))->assertForbidden();
        $this->get(route('pontos.index'))->assertForbidden();

        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());
        $this->get(route('dispositivos.index'))->assertOk();
    }

    public function test_cadastra_shelly_com_senha_guardada_criptografada_e_mantida_ao_editar(): void
    {
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        Livewire::test('pages::dispositivos.form')
            ->set('nome', 'Shelly Portaria')
            ->set('driver', 'shelly')
            ->set('host', '192.168.1.20')
            ->set('config.senha', 'segredo123')
            ->call('salvar')
            ->assertHasNoErrors();

        $dispositivo = Dispositivo::where('nome', 'Shelly Portaria')->firstOrFail();
        $this->assertSame('segredo123', $dispositivo->config['senha']);
        $this->assertStringNotContainsString('segredo123', (string) $dispositivo->getRawOriginal('config'));

        Livewire::test('pages::dispositivos.form', ['dispositivo' => $dispositivo])
            ->assertSet('config.senha', '')
            ->set('nome', 'Shelly Portaria 2')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertSame('segredo123', $dispositivo->fresh()->config['senha']);
    }

    public function test_tuya_exige_chave_de_16_caracteres(): void
    {
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        Livewire::test('pages::dispositivos.form')
            ->set('nome', 'Bocal cozinha')
            ->set('driver', 'tuya_local')
            ->set('host', '192.168.1.30')
            ->set('config.device_id', 'eb1234567890abcdefgh')
            ->set('config.local_key', 'curta')
            ->call('salvar')
            ->assertHasErrors('config.local_key');
    }

    public function test_driver_shelly_gen2_envia_pulso_com_temporizador_do_aparelho(): void
    {
        Http::fake(['*' => Http::response(['was_on' => false])]);
        $dispositivo = Dispositivo::factory()->for($this->condominio)->shelly('192.168.1.20')->create();

        $resultado = $dispositivo->driverDeRele()->pulso($dispositivo, 1, 1500);

        $this->assertTrue($resultado->sucesso);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://192.168.1.20/rpc/Switch.Set')
            && $r['id'] == 0 && $r['on'] === 'true' && (float) $r['toggle_after'] === 1.5);
    }

    public function test_driver_shelly_gen1_detectado_automaticamente(): void
    {
        Http::fake([
            '*/shelly' => Http::response(['type' => 'SHSW-1', 'fw' => '1.0']),
            '*/relay/0*' => Http::response(['ison' => true]),
        ]);
        $dispositivo = Dispositivo::factory()->for($this->condominio)->create(['driver' => 'shelly', 'host' => '10.1.1.1', 'config' => ['geracao' => 'auto']]);

        $resultado = $dispositivo->driverDeRele()->pulso($dispositivo, 1, 800);

        $this->assertTrue($resultado->sucesso);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/relay/0') && $r['turn'] === 'on' && (int) $r['timer'] === 1);
    }

    public function test_driver_http_generico_substitui_variaveis_e_detecta_erro(): void
    {
        Http::fake([
            '*/on*' => Http::response('OK'),
            '*/off*' => Http::response('erro', 500),
        ]);
        $dispositivo = Dispositivo::factory()->for($this->condominio)->create([
            'driver' => 'http', 'host' => '10.0.0.9', 'porta' => 8080,
            'config' => ['url_ligar' => '/on?r={canal0}', 'url_desligar' => '/off?r={canal0}', 'metodo' => 'GET'],
        ]);
        $driver = $dispositivo->driverDeRele();

        $this->assertTrue($driver->ligar($dispositivo, 2)->sucesso);
        Http::assertSent(fn ($r) => $r->url() === 'http://10.0.0.9:8080/on?r=1');

        $falha = $driver->desligar($dispositivo, 2);
        $this->assertFalse($falha->sucesso);
        $this->assertStringContainsString('500', $falha->mensagem);
    }

    public function test_pulso_generico_avisa_quando_nao_consegue_desligar(): void
    {
        Http::fake([
            '*/on*' => Http::response('OK'),
            '*/off*' => Http::response('erro', 500),
        ]);
        $dispositivo = Dispositivo::factory()->for($this->condominio)->create([
            'driver' => 'http', 'host' => '10.0.0.9',
            'config' => ['url_ligar' => '/on', 'url_desligar' => '/off'],
        ]);

        $resultado = $dispositivo->driverDeRele()->pulso($dispositivo, 1, 100);

        $this->assertFalse($resultado->sucesso);
        $this->assertStringContainsString('NÃO conseguiu desligar', $resultado->mensagem);
    }
}
