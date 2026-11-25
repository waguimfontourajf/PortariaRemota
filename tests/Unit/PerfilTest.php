<?php

namespace Tests\Unit;

use App\Enums\Perfil;
use App\Enums\Permissao;
use PHPUnit\Framework\TestCase;

class PerfilTest extends TestCase
{
    public function test_somente_operador_exige_2fa(): void
    {
        $this->assertSame([Perfil::Operador->value], Perfil::exigindo2fa());
    }

    public function test_morador_nao_gerencia_cadastros_nem_ve_historico_completo(): void
    {
        $permissoes = Perfil::Morador->permissoes();

        $this->assertContains(Permissao::AcessosAbrir, $permissoes);
        $this->assertNotContains(Permissao::CadastrosGerenciar, $permissoes);
        $this->assertNotContains(Permissao::HistoricoVer, $permissoes);
    }

    public function test_todo_perfil_tem_label_e_permissoes(): void
    {
        foreach (Perfil::cases() as $perfil) {
            $this->assertNotEmpty($perfil->label());
            $this->assertNotEmpty($perfil->permissoes());
        }
    }
}
