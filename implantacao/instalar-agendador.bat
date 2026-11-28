@echo off
REM ==========================================================
REM  Liga as tarefas automaticas do sistema (monitoramento da rede,
REM  alertas do Telegram...). Rode UMA VEZ, pelo Terminal do Laragon
REM  NORMAL (sem "Executar como administrador"), dentro da pasta do projeto:
REM      implantacao\instalar-agendador.bat
REM
REM  Cria a tarefa do Windows "PortariaRemota-Agendador", que roda
REM  "php artisan schedule:run" a cada minuto, escondida (sem janela),
REM  com o SEU usuario. Esse usuario precisa ficar logado no servidor.
REM ==========================================================
setlocal
cd /d "%~dp0.."
set "PROJETO=%cd%"

where php >nul 2>&1 || (echo ERRO: php nao encontrado. Rode pelo Terminal do Laragon. & exit /b 1)

> "%~dp0ambiente.local.bat" echo @set "PATH=%PATH%"
echo PATH salvo em implantacao\ambiente.local.bat

schtasks /create /f /tn "PortariaRemota-Agendador" /sc minute /mo 1 /it /tr "wscript.exe \"%PROJETO%\implantacao\agendador-oculto.vbs\""
schtasks /query /tn "PortariaRemota-Agendador" >nul 2>&1
if errorlevel 1 (
    echo.
    echo ERRO ao criar a tarefa. Rode pelo Terminal do Laragon NORMAL, com o
    echo mesmo usuario que fica logado no Windows do servidor.
    exit /b 1
)

echo.
echo Testando o monitoramento agora...
php artisan portaria:monitorar

echo.
echo Pronto! O monitoramento roda sozinho a cada minuto.
echo Registro da ultima execucao: storage\logs\agendador.log
exit /b 0
