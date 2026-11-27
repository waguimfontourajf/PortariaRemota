<?php

namespace App\Dispositivos\Contratos;

use App\Dispositivos\Resultado;
use App\Models\Dispositivo;

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
     * Cria ou atualiza a pessoa na leitora, com a foto (JPEG até 200 KB).
     */
    public function enviarPessoa(Dispositivo $dispositivo, int $codigo, string $nome, string $fotoJpeg): Resultado;

    /**
     * Remove a pessoa (e a foto) da leitora. Não falha se ela não existir lá.
     */
    public function removerPessoa(Dispositivo $dispositivo, int $codigo): Resultado;
}
