<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Models\Dispositivo;
use App\Models\EventoAcesso;
use App\Models\Pessoa;
use App\Models\PontoAcesso;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Visita;
use Livewire\Livewire;

class VisitantesTest extends CadastrosTestCase
{
    private PontoAcesso $social;

    private PontoAcesso $garagem;

    protected function setUp(): void
    {
        parent::setUp();
        $rele = Dispositivo::factory()->for($this->condominio)->create(['canais' => 2]);
        $this->social = PontoAcesso::factory()->for($this->condominio)->create(['nome' => 'Portão social', 'dispositivo_id' => $rele->id, 'liberado_visitantes' => true]);
        $this->garagem = PontoAcesso::factory()->for($this->condominio)->create(['nome' => 'Garagem', 'dispositivo_id' => $rele->id, 'canal' => 2, 'liberado_visitantes' => false]);
    }

    private function moradorComUnidade(): User
    {
        $morador = $this->usuarioCom(Perfil::Morador);
        $pessoa = Pessoa::factory()->for($this->condominio)->create(['user_id' => $morador->id]);
        $pessoa->unidades()->attach(Unidade::factory()->for($this->condominio)->create(['numero' => '101']), ['vinculo' => 'proprietario']);

        return $morador;
    }

    /**
     * Cria a autorização pela tela e devolve [visita, token do link].
     *
     * @return array{0: Visita, 1: string}
     */
    private function autorizar(User $morador, int $usos = 1, array $extra = []): array
    {
        $this->entrarComo($morador);
        $tela = Livewire::test('pages::visitas.form')->set('nome', 'Carlos Entregador')->set('usos', $usos);
        foreach ($extra as $campo => $valor) {
            $tela->set($campo, $valor);
        }
        $tela->call('salvar')->assertHasNoErrors();

        $visita = Visita::latest('id')->firstOrFail();

        return [$visita, $visita->token];
    }

    public function test_morador_cria_autorizacao_so_com_portoes_liberados_para_visitantes(): void
    {
        $morador = $this->moradorComUnidade();
        [$visita, $token] = $this->autorizar($morador);

        $this->assertSame(32, strlen($token));
        $this->assertSame(hash('sha256', $token), $visita->token_hash);
        $this->assertStringNotContainsString($token, (string) $visita->getRawOriginal('token'), 'token guardado criptografado');
        $this->assertSame(['Portão social'], $visita->pontos->pluck('nome')->all());
        $this->assertSame('101', $visita->unidade->numero);
        $this->assertTrue($visita->valido_ate->isFuture());
    }

    public function test_visitante_abre_pelo_link_sem_login_e_o_uso_e_contado(): void
    {
        [$visita, $token] = $this->autorizar($this->moradorComUnidade());
        auth()->logout();

        $this->get(route('visitante.mostrar', $token))->assertOk()->assertSee('Olá, Carlos!')->assertSee('ABRIR PORTÃO SOCIAL');

        $this->post(route('visitante.abrir', [$token, $this->social->uuid]))
            ->assertRedirect(route('visitante.mostrar', $token))
            ->assertSessionHas('resultado.sucesso', true);

        $this->assertSame(1, $visita->fresh()->usos);
        $evento = EventoAcesso::latest('id')->first();
        $this->assertSame($visita->id, $evento->visita_id);
        $this->assertSame('visitante', $evento->origem->value);
        $this->assertStringStartsWith('Visitante: Carlos Entregador (autorizado por', $evento->usuario_nome);

        // Limite de 1 uso: a segunda tentativa é negada.
        $this->post(route('visitante.abrir', [$token, $this->social->uuid]))->assertSessionHas('resultado.sucesso', false);
        $this->assertSame(1, $visita->fresh()->usos);
        $this->get(route('visitante.mostrar', $token))->assertSee('já foi usada o número máximo de vezes');
    }

    public function test_link_nao_abre_portao_fora_da_autorizacao(): void
    {
        [$visita, $token] = $this->autorizar($this->moradorComUnidade(), 5);
        auth()->logout();

        $this->post(route('visitante.abrir', [$token, $this->garagem->uuid]))->assertSessionHas('resultado.sucesso', false);
        $this->assertSame(0, $visita->fresh()->usos);
    }

