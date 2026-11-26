@echo off
REM ==========================================================
REM  Portaria Remota - instala/atualiza no servidor Windows
REM  Execute pelo Terminal do Laragon (ou CMD com php, composer,
REM  node e git no PATH), dentro da pasta do projeto:
REM      implantacao\atualizar.bat
REM ==========================================================
setlocal
cd /d "%~dp0.."

echo.
echo [1/7] Verificando a versao do PHP (precisa ser 8.3 ou mais nova)...
php -r "exit(version_compare(PHP_VERSION, '8.3.0', '>=') ? 0 : 1);"
if errorlevel 1 (
    php -v
    echo ERRO: o PHP precisa ser 8.3 ou mais novo. Veja docs\IMPLANTACAO.md
    exit /b 1
)

echo [2/7] Baixando a versao mais recente do codigo...
git pull --ff-only || exit /b 1

echo [3/7] Modo manutencao...
if exist vendor\autoload.php php artisan down --retry=15 >nul 2>&1

echo [4/7] Dependencias PHP...
call composer install --no-dev --optimize-autoloader --no-interaction || goto erro

echo [5/7] Telas (CSS/JS)...
call npm ci --no-audit --no-fund || goto erro
call npm run build || goto erro

echo [6/7] Banco de dados...
php artisan migrate --force || goto erro
php artisan db:seed --class=PerfisSeeder --force || goto erro

echo [7/7] Otimizando...
php artisan optimize || goto erro
php artisan up

echo.
echo Pronto! Sistema atualizado.
exit /b 0

:erro
echo.
echo ERRO durante a atualizacao. O sistema continua em manutencao.
echo Corrija o problema e rode este script de novo (ou "php artisan up").
exit /b 1
