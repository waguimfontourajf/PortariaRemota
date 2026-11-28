<?php

namespace Tests\Feature\Portaria;

use App\Acoes\AcionarPontoDeAcesso;
use App\Enums\OrigemEvento;
use App\Enums\Perfil;
use App\Models\Dispositivo;
use App\Models\PontoAcesso;
use App\Models\QuedaDispositivo;
use App\Models\User;

class TempoRealTest extends CadastrosTestCase
{
    private function ponto(array $atributos = [], array $dispositivo = [], $condominio = null): PontoAcesso
    {
        $condominio ??= $this->condominio;
        $d = Dispositivo::factory()->for($condominio)->create($dispositivo);

        return PontoAcesso::factory()->for($condominio)->create(['dispositivo_id' => $d->id, 'liberado_moradores' => true, ...$atributos]);
    }

    /**
     * Primeira chamada (só cursor) e devolve a função que pergunta as novidades.
     */
    private function vigiar(User $usuario): \Closure
    {
        $this->entrarComo($usuario);
        $cursor = $this->getJson(route('novidades'))->assertOk()->assertJson(['eventos' => [], 'rede' => []])->json('cursor');

        return function () use (&$cursor) {
            $resposta = $this->getJson(route('novidades', $cursor))->assertOk();
            $cursor = $resposta->json('cursor');

            return $resposta->json();
        };
    }

    public function test_exige_login(): void
    {
        $this->getJson(route('novidades'))->assertUnauthorized();
    }

    public function test_primeira_chamada_nao_repete_o_passado(): void
    {
        app(AcionarPontoDeAcesso::class)($this->ponto(), $this->usuarioCom(Perfil::Operador), OrigemEvento::Web);

        $novidades = $this->vigiar($this->usuarioCom(Perfil::Operador));

        $this->assertSame([], $novidades()['eventos']);
    }

    public function test_operador_recebe_acessos_novos_uma_unica_vez_com_alerta_nas_falhas(): void
    {
        $ok = $this->ponto(['nome' => 'Portão social']);
        $quebrado = $this->ponto(['nome' => 'Garagem'], ['config' => ['falhar' => true]]);
        $novidades = $this->vigiar($this->usuarioCom(Perfil::Operador));
        $porteiro = $this->usuarioCom(Perfil::Porteiro);

        app(AcionarPontoDeAcesso::class)($ok, $porteiro, OrigemEvento::Web);
        app(AcionarPontoDeAcesso::class)($quebrado, $porteiro, OrigemEvento::Web);

        $eventos = collect($novidades()['eventos']);
        $this->assertSame(['Portão social', 'Garagem'], $eventos->pluck('ponto')->all());
        $this->assertSame([false, true], $eventos->pluck('alerta')->all());

        $this->assertSame([], $novidades()['eventos'], 'não repete');
    }

    public function test_morador_so_recebe_os_proprios_acessos(): void
    {
        $ponto = $this->ponto();
        $eu = $this->usuarioCom(Perfil::Morador);
        $novidades = $this->vigiar($eu);

        app(AcionarPontoDeAcesso::class)($ponto, $this->usuarioCom(Perfil::Morador), OrigemEvento::Web);
        app(AcionarPontoDeAcesso::class)($ponto, $eu, OrigemEvento::Web);

        $eventos = $novidades()['eventos'];
        $this->assertCount(1, $eventos);
        $this->assertSame($eu->name, $eventos[0]['usuario']);
        $this->assertFalse($eventos[0]['alerta'], 'o próprio acesso não gera aviso');
    }

    public function test_nao_recebe_acessos_de_outro_condominio(): void
    {
        $novidades = $this->vigiar($this->usuarioCom(Perfil::Sindico));

        app(AcionarPontoDeAcesso::class)($this->ponto([], [], $this->outro), User::factory()->superAdmin()->create(), OrigemEvento::Web);

        $this->assertSame([], $novidades()['eventos']);
    }

    public function test_equipe_da_central_recebe_quedas_e_voltas_de_todos_os_seus_condominios(): void
    {
        $operador = $this->usuarioCom(Perfil::Operador);
        $operador->atribuirPerfil(Perfil::Operador, $this->outro);
        $novidades = $this->vigiar($operador);

        $roteador = Dispositivo::factory()->for($this->outro)->create(['nome' => 'Roteador Beta', 'tipo' => 'roteador', 'driver' => null]);
        $queda = QuedaDispositivo::create(['condominio_id' => $this->outro->id, 'dispositivo_id' => $roteador->id, 'inicio' => now()]);

        $rede = $novidades()['rede'];
        $this->assertSame('caiu', $rede[0]['tipo']);
        $this->assertSame('Roteador Beta', $rede[0]['dispositivo']);
        $this->assertSame('Residencial Beta', $rede[0]['condominio']);

        $this->travel(5)->seconds();
        $queda->update(['fim' => now()]);
        $this->assertSame('voltou', $novidades()['rede'][0]['tipo']);
    }

    public function test_sindico_so_ve_quedas_do_proprio_condominio_e_morador_nenhuma(): void
    {
        $alheio = Dispositivo::factory()->for($this->outro)->create(['driver' => null]);
        $daqui = Dispositivo::factory()->for($this->condominio)->create(['nome' => 'Switch Alfa', 'driver' => null]);

        $sindico = $this->vigiar($this->usuarioCom(Perfil::Sindico));
        QuedaDispositivo::create(['condominio_id' => $this->outro->id, 'dispositivo_id' => $alheio->id, 'inicio' => now()]);
        QuedaDispositivo::create(['condominio_id' => $this->condominio->id, 'dispositivo_id' => $daqui->id, 'inicio' => now()]);
        $this->assertSame(['Switch Alfa'], collect($sindico()['rede'])->pluck('dispositivo')->all());

        $morador = $this->vigiar($this->usuarioCom(Perfil::Morador));
        QuedaDispositivo::create(['condominio_id' => $this->condominio->id, 'dispositivo_id' => $daqui->id, 'inicio' => now()]);
        $this->assertSame([], $morador()['rede']);
    }

    public function test_paginas_ouvem_o_tempo_real(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        $this->get(route('dashboard'))->assertOk()->assertSee('tempo-real-acessos', false)->assertSee('name="tempo-real"', false);
        $this->get(route('acessos.historico'))->assertSee('tempo-real-acessos', false);
        $this->get(route('acessos.abrir'))->assertSee('tempo-real-acessos', false);
    }
}
