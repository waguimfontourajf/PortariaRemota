<?php

namespace App\Dispositivos\Modbus;

use App\Dispositivos\ExcecaoDeComunicacao;
use Closure;

/**
 * Cliente Modbus pela rede (porta 502 por padrão), sem dependências.
 *
 * Formatos de quadro:
 *  - "tcp": Modbus TCP padrão (cabeçalho MBAP, sem CRC);
 *  - "rtu": Modbus RTU "sobre TCP" (quadro serial com CRC16 dentro do TCP),
 *           usado por alguns conversores Ethernet/RS485 em modo transparente.
 */
class ClienteModbus
{
    public const LER_BOBINAS = 0x01;

    public const LER_ENTRADAS = 0x02;

    public const LER_REGISTRADORES = 0x03;

    public const ESCREVER_BOBINA = 0x05;

    public const ESCREVER_REGISTRADOR = 0x06;

    private const ERROS = [
        1 => 'função não suportada pelo módulo',
        2 => 'endereço (registrador/canal) não existe no módulo',
        3 => 'valor recusado pelo módulo',
        4 => 'falha interna do módulo',
        6 => 'módulo ocupado',
        10 => 'gateway sem caminho para o módulo',
        11 => 'módulo não respondeu ao gateway (confira o Slave ID)',
    ];

    /** @var resource|null */
    private $socket = null;

    private int $transacao = 0;

    /** @var (Closure(string): void)|null */
    private ?Closure $diagnostico = null;

    public function __construct(
        private readonly string $host,
        private readonly int $porta = 502,
        private readonly int $unidade = 1,
        private readonly string $formato = 'tcp',
        private readonly float $timeout = 3.0,
    ) {
        if (! in_array($formato, ['tcp', 'rtu'], true)) {
            throw new ExcecaoDeComunicacao("Formato Modbus desconhecido: {$formato}");
        }
    }

    /**
     * @param  Closure(string): void  $callback
     */
    public function aoDiagnosticar(Closure $callback): static
    {
        $this->diagnostico = $callback;

        return $this;
    }

    public function fechar(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }

