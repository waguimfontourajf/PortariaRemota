<?php

namespace App\Support;

use App\Models\Pessoa;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Foto do rosto da pessoa (usada pelas leitoras faciais).
 *
 * Fica no disco privado (storage/app/private/pessoas), nunca em pasta pública.
 * É sempre gravada como JPEG de até 200 KB e no máximo 800 px no maior lado:
 * é o que as leitoras Intelbras e Hikvision aceitam (Control iD aceita mais).
 */
class FotoDePessoa
{
    public const TAMANHO_MAXIMO = 200 * 1024;

    private const LADO_MAXIMO = 800;

    private const DISCO = 'local';

    /**
     * Converte a imagem enviada e grava como a foto da pessoa.
     */
    public function salvar(Pessoa $pessoa, string $conteudo): void
    {
        $jpeg = self::normalizar($conteudo);
        $caminho = 'pessoas/'.$pessoa->uuid.'.jpg';

        Storage::disk(self::DISCO)->put($caminho, $jpeg);
        $pessoa->forceFill(['foto_path' => $caminho])->save();
    }

    public function remover(Pessoa $pessoa): void
    {
        if ($pessoa->foto_path) {
            Storage::disk(self::DISCO)->delete($pessoa->foto_path);
        }

        $pessoa->forceFill(['foto_path' => null])->save();
    }

    public function conteudo(Pessoa $pessoa): ?string
    {
        if (! $pessoa->foto_path || ! Storage::disk(self::DISCO)->exists($pessoa->foto_path)) {
            return null;
        }

        return Storage::disk(self::DISCO)->get($pessoa->foto_path);
    }

    /**
     * JPEG, orientação corrigida (fotos de celular), até 800 px e 200 KB.
     */
    public static function normalizar(string $conteudo): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('A extensão GD do PHP não está ativa no servidor (necessária para as fotos).');
        }

        $imagem = @imagecreatefromstring($conteudo);

        if ($imagem === false) {
            throw new RuntimeException('Arquivo de imagem não reconhecido. Use JPG ou PNG.');
        }

        $imagem = self::corrigirOrientacao($imagem, $conteudo);
        [$largura, $altura] = [imagesx($imagem), imagesy($imagem)];
        $escala = min(1, self::LADO_MAXIMO / max($largura, $altura));

        if ($escala < 1) {
            $nova = imagecreatetruecolor((int) round($largura * $escala), (int) round($altura * $escala));
            imagecopyresampled($nova, $imagem, 0, 0, 0, 0, imagesx($nova), imagesy($nova), $largura, $altura);
            imagedestroy($imagem);
            $imagem = $nova;
        }

        // Fundo branco (PNG com transparência).
        $final = imagecreatetruecolor(imagesx($imagem), imagesy($imagem));
        imagefill($final, 0, 0, imagecolorallocate($final, 255, 255, 255));
        imagecopy($final, $imagem, 0, 0, 0, 0, imagesx($imagem), imagesy($imagem));
        imagedestroy($imagem);

        foreach ([85, 75, 65, 55, 45] as $qualidade) {
            ob_start();
            imagejpeg($final, null, $qualidade);
            $jpeg = (string) ob_get_clean();

            if (strlen($jpeg) <= self::TAMANHO_MAXIMO) {
                break;
            }
        }

        imagedestroy($final);

        return $jpeg;
    }

    /**
     * Fotos de celular guardam a rotação no EXIF; as leitoras não a leem.
     *
     * @param  \GdImage  $imagem
     * @return \GdImage
     */
    private static function corrigirOrientacao($imagem, string $conteudo)
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($conteudo, "\xFF\xD8")) {
            return $imagem;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($conteudo));
        $angulo = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angulo === 0 ? $imagem : (imagerotate($imagem, $angulo, 0) ?: $imagem);
    }
}
