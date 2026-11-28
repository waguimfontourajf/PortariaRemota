<?php

namespace Tests\Feature\Portaria;

use App\Central\Chamadas;
use App\Central\Interfones;
use App\Central\Pabx;
use App\Enums\OrigemChamada;
use App\Enums\OrigemEvento;
use App\Enums\Perfil;
use App\Enums\StatusChamada;
use App\Models\Chamada;
use App\Models\Configuracao;
use App\Models\Dispositivo;
use App\Models\EventoAcesso;
use App\Models\PontoAcesso;
use App\Models\User;
use Livewire\Livewire;

class CentralTest extends CadastrosTestCase
{
    private Dispositivo $interfone;

    private PontoAcesso $portao;

    protected function setUp(): void
    {
        parent::setUp();
        $rele = Dispositivo::factory()->for($this->condominio)->create();
        $this->interfone = Dispositivo::factory()->for($this->condominio)->create([
            'nome' => 'Interfone social', 'tipo' => 'interfone', 'driver' => null, 'ramal' => '2001', 'config' => ['senha_sip' => 'segredo-sip-123'],
        ]);
        $this->portao = PontoAcesso::factory()->for($this->condominio)->create([
            'nome' => 'Portão social', 'dispositivo_id' => $rele->id, 'interfone_id' => $this->interfone->id,
        ]);
        Pabx::gerarToken();
    }

    private function evento(array $dados)
    {
        return $this->post(route('pabx.evento'), $dados + ['token' => Pabx::token()]);
    }

    public function test_pabx_sem_token_e_recusado(): void
    {
        $this->post(route('pabx.evento'), ['evento' => 'inicio', 'ramal' => '2001', 'id' => '1.1', 'token' => 'errado'])->assertForbidden();
        $this->assertSame(0, Chamada::count());
    }

    public function test_interfone_liga_e_toca_so_para_quem_atende_aquele_condominio(): void
    {
        $operador = $this->usuarioCom(Perfil::Operador);
        $porteiroDeOutro = $this->usuarioCom(Perfil::Porteiro, $this->outro);
        $sindico = $this->usuarioCom(Perfil::Sindico);

        $resposta = $this->evento(['evento' => 'inicio', 'ramal' => '2001', 'id' => '1700000000.10'])->assertOk()->getContent();

        $chamada = Chamada::firstOrFail();
        [$uuid, $destinos] = explode('|', $resposta);
        $this->assertSame($chamada->uuid, $uuid);
        $this->assertSame('op'.$operador->id, $operador->fresh()->ramal_sip);
        $this->assertStringContainsString('PJSIP/op'.$operador->id, $destinos);
        $this->assertStringNotContainsString('op'.$porteiroDeOutro->id, $destinos);
        $this->assertStringNotContainsString('op'.$sindico->id, $destinos);
        $this->assertSame($this->portao->id, $chamada->ponto_acesso_id);
        $this->assertSame(StatusChamada::Tocando, $chamada->status);

        // O mesmo aviso repetido não cria outra chamada.
        $this->evento(['evento' => 'inicio', 'ramal' => '2001', 'id' => '1700000000.10']);
        $this->assertSame(1, Chamada::count());
    }

    public function test_ramal_desconhecido_nao_toca(): void
    {
        $this->evento(['evento' => 'inicio', 'ramal' => '9999', 'id' => '1.2'])->assertOk()->assertSeeText('0|');
        $this->assertSame(0, Chamada::count());
    }

    public function test_fim_sem_atender_vira_nao_atendida_e_atendida_vira_encerrada(): void
    {
        $this->evento(['evento' => 'inicio', 'ramal' => '2001', 'id' => 'a']);
        $this->evento(['evento' => 'fim', 'id' => 'a', 'status' => 'NOANSWER'])->assertOk();
        $this->assertSame(StatusChamada::Perdida, Chamada::where('id_externo', 'a')->first()->status);

        $this->evento(['evento' => 'inicio', 'ramal' => '2001', 'id' => 'b']);
        $chamada = Chamada::where('id_externo', 'b')->first();
        app(Chamadas::class)->atender($chamada, $this->usuarioCom(Perfil::Operador));
        $this->evento(['evento' => 'fim', 'id' => 'b', 'status' => 'ANSWER']);
        $this->assertSame(StatusChamada::Encerrada, $chamada->fresh()->status);
        $this->assertNotNull($chamada->fresh()->encerrada_em);
    }

