@echo off
REM ==========================================================
REM  Portaria Remota - instala/atualiza no servidor Windows
REM
REM  Pelo terminal (Laragon/Cmder), dentro da pasta do projeto:
REM      implantacao\atualizar.bat
REM
REM  Ou pelo painel: Administracao > Atualizar sistema
REM  (requer implantacao\instalar-atualizacao-pelo-painel.bat)
REM ==========================================================
setlocal
REM Pelo painel, o executar-atualizacao.bat roda uma COPIA deste arquivo
REM (o git pull pode alterar o original no meio) e informa a pasta.
if not defined PORTARIA_DIR set "PORTARIA_DIR=%~dp0.."
cd /d "%PORTARIA_DIR%"

REM PATH salvo pelo instalador (php, composer, node e git do Laragon),
REM usado quando a atualizacao e disparada pelo painel.
if exist "implantacao\ambiente.local.bat" call "implantacao\ambiente.local.bat"

set "ESTADO_DIR=storage\app\atualizacao"
if not exist "%ESTADO_DIR%" mkdir "%ESTADO_DIR%"
> "%ESTADO_DIR%\estado.txt" echo executando

echo Inicio: %date% %time%
echo.
echo [1/7] Verificando a versao do PHP (precisa ser 8.3 ou mais nova)...
php -r "exit(version_compare(PHP_VERSION, '8.3.0', '>=') ? 0 : 1);"
if errorlevel 1 (
    php -v
    echo ERRO: o PHP precisa ser 8.3 ou mais novo. Veja docs\IMPLANTACAO.md
    goto erro_sem_manutencao
)

echo [2/7] Baixando a versao mais recente do codigo...
git pull --ff-only || goto erro_sem_manutencao

echo [3/7] Modo manutencao...
if exist vendor\autoload.php php artisan down --retry=15

echo [4/7] Dependencias PHP...
call composer install --no-dev --optimize-autoloader --no-interaction --no-progress || goto erro

echo [5/7] Telas (CSS/JS)...
call npm ci --no-audit --no-fund || goto erro
call npm run build || goto erro

echo [6/7] Banco de dados...
php artisan migrate --force || goto erro
php artisan db:seed --class=PerfisSeeder --force || goto erro

echo [7/7] Otimizando...
php artisan optimize || goto erro
php artisan up

git log -1 --format="%%h|%%cI|%%s" > "%ESTADO_DIR%\versao.txt"
> "%ESTADO_DIR%\estado.txt" echo sucesso
echo.
echo Fim: %date% %time%
echo Pronto! Sistema atualizado.
exit /b 0

:erro
echo.
echo ERRO durante a atualizacao. Tirando o sistema da manutencao para que
echo a tela de atualizacao mostre este registro. Veja a mensagem acima.
php artisan up

:erro_sem_manutencao
> "%ESTADO_DIR%\estado.txt" echo erro
echo Fim com erro: %date% %time%
exit /b 1
