<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Models\Bloco;
use App\Models\Dispositivo;
use App\Models\EventoAcesso;
use App\Models\HorarioAcesso;
use App\Models\Pessoa;
use App\Models\Unidade;
use App\Support\FotoDePessoa;
use App\Support\ImportacaoDePassagens;
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

    private function horario(): HorarioAcesso
    {
        $horario = new HorarioAcesso(['nome' => 'Diaristas', 'dias' => [1, 2, 3, 4, 5], 'inicio' => '07:00:00', 'fim' => '18:00:59']);
        $horario->condominio_id = $this->condominio->id;
        $horario->numero = 1;
        $horario->save();

        return $horario;
    }

    public function test_intelbras_recebe_horario_e_validade(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        $this->leitora('intelbras_facial');
        $pessoa = $this->pessoaLiberada(['horario_acesso_id' => $this->horario()->id, 'acesso_ate' => '2027-03-31']);

        $resultado = app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa->refresh())[0];

        $this->assertTrue($resultado['sucesso'], $resultado['mensagem']);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'AccessTimeSchedule[1].TimeSchedule[1][0]=1 07:00:00-18:00:59')
            && str_contains(urldecode($r->url()), 'AccessTimeSchedule[1].TimeSchedule[0][0]=0 00:00:00-00:00:00'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'AccessUser.cgi?action=insertMulti')
            && $r['UserList'][0]['TimeSections'] === [1] && $r['UserList'][0]['ValidTo'] === '2027-03-31 23:59:59');
    }

    public function test_hikvision_recebe_horario_e_validade(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'])]);
        $this->leitora('hikvision_facial');
        $pessoa = $this->pessoaLiberada(['horario_acesso_id' => $this->horario()->id, 'acesso_de' => '2026-12-01']);

        $resultado = app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa->refresh())[0];

        $this->assertTrue($resultado['sucesso'], $resultado['mensagem']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/UserRightWeekPlanCfg/2')
            && $r['UserRightWeekPlanCfg']['WeekPlanCfg'][1]['week'] === 'Monday' && $r['UserRightWeekPlanCfg']['WeekPlanCfg'][1]['enable'] === true
            && $r['UserRightWeekPlanCfg']['WeekPlanCfg'][0]['enable'] === false);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/UserRightPlanTemplate/2') && $r['UserRightPlanTemplate']['weekPlanNo'] === 2);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'UserInfo/SetUp')
            && $r['UserInfo']['RightPlan'][0]['planTemplateNo'] === '2'
            && $r['UserInfo']['Valid']['enable'] === true && $r['UserInfo']['Valid']['beginTime'] === '2026-12-01T00:00:00');
    }

    public function test_control_id_recebe_horario_como_grupo(): void
    {
        Http::fake([
            '*/login.fcgi' => Http::response(['session' => 'abc123']),
            '*/load_objects.fcgi*' => Http::response([]),
            '*/user_set_image.fcgi*' => Http::response(['success' => true]),
            '*' => Http::response([]),
        ]);
        $this->leitora('controlid_facial');
        $pessoa = $this->pessoaLiberada(['horario_acesso_id' => $this->horario()->id]);

        $resultado = app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa->refresh())[0];

        $this->assertTrue($resultado['sucesso'], $resultado['mensagem']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'create_objects.fcgi') && $r['object'] === 'time_spans'
            && $r['values'][0]['mon'] === 1 && $r['values'][0]['sun'] === 0 && $r['values'][0]['start'] === 7 * 3600 && $r['values'][0]['time_zone_id'] === 1001);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'create_objects.fcgi') && $r['object'] === 'user_groups' && $r['values'][0]['group_id'] === 1001);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'create_objects.fcgi') && $r['object'] === 'portal_access_rules');
    }

    public function test_editar_horario_reenvia_quem_usa(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        $leitora = $this->leitora('intelbras_facial');
        $horario = $this->horario();
        $this->pessoaLiberada(['horario_acesso_id' => $horario->id]);
        app(SincronizacaoFacial::class)->sincronizarLeitora($leitora);

        $horario->update(['fim' => '20:00:59']);
        $totais = app(SincronizacaoFacial::class)->sincronizarLeitora($leitora);

        $this->assertSame(1, $totais['enviadas']);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), '07:00:00-20:00:59'));
    }

    public function test_cadastra_horario_com_numero_sequencial(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::cadastros.horarios')
            ->set('nome', 'Comercial')->set('dias', ['1', '2', '3', '4', '5'])->set('inicio', '08:00')->set('fim', '17:00')
            ->call('salvar')->assertHasNoErrors();

        $horario = HorarioAcesso::where('nome', 'Comercial')->firstOrFail();
        $this->assertSame(1, $horario->numero);
        $this->assertSame([1, 2, 3, 4, 5], $horario->dias);
        $this->assertSame('Seg a Sex · 08:00 às 17:00', $horario->descricao());
    }

    public function test_importa_passagens_da_intelbras_sem_duplicar(): void
    {
        $pessoa = $this->pessoaLiberada();
        $agora = now()->getTimestamp();
        Http::fake(['*recordFinder.cgi*' => Http::response(implode("\r\n", [
            'found=3',
            "records[0].CreateTime={$agora}", 'records[0].Method=15', "records[0].UserID={$pessoa->id}", 'records[0].RecNo=10', 'records[0].Status=1',
            "records[1].CreateTime={$agora}", 'records[1].Method=1', 'records[1].UserID=5', 'records[1].RecNo=11',
            "records[2].CreateTime={$agora}", 'records[2].Method=15', 'records[2].UserID=0', 'records[2].RecNo=12', 'records[2].Status=0',
        ]))]);
        $leitora = $this->leitora('intelbras_facial');

        $this->assertSame(2, app(ImportacaoDePassagens::class)->importar($leitora));
        $this->assertSame(0, app(ImportacaoDePassagens::class)->importar($leitora->fresh()), 'não importa de novo');

        $this->assertDatabaseHas('eventos_acesso', ['pessoa_id' => $pessoa->id, 'origem' => 'leitora', 'resultado' => 'sucesso', 'usuario_nome' => 'Maria Souza']);
        $this->assertDatabaseHas('eventos_acesso', ['pessoa_id' => null, 'resultado' => 'negado', 'usuario_nome' => 'Rosto não reconhecido']);
        $this->assertSame((string) $agora, $leitora->fresh()->eventos_cursor);
    }

    public function test_importa_passagens_da_hikvision(): void
    {
        $pessoa = $this->pessoaLiberada();
        Http::fake(['*AcsEvent*' => Http::response(['AcsEvent' => [
            'responseStatusStrg' => 'OK', 'numOfMatches' => 2,
            'InfoList' => [
                ['major' => 5, 'minor' => 75, 'time' => '2026-11-27T10:00:00-03:00', 'employeeNoString' => (string) $pessoa->id, 'serialNo' => 575],
                ['major' => 5, 'minor' => 22, 'time' => '2026-11-27T10:00:05-03:00', 'serialNo' => 576],
            ],
        ]])]);
        $leitora = $this->leitora('hikvision_facial');

        $this->assertSame(1, app(ImportacaoDePassagens::class)->importar($leitora));

        $evento = EventoAcesso::where('pessoa_id', $pessoa->id)->firstOrFail();
        $this->assertSame('2026-11-27 13:00:00', $evento->created_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_importa_passagens_da_control_id(): void
    {
        $pessoa = $this->pessoaLiberada();
        Http::fake([
            '*/login.fcgi' => Http::response(['session' => 'abc123']),
            '*/load_objects.fcgi*' => Http::response(['access_logs' => [
                ['id' => 41, 'time' => now()->getTimestamp(), 'event' => 7, 'user_id' => $pessoa->id],
                ['id' => 42, 'time' => now()->getTimestamp(), 'event' => 12, 'user_id' => 0],
                ['id' => 43, 'time' => now()->getTimestamp(), 'event' => 6, 'user_id' => $pessoa->id],
            ]]),
            '*' => Http::response([]),
        ]);
        $leitora = $this->leitora('controlid_facial');

        $this->assertSame(2, app(ImportacaoDePassagens::class)->importar($leitora));
        $this->assertSame('43', $leitora->fresh()->eventos_cursor);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'load_objects.fcgi') && ($r['object'] ?? null) === 'access_logs');
    }

    public function test_morador_ve_a_propria_passagem_no_historico(): void
    {
        $morador = $this->usuarioCom(Perfil::Morador);
        $pessoa = $this->pessoaLiberada();
        $pessoa->forceFill(['user_id' => $morador->id])->save();
        Http::fake(['*recordFinder.cgi*' => Http::response('records[0].CreateTime='.now()->getTimestamp()."\r\nrecords[0].Method=15\r\nrecords[0].UserID={$pessoa->id}\r\nrecords[0].RecNo=1")]);
        app(ImportacaoDePassagens::class)->importar($this->leitora('intelbras_facial'));

        $this->entrarComo($morador);
        Livewire::test('pages::acessos.historico')->assertSee('Maria Souza')->assertSee('Leitora facial');
    }
}
