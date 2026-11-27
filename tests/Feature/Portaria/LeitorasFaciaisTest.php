<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Models\Bloco;
use App\Models\Dispositivo;
use App\Models\Pessoa;
use App\Models\Unidade;
use App\Support\FotoDePessoa;
use App\Support\SincronizacaoFacial;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class LeitorasFaciaisTest extends CadastrosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function leitora(string $driver, array $config = []): Dispositivo
    {
        return Dispositivo::factory()->for($this->condominio)->create([
            'nome' => 'Leitora portaria', 'driver' => $driver, 'host' => '192.168.0.60', 'canais' => 1,
            'config' => ['usuario' => 'admin', 'senha' => 'segredo', ...$config],
        ]);
    }

    private function jpeg(): string
    {
        $imagem = imagecreatetruecolor(1200, 1600);
        imagefill($imagem, 0, 0, imagecolorallocate($imagem, 200, 180, 160));
        ob_start();
        imagejpeg($imagem);

        return (string) ob_get_clean();
    }

    private function pessoaLiberada(array $atributos = []): Pessoa
    {
        $pessoa = Pessoa::factory()->for($this->condominio)->create(['nome' => 'Maria Souza', ...$atributos]);
        app(FotoDePessoa::class)->salvar($pessoa, FotoDePessoa::normalizar($this->jpeg()));
        $pessoa->forceFill(['biometria_autorizada_em' => now()])->save();

        return $pessoa->refresh();
    }

    public function test_foto_e_reduzida_para_jpeg_de_ate_200kb(): void
    {
        $jpeg = FotoDePessoa::normalizar($this->jpeg());
        $info = getimagesizefromstring($jpeg);

        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertLessThanOrEqual(800, max($info[0], $info[1]));
        $this->assertLessThanOrEqual(FotoDePessoa::TAMANHO_MAXIMO, strlen($jpeg));
    }

    public function test_sem_consentimento_nao_vai_para_a_leitora(): void
    {
        Http::fake();
        $this->leitora('intelbras_facial');
        $pessoa = $this->pessoaLiberada();
        $pessoa->forceFill(['biometria_autorizada_em' => null])->save();

        $resultados = app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa);

        $this->assertTrue($resultados[0]['sucesso']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('dispositivo_pessoa', 0);
    }

    public function test_intelbras_recebe_pessoa_e_foto(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        $leitora = $this->leitora('intelbras_facial');
        $pessoa = $this->pessoaLiberada();

        $resultado = app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa)[0];

        $this->assertTrue($resultado['sucesso'], $resultado['mensagem']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'AccessUser.cgi?action=insertMulti')
            && $r['UserList'][0]['UserID'] === (string) $pessoa->id && $r['UserList'][0]['UserName'] === 'Maria Souza');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'AccessFace.cgi?action=insertMulti')
            && base64_decode($r['FaceList'][0]['PhotoData'][0]) === app(FotoDePessoa::class)->conteudo($pessoa));
        $this->assertDatabaseHas('dispositivo_pessoa', ['dispositivo_id' => $leitora->id, 'pessoa_id' => $pessoa->id, 'erro' => null]);
    }

    public function test_intelbras_substitui_foto_existente(): void
    {
        Http::fake([
            '*AccessFace.cgi?action=insertMulti' => Http::response("Error\r\nBad Request!", 400),
            '*' => Http::response('OK'),
        ]);
        $this->leitora('intelbras_facial');

        $resultado = app(SincronizacaoFacial::class)->sincronizarPessoa($this->pessoaLiberada())[0];

        $this->assertTrue($resultado['sucesso'], $resultado['mensagem']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'AccessFace.cgi?action=updateMulti'));
    }

    public function test_hikvision_cadastra_usuario_e_face(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 1, 'statusString' => 'OK', 'subStatusCode' => 'ok'])]);
        $this->leitora('hikvision_facial');
        $pessoa = $this->pessoaLiberada();

        $resultado = app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa)[0];

        $this->assertTrue($resultado['sucesso'], $resultado['mensagem']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/ISAPI/AccessControl/UserInfo/SetUp')
            && $r['UserInfo']['employeeNo'] === (string) $pessoa->id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/ISAPI/Intelligent/FDLib/FDSetUp') && $r->isMultipart());
    }

    public function test_hikvision_mostra_erro_da_leitora(): void
    {
        Http::fake([
            '*UserInfo/SetUp*' => Http::response(['statusCode' => 1, 'statusString' => 'OK']),
            '*FDSetUp*' => Http::response(['statusCode' => 6, 'statusString' => 'Invalid Content', 'subStatusCode' => 'faceNotDetected'], 400),
        ]);
        $leitora = $this->leitora('hikvision_facial');
        $pessoa = $this->pessoaLiberada();

        $resultado = app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa)[0];

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('faceNotDetected', $resultado['mensagem']);
        $this->assertDatabaseHas('dispositivo_pessoa', ['dispositivo_id' => $leitora->id, 'pessoa_id' => $pessoa->id]);
    }

    public function test_control_id_faz_login_cadastra_grupo_e_foto(): void
    {
        Http::fake([
            '*/login.fcgi' => Http::response(['session' => 'abc123']),
            '*/load_objects.fcgi*' => Http::response(['users' => [], 'user_groups' => []]),
            '*/user_set_image.fcgi*' => Http::response(['success' => true, 'errors' => []]),
            '*' => Http::response(['ids' => [1]]),
        ]);
        $this->leitora('controlid_facial', ['grupo' => 1]);
        $pessoa = $this->pessoaLiberada();

        $resultado = app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa)[0];

        $this->assertTrue($resultado['sucesso'], $resultado['mensagem']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'create_objects.fcgi') && $r['object'] === 'users' && $r['values'][0]['id'] === $pessoa->id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'create_objects.fcgi') && $r['object'] === 'user_groups');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'user_set_image.fcgi') && str_contains($r->url(), 'user_id='.$pessoa->id)
            && $r->hasHeader('Content-Type', 'application/octet-stream'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'logout.fcgi'));
    }

    public function test_control_id_traduz_erro_da_foto(): void
    {
        Http::fake([
            '*/login.fcgi' => Http::response(['session' => 'abc123']),
            '*/load_objects.fcgi*' => Http::response(['users' => [['id' => 1]], 'user_groups' => [['user_id' => 1]]]),
            '*/user_set_image.fcgi*' => Http::response(['success' => false, 'errors' => [['code' => 2, 'message' => 'Face not detected']]]),
            '*' => Http::response([]),
        ]);
        $this->leitora('controlid_facial');

        $resultado = app(SincronizacaoFacial::class)->sincronizarPessoa($this->pessoaLiberada())[0];

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('nenhum rosto encontrado', $resultado['mensagem']);
    }

    public function test_pessoa_inativada_e_removida_da_leitora(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        $leitora = $this->leitora('intelbras_facial');
        $pessoa = $this->pessoaLiberada();
        app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa);

        $pessoa->update(['ativo' => false]);
        app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'AccessUser.cgi?action=removeMulti') && str_contains(urldecode($r->url()), 'UserIDList[0]='.$pessoa->id));
        $this->assertDatabaseMissing('dispositivo_pessoa', ['dispositivo_id' => $leitora->id, 'pessoa_id' => $pessoa->id]);
    }

    public function test_sincronizacao_da_leitora_so_envia_quem_mudou(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        $leitora = $this->leitora('intelbras_facial');
        $this->pessoaLiberada(['nome' => 'Ana']);
        $this->pessoaLiberada(['nome' => 'Bia']);
        Pessoa::factory()->for($this->condominio)->create(); // sem foto: ignorada

        $primeira = app(SincronizacaoFacial::class)->sincronizarLeitora($leitora);
        $segunda = app(SincronizacaoFacial::class)->sincronizarLeitora($leitora);

        $this->assertSame(2, $primeira['enviadas']);
        $this->assertSame(0, $segunda['enviadas']);
        $this->assertSame(2, $segunda['sem_mudanca']);
    }

    public function test_cadastro_com_foto_e_consentimento_envia_para_leitora(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        $leitora = $this->leitora('intelbras_facial');
        $sindico = $this->usuarioCom(Perfil::Sindico);
        $this->entrarComo($sindico);

        Livewire::test('pages::cadastros.pessoas.form')
            ->set('nome', 'Carlos Lima')
            ->set('foto', UploadedFile::fake()->createWithContent('rosto.jpg', $this->jpeg()))
            ->set('biometria_autorizada', true)
            ->call('salvar')
            ->assertHasNoErrors();

        $pessoa = Pessoa::where('nome', 'Carlos Lima')->firstOrFail();
        $this->assertNotNull($pessoa->foto_path);
        Storage::disk('local')->assertExists($pessoa->foto_path);
        $this->assertSame($sindico->id, $pessoa->biometria_autorizada_por);
        $this->assertDatabaseHas('dispositivo_pessoa', ['dispositivo_id' => $leitora->id, 'pessoa_id' => $pessoa->id, 'erro' => null]);
    }

    public function test_foto_so_e_vista_por_quem_gerencia_o_condominio(): void
    {
        $pessoa = $this->pessoaLiberada();

        $this->entrarComo($this->usuarioCom(Perfil::Sindico))
            ->get(route('pessoas.foto', $pessoa))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->entrarComo($this->usuarioCom(Perfil::Morador))
            ->get(route('pessoas.foto', $pessoa))->assertForbidden();

        $this->entrarComo($this->usuarioCom(Perfil::Sindico, $this->outro), $this->outro)
            ->get(route('pessoas.foto', $pessoa))->assertNotFound();
    }

    public function test_leitora_abre_porta_como_rele(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        $leitora = $this->leitora('intelbras_facial');

        $resultado = $leitora->driverDeRele()->pulso($leitora, 1, 1000);

        $this->assertTrue($resultado->sucesso, $resultado->mensagem);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'accessControl.cgi?action=openDoor&channel=1'));
    }

    public function test_publico_da_leitora_por_tipo_e_bloco(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        $leitora = $this->leitora('intelbras_facial', ['publico' => 'todos', 'blocos' => 'Bloco A']);
        $blocoA = Bloco::factory()->for($this->condominio)->create(['nome' => 'Bloco A']);
        $blocoB = Bloco::factory()->for($this->condominio)->create(['nome' => 'Bloco B']);

        $moradorA = $this->pessoaLiberada(['nome' => 'Morador A']);
        $moradorA->unidades()->attach(Unidade::factory()->for($this->condominio)->create(['bloco_id' => $blocoA->id]));
        $moradorB = $this->pessoaLiberada(['nome' => 'Morador B']);
        $moradorB->unidades()->attach(Unidade::factory()->for($this->condominio)->create(['bloco_id' => $blocoB->id]));
        $zelador = $this->pessoaLiberada(['nome' => 'Zelador', 'tipo' => 'funcionario']);

        $servico = app(SincronizacaoFacial::class);
        $this->assertTrue($servico->deveEstar($leitora, $moradorA->load('unidades.bloco')));
        $this->assertFalse($servico->deveEstar($leitora, $moradorB->load('unidades.bloco')));
        $this->assertTrue($servico->deveEstar($leitora, $zelador), 'funcionário do condomínio entra sempre');

        $totais = $servico->sincronizarLeitora($leitora);
        $this->assertSame(2, $totais['enviadas']);

        // Mudou o público: o zelador sai na próxima sincronização.
        $leitora->update(['config' => [...$leitora->config, 'publico' => 'moradores']]);
        $totais = $servico->sincronizarLeitora($leitora->fresh());
        $this->assertSame(1, $totais['removidas']);
        $this->assertDatabaseMissing('dispositivo_pessoa', ['dispositivo_id' => $leitora->id, 'pessoa_id' => $zelador->id]);
    }
}