        $this->socket = null;
    }

    public function __destruct()
    {
        $this->fechar();
    }

    /**
     * @return list<int>
     */
    public function lerRegistradores(int $endereco, int $quantidade = 1): array
    {
        $dados = $this->requisitar(self::LER_REGISTRADORES, pack('nn', $endereco, $quantidade));
        $bytes = ord($dados[0] ?? "\0");

        if ($bytes !== $quantidade * 2 || strlen($dados) < 1 + $bytes) {
            throw new ExcecaoDeComunicacao('Resposta Modbus com tamanho inesperado.');
        }

        return array_values(unpack('n*', substr($dados, 1, $bytes)));
    }

    public function escreverRegistrador(int $endereco, int $valor): void
    {
        $this->requisitar(self::ESCREVER_REGISTRADOR, pack('nn', $endereco, $valor & 0xFFFF));
    }

    /**
     * @return list<bool>
     */
    public function lerBobinas(int $endereco, int $quantidade = 1): array
    {
        return $this->lerBits(self::LER_BOBINAS, $endereco, $quantidade);
    }

    /**
     * @return list<bool>
     */
    public function lerEntradas(int $endereco, int $quantidade = 1): array
    {
        return $this->lerBits(self::LER_ENTRADAS, $endereco, $quantidade);
    }

    public function escreverBobina(int $endereco, bool $ligado): void
    {
        $this->requisitar(self::ESCREVER_BOBINA, pack('nn', $endereco, $ligado ? 0xFF00 : 0x0000));
    }

    /**
     * @return list<bool>
     */
    private function lerBits(int $funcao, int $endereco, int $quantidade): array
    {
        $dados = $this->requisitar($funcao, pack('nn', $endereco, $quantidade));
        $bytes = ord($dados[0] ?? "\0");

        if ($bytes !== intdiv($quantidade + 7, 8) || strlen($dados) < 1 + $bytes) {
            throw new ExcecaoDeComunicacao('Resposta Modbus com tamanho inesperado.');
        }

        $bits = [];
        for ($i = 0; $i < $quantidade; $i++) {
            $bits[] = (bool) ((ord($dados[1 + intdiv($i, 8)]) >> ($i % 8)) & 1);
        }

        return $bits;
    }

    /**
     * Envia uma requisição e devolve os dados da resposta (sem unidade/função).
     */
    private function requisitar(int $funcao, string $dados): string
    {
        $this->conectar();
        $pdu = chr($funcao).$dados;

        if ($this->formato === 'tcp') {
            $id = $this->transacao = ($this->transacao + 1) & 0xFFFF;
            $quadro = pack('nnn', $id, 0, strlen($pdu) + 1).chr($this->unidade).$pdu;
        } else {
            $quadro = Crc16::anexar(chr($this->unidade).$pdu);
        }

        $this->log(sprintf('→ função 0x%02X: %s', $funcao, bin2hex($quadro)));

        if (@fwrite($this->socket, $quadro) !== strlen($quadro)) {
            $this->fechar();
            throw new ExcecaoDeComunicacao('A conexão com o módulo caiu ao enviar o comando.');
        }

        $resposta = $this->formato === 'tcp' ? $this->receberTcp() : $this->receberRtu($funcao);
        $this->log('← '.bin2hex($resposta));

        $funcaoResposta = ord($resposta[1]);

        if ($funcaoResposta === ($funcao | 0x80)) {
            $codigo = ord($resposta[2] ?? "\0");
            throw new ExcecaoDeComunicacao('O módulo recusou o comando: '.(self::ERROS[$codigo] ?? "erro Modbus {$codigo}").'.');
        }

        if ($funcaoResposta !== $funcao) {
            throw new ExcecaoDeComunicacao(sprintf('Resposta Modbus inesperada (função 0x%02X).', $funcaoResposta));
        }

        return substr($resposta, 2);
    }

    private function conectar(): void
    {
        if ($this->socket) {
            return;
        }

        $this->log("Conectando em {$this->host}:{$this->porta} (Modbus ".strtoupper($this->formato).", Slave ID {$this->unidade})...");
        $erroNumero = 0;
        $erroTexto = '';
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->porta}", $erroNumero, $erroTexto, $this->timeout);

        if (! $socket) {
            throw new ExcecaoDeComunicacao("Não foi possível conectar ao módulo em {$this->host}:{$this->porta} ({$erroTexto}). Confira o IP, a porta e se ele está na mesma rede.");
        }

        stream_set_blocking($socket, true);
        $this->socket = $socket;
    }

    /**
     * Modbus TCP: cabeçalho MBAP (7 bytes) + PDU. Devolve unidade + PDU.
     */
    private function receberTcp(): string
    {
        $limite = microtime(true) + $this->timeout;

        while (true) {
            $cabecalho = $this->lerBytes(6, $limite);
            ['id' => $id, 'tamanho' => $tamanho] = unpack('nid/nprotocolo/ntamanho', $cabecalho);

            if ($tamanho < 2 || $tamanho > 260) {
                throw new ExcecaoDeComunicacao('Resposta Modbus TCP inválida. Se o módulo usa "Modbus RTU sobre TCP", troque o campo "Formato".');
            }

            $corpo = $this->lerBytes($tamanho, $limite);

            if ($id === $this->transacao) {
                return $corpo;
            }

            $this->log("Resposta de outra transação ({$id}) ignorada.");
        }
    }

    /**
     * Modbus RTU sobre TCP: unidade + função + dados + CRC.
     */
    private function receberRtu(int $funcao): string
    {
        $limite = microtime(true) + $this->timeout;
        $inicio = $this->lerBytes(2, $limite);
        $funcaoResposta = ord($inicio[1]);

        $restante = match (true) {
            ($funcaoResposta & 0x80) !== 0 => 3,                                  // código de erro + CRC
            in_array($funcaoResposta, [0x01, 0x02, 0x03, 0x04], true) => null,
            default => 6,                                                 // eco: endereço + valor + CRC
        };

        if ($restante === null) {
            $quantidade = $this->lerBytes(1, $limite);
            $quadro = $inicio.$quantidade.$this->lerBytes(ord($quantidade) + 2, $limite);
        } else {
            $quadro = $inicio.$this->lerBytes($restante, $limite);
        }

        if (! Crc16::valido($quadro)) {
            throw new ExcecaoDeComunicacao('Resposta Modbus RTU com CRC inválido. Se o módulo usa "Modbus TCP", troque o campo "Formato".');
        }

        return substr($quadro, 0, -2);
    }

    private function lerBytes(int $quantidade, float $limite): string
    {
        $dados = '';

        while (strlen($dados) < $quantidade) {
            $restante = $limite - microtime(true);

            if ($restante <= 0) {
                throw new ExcecaoDeComunicacao($dados === ''
                    ? 'O módulo não respondeu a tempo. Confira o Slave ID, o formato (Modbus TCP ou RTU sobre TCP) e a porta.'
                    : 'O módulo parou de responder no meio da resposta.');
            }

            stream_set_timeout($this->socket, (int) $restante, (int) (fmod($restante, 1) * 1_000_000));
            $pedaco = fread($this->socket, $quantidade - strlen($dados));

            if ($pedaco === false || ($pedaco === '' && feof($this->socket))) {
                $this->fechar();
                throw new ExcecaoDeComunicacao('O módulo encerrou a conexão. Confira a porta e o formato (Modbus TCP ou RTU sobre TCP).');
            }

            $dados .= $pedaco;
        }

        return $dados;
    }

    private function log(string $mensagem): void
    {
        if ($this->diagnostico) {
            ($this->diagnostico)($mensagem);
        }
    }
}
