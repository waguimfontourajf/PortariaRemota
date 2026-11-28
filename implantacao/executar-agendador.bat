@echo off
REM ==========================================================
REM  Executado a cada minuto pela tarefa "PortariaRemota-Agendador"
REM  (via agendador-oculto.vbs, sem abrir janela). Nao precisa rodar a mao.
REM  Roda as tarefas periodicas do sistema, como o monitoramento da rede.
REM ==========================================================
setlocal
cd /d "%~dp0.."
if exist "%~dp0ambiente.local.bat" call "%~dp0ambiente.local.bat"
if not exist "storage\logs" mkdir "storage\logs"
php artisan schedule:run > "storage\logs\agendador.log" 2>&1
exit /b %errorlevel%