    public function test_so_um_operador_fica_com_a_chamada(): void
    {
        $chamada = app(Chamadas::class)->iniciar($this->interfone, OrigemChamada::Teste);
        $ana = $this->usuarioCom(Perfil::Operador);
        $bia = $this->usuarioCom(Perfil::Porteiro);

        $this->assertTrue(app(Chamadas::class)->atender($chamada, $ana));
        $this->assertFalse(app(Chamadas::class)->atender($chamada->fresh(), $bia));
        $this->assertSame($ana->id, $chamada->fresh()->atendida_por_id);

        // A outra pessoa deixa de ver a chamada no pop-up.
        $this->assertCount(0, app(Chamadas::class)->ativasPara($bia));
        $this->assertCount(1, app(Chamadas::class)->ativasPara($ana));
    }

    public function test_abrir_pelo_popup_atende_abre_e_registra_origem_central(): void
    {
        $chamada = app(Chamadas::class)->iniciar($this->interfone, OrigemChamada::Teste);
        $operador = $this->usuarioCom(Perfil::Operador);
        $this->entrarComo($operador);

        Livewire::test('pages::central.popup')
            ->assertSee('Interfone tocando')->assertSee('Portão social')
            ->call('abrir', $chamada->uuid);

        $chamada->refresh();
        $this->assertTrue($chamada->porta_aberta);
        $this->assertSame(StatusChamada::EmAtendimento, $chamada->status);
        $evento = EventoAcesso::latest('id')->first();
        $this->assertSame(OrigemEvento::Central, $evento->origem);
        $this->assertSame($operador->id, $evento->user_id);

        Livewire::test('pages::central.popup')->set("observacoes.{$chamada->uuid}", 'Entregador do mercado')->call('encerrar', $chamada->uuid);
        $this->assertSame(StatusChamada::Encerrada, $chamada->fresh()->status);
        $this->assertSame('Entregador do mercado', $chamada->fresh()->observacao);
    }

    public function test_quem_nao_atende_o_condominio_nao_mexe_na_chamada(): void
    {
        $chamada = app(Chamadas::class)->iniciar($this->interfone, OrigemChamada::Teste);
        $this->entrarComo($this->usuarioCom(Perfil::Porteiro, $this->outro), $this->outro);

        Livewire::test('pages::central.popup')->assertDontSee('Portão social')->call('abrir', $chamada->uuid)->assertForbidden();
        $this->assertFalse($chamada->fresh()->porta_aberta);
    }

    public function test_campainha_por_link_cria_uma_chamada_so(): void
    {
        $link = Interfones::gerarLinkCampainha($this->interfone);

        $this->get($link)->assertOk()->assertJson(['ok' => true]);
        $this->post($link)->assertOk();
        $this->assertSame(1, Chamada::count());
        $this->assertSame(OrigemChamada::Campainha, Chamada::first()->origem);

        $this->get(route('campainha', str_repeat('a', 40)))->assertNotFound();
    }

    public function test_chamada_esquecida_vira_nao_atendida(): void
    {
        $chamada = app(Chamadas::class)->iniciar($this->interfone, OrigemChamada::Teste);
        $this->travel(2)->minutes();

        app(Chamadas::class)->expirarAntigas();
        $this->assertSame(StatusChamada::Perdida, $chamada->fresh()->status);
    }

    public function test_novidades_traz_chamadas_so_para_quem_atende(): void
    {
        app(Chamadas::class)->iniciar($this->interfone, OrigemChamada::Teste);

        $this->entrarComo($this->usuarioCom(Perfil::Operador));
        $this->getJson(route('novidades'))->assertJsonPath('chamadas.0.titulo', 'Portão social')->assertJsonPath('chamadas.0.status', 'tocando');

        $this->entrarComo($this->usuarioCom(Perfil::Morador));
        $this->getJson(route('novidades'))->assertJsonPath('chamadas', null);
    }