    public function test_link_de_outro_condominio_nao_abre_ponto_daqui(): void
    {
        $rele = Dispositivo::factory()->for($this->outro)->create();
        $alheio = PontoAcesso::factory()->for($this->outro)->create(['dispositivo_id' => $rele->id, 'liberado_visitantes' => true]);
        [, $token] = $this->autorizar($this->moradorComUnidade());
        auth()->logout();

        $this->post(route('visitante.abrir', [$token, $alheio->uuid]))->assertNotFound();
    }

    public function test_token_errado_cancelado_ou_expirado_nao_funciona(): void
    {
        [$visita, $token] = $this->autorizar($this->moradorComUnidade(), 5);
        auth()->logout();

        $this->get('/v/'.str_repeat('a', 32))->assertNotFound();

        $visita->forceFill(['revogada_em' => now()])->save();
        $this->post(route('visitante.abrir', [$token, $this->social->uuid]))->assertSessionHas('resultado.sucesso', false);

        $visita->forceFill(['revogada_em' => null, 'valido_ate' => now()->subMinute()])->save();
        $this->post(route('visitante.abrir', [$token, $this->social->uuid]))->assertSessionHas('resultado.sucesso', false);

        $this->assertSame(0, $visita->fresh()->usos);
        $this->assertSame(2, EventoAcesso::where('visita_id', $visita->id)->where('resultado', 'negado')->count());
    }

    public function test_falha_no_portao_devolve_o_uso(): void
    {
        $this->social->dispositivo->update(['config' => ['falhar' => true]]);
        [$visita, $token] = $this->autorizar($this->moradorComUnidade());
        auth()->logout();

        $this->post(route('visitante.abrir', [$token, $this->social->uuid]))->assertSessionHas('resultado.sucesso', false);

        $this->assertSame(0, $visita->fresh()->usos, 'pode tentar de novo');
    }

    public function test_muitas_tentativas_sao_bloqueadas(): void
    {
        [, $token] = $this->autorizar($this->moradorComUnidade(), 50);
        auth()->logout();

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('visitante.abrir', [$token, $this->social->uuid]));
        }

        $this->post(route('visitante.abrir', [$token, $this->social->uuid]))->assertStatus(429);
    }

    public function test_morador_ve_e_cancela_so_as_proprias_e_recebe_aviso_do_seu_visitante(): void
    {
        $eu = $this->moradorComUnidade();
        [$minha, $token] = $this->autorizar($eu, 3);
        [$dele] = $this->autorizar($this->moradorComUnidade());

        $this->entrarComo($eu);
        $cursor = $this->getJson(route('novidades'))->json('cursor');

        Livewire::test('pages::visitas.index')->set('filtro', 'todas')->assertSee('Carlos Entregador')->call('cancelar', $dele->id)->assertNotFound();
        $this->get(route('visitas.ver', $dele))->assertNotFound();
        $this->get(route('visitas.ver', $minha))->assertOk()->assertSee($minha->token);

        // O visitante dele entra: o morador recebe o aviso e vê no histórico.
        $this->post(route('visitante.abrir', [$token, $this->social->uuid]));
        $this->entrarComo($eu);
        $eventos = $this->getJson(route('novidades', $cursor))->json('eventos');
        $this->assertSame('Carlos Entregador', $eventos[0]['meu_visitante']);
        $this->get(route('acessos.historico'))->assertSee('Visitante: Carlos Entregador');

        Livewire::test('pages::visitas.index')->call('cancelar', $minha->id);
        $this->assertNotNull($minha->fresh()->revogada_em);
    }

    public function test_portaria_ve_todas_as_visitas_mas_nao_o_link(): void
    {
        [$visita] = $this->autorizar($this->moradorComUnidade());
        $this->entrarComo($this->usuarioCom(Perfil::Porteiro));

        $this->get(route('visitas.index'))->assertOk()->assertSee('Carlos Entregador');
        $this->get(route('visitas.ver', $visita))->assertOk()->assertDontSee($visita->token);
    }

    public function test_app_instalavel(): void
    {
        $this->assertJson(file_get_contents(public_path('manifest.webmanifest')));
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));

        $this->entrarComo($this->moradorComUnidade());
        $this->get(route('acessos.abrir'))->assertSee('manifest.webmanifest', false)->assertSee('Instale o app no seu celular');
        $this->get(route('dashboard'))->assertRedirect(route('acessos.abrir'));
    }
}
