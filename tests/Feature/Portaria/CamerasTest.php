<?php

namespace Tests\Feature\Portaria;

use App\Cameras\Cameras;
use App\Enums\Perfil;
use App\Models\Dispositivo;
use App\Models\PontoAcesso;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class CamerasTest extends CadastrosTestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0fake-jpeg";

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('file')->flush();

        // Como a câmera real: sem senha, responde 401 com desafio Digest.
        Http::fake(function (Request $r) {
            if (! $r->hasHeader('Authorization')) {
                return Http::response('', 401, ['WWW-Authenticate' => 'Digest realm="Camera", qop="auth", nonce="'.md5('n').'", algorithm=MD5']);
            }

            return null;
        });
    }

    private function camera(array $config = [], array $extra = []): Dispositivo
    {
        return Dispositivo::factory()->for($extra['condominio'] ?? $this->condominio)->create([
            'nome' => 'Câmera portão', 'tipo' => 'camera', 'driver' => null, 'host' => '192.168.1.108', 'porta' => 80,
            'config' => array_merge(['marca' => 'intelbras', 'usuario' => 'admin', 'senha' => 'segredo', 'canal' => 1], $config),
        ] + array_diff_key($extra, ['condominio' => 1]));
    }

    public function test_porteiro_ve_a_imagem_intelbras_com_digest(): void
    {
        Http::fake(['*' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg'])]);
        $camera = $this->camera();
        $this->entrarComo($this->usuarioCom(Perfil::Porteiro));

        $this->get(route('cameras.imagem', $camera))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        Http::assertSent(fn (Request $r) => $r->url() === 'http://192.168.1.108/cgi-bin/snapshot.cgi?channel=1'
            && str_starts_with($r->header('Authorization')[0] ?? '', 'Digest username="admin"'));
    }

    public function test_hikvision_usa_isapi_e_rtsp_por_marca(): void
    {
        $hik = $this->camera(['marca' => 'hikvision', 'canal' => 2]);
        $int = $this->camera(['canal' => 3]);

        $this->assertSame('http://192.168.1.108/ISAPI/Streaming/channels/201/picture', app(Cameras::class)->urlImagem($hik));
        $this->assertSame('rtsp://192.168.1.108:554/Streaming/Channels/202', app(Cameras::class)->urlRtsp($hik));
        $this->assertSame('rtsp://192.168.1.108:554/cam/realmonitor?channel=3&subtype=1', app(Cameras::class)->urlRtsp($int));
    }

    public function test_morador_so_ve_camera_liberada_para_moradores(): void
    {
        Http::fake(['*' => Http::response(self::JPEG)]);
        $interna = $this->camera();
        $portao = $this->camera(['liberada_moradores' => true]);
        $this->entrarComo($this->usuarioCom(Perfil::Morador));

        $this->get(route('cameras.imagem', $interna))->assertNotFound();
        $this->get(route('cameras.imagem', $portao))->assertOk();
        Livewire::test('pages::cameras.index')->assertSee('Câmera portão');
    }

    public function test_camera_de_outro_condominio_nao_aparece(): void
    {
        $camera = $this->camera(extra: ['condominio' => $this->outro]);
        $this->entrarComo($this->usuarioCom(Perfil::Porteiro));

        $this->get(route('cameras.imagem', $camera))->assertNotFound();
    }

    public function test_falha_da_camera_vira_mensagem_e_senha_nao_vaza(): void
    {
        Http::fake(['*' => Http::response('', 401)]);
        $camera = $this->camera();
        $this->entrarComo($this->usuarioCom(Perfil::Porteiro));

        $this->get(route('cameras.imagem', $camera))->assertStatus(503)->assertSee('recusou o usuário/senha')->assertDontSee('segredo');
    }

    public function test_resposta_que_nao_e_jpeg_e_recusada(): void
    {
        Http::fake(['*' => Http::response('<html>login</html>')]);
        $this->entrarComo($this->usuarioCom(Perfil::Porteiro));

        $this->get(route('cameras.imagem', $this->camera()))->assertStatus(503)->assertSee('não devolveu uma imagem');
    }

    public function test_camera_aparece_junto_do_botao_abrir(): void
    {
        $camera = $this->camera(['liberada_moradores' => true]);
        $rele = Dispositivo::factory()->for($this->condominio)->create();
        $ponto = PontoAcesso::factory()->for($this->condominio)->create(['nome' => 'Portão social', 'dispositivo_id' => $rele->id, 'liberado_moradores' => true]);
        $ponto->cameras()->attach($camera->id);
        $this->entrarComo($this->usuarioCom(Perfil::Morador));

        Livewire::test('pages::acessos.abrir')->assertSeeHtml('alt="Câmera portão"')->assertSeeHtml($camera->uuid.'\/imagem');
    }

    public function test_cadastro_de_ponto_escolhe_cameras(): void
    {
        $camera = $this->camera();
        $rele = Dispositivo::factory()->for($this->condominio)->create();
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());
        $ponto = PontoAcesso::factory()->for($this->condominio)->create(['dispositivo_id' => $rele->id]);

        Livewire::test('pages::dispositivos.pontos')->call('editar', $ponto->id)
            ->set('camerasEscolhidas', [(string) $camera->id])->call('salvar')->assertHasNoErrors();

        $this->assertSame([$camera->id], $ponto->cameras()->pluck('dispositivos.id')->all());
    }
}
