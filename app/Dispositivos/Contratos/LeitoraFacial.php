<?php

namespace App\Dispositivos\Contratos;

use App\Dispositivos\PassagemNaLeitora;
use App\Dispositivos\RegrasDeAcesso;
use App\Dispositivos\Resultado;
use App\Models\Dispositivo;
use Carbon\CarbonInterface;

/**
 * Leitora de reconhecimento facial que recebe o cadastro das pessoas do
 * sistema. A leitora guarda uma cópia (nome, código e foto) e reconhece o
 * rosto sozinha, mesmo sem rede; o sistema é o dono do cadastro.
 *
 * O código da pessoa na leitora é o ID numérico da pessoa no sistema.
 */
interface LeitoraFacial
{
    /**
     * Cria ou atualiza a pessoa na leitora, com a foto (JPEG até 200 KB) e as
     * regras de acesso (validade e horário semanal).
     */
    public function enviarPessoa(Dispositivo $dispositivo, int $codigo, string $nome, string $fotoJpeg, RegrasDeAcesso $regras): Resultado;

    /**
     * Esquece o que já foi configurado na leitora (horários), para reenviar tudo.
     */
    public function esquecerConfiguracao(Dispositivo $dispositivo): void;

    /**
     * Remove a pessoa (e a foto) da leitora. Não falha se ela não existir lá.
     */
    public function removerPessoa(Dispositivo $dispositivo, int $codigo): Resultado;

    /**
     * Passagens registradas pela leitora depois do cursor (ou desde $desde,
     * na primeira vez). "mais" = ainda há eventos para buscar.
     *
     * @return array{passagens: list<PassagemNaLeitora>, cursor: ?string, mais: bool}
     *
     * @throws \App\Dispositivos\ExcecaoDeComunicacao
     */
    public function buscarPassagens(Dispositivo $dispositivo, ?string $cursor, CarbonInterface $desde): array;
}