    public function test_central_e_softphone_so_para_quem_atende(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Morador));
        $this->get(route('central.index'))->assertForbidden();
        $this->get(route('central.softphone'))->assertForbidden();

        $operador = $this->usuarioCom(Perfil::Operador);
        $this->entrarComo($operador);
        $this->get(route('central.index'))->assertOk()->assertSee('Central de atendimento')->assertSee('data-softphone', false);
        $this->getJson(route('central.softphone'))->assertJson(['ativo' => false]);

        Configuracao::definir('pabx.wss', 'wss://voip.exemplo.com.br/ws');
        $this->getJson(route('central.softphone'))
            ->assertJson(['ativo' => true, 'wss' => 'wss://voip.exemplo.com.br/ws', 'uri' => 'sip:op'.$operador->id.'@voip.exemplo.com.br']);
    }

    public function test_arquivos_do_asterisk_tem_ramais_e_token(): void
    {
        $operador = $this->usuarioCom(Perfil::Operador);

        $pjsip = Pabx::configPjsip();
        $this->assertStringContainsString('[2001](portaria-interfone)', $pjsip);
        $this->assertStringContainsString('password=segredo-sip-123', $pjsip);
        $this->assertStringContainsString('[op'.$operador->id.'](portaria-operador)', $pjsip);
        $this->assertStringContainsString('webrtc=yes', $pjsip);

        $ext = Pabx::configExtensoes();
        $this->assertStringContainsString('PORTARIA_TOKEN='.Pabx::token(), $ext);
        $this->assertStringContainsString('/pabx/evento', $ext);
        $this->assertStringContainsString('X-Portaria-Chamada', $ext);

        $this->entrarComo($operador);
        $this->get(route('sistema.pabx'))->assertForbidden();
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());
        Livewire::test('pages::sistema.pabx')->call('gerarArquivos')->assertSee('pjsip_portaria.conf')->assertSee('[2001](portaria-interfone)');
    }

    public function test_cadastro_de_interfone_cria_senha_e_valida_ramal(): void
    {
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        Livewire::test('pages::dispositivos.form')->set('tipo', 'interfone')->assertSee('Configuração da interfone')
            ->set('nome', 'Interfone garagem')->set('ramal', '2001')->set('monitorar', false)
            ->call('salvar')->assertHasErrors(['ramal' => 'unique']);

        Livewire::test('pages::dispositivos.form')->set('tipo', 'interfone')
            ->set('nome', 'Interfone garagem')->set('ramal', '1000')->set('monitorar', false)
            ->call('salvar')->assertHasErrors(['ramal']);

        Livewire::test('pages::dispositivos.form')->set('tipo', 'interfone')
            ->set('nome', 'Interfone garagem')->set('ramal', '2002')->set('monitorar', false)
            ->call('salvar')->assertHasNoErrors();

        $novo = Dispositivo::where('ramal', '2002')->firstOrFail();
        $this->assertSame(20, strlen($novo->opcao('senha_sip')));

        // Câmera também mostra a configuração (sem driver).
        Livewire::test('pages::dispositivos.form')->set('tipo', 'camera')->assertSee('Marca')->assertSee('Buscar imagem agora');
    }

    public function test_ponto_de_acesso_escolhe_o_interfone(): void
    {
        $outro = Dispositivo::factory()->for($this->condominio)->create(['tipo' => 'interfone', 'driver' => null, 'ramal' => '2005']);
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        Livewire::test('pages::dispositivos.pontos')->call('editar', $this->portao->id)
            ->assertSet('interfone_id', (string) $this->interfone->id)
            ->set('interfone_id', (string) $outro->id)->call('salvar')->assertHasNoErrors();

        $this->assertSame($outro->id, $this->portao->fresh()->interfone_id);
    }
}
