@echo off
REM ==========================================================
REM  Executado pela tarefa agendada "PortariaRemota-Atualizar"
REM  (botao "Atualizar sistema" do painel). Nao precisa rodar a mao.
REM
REM  Roda uma COPIA do atualizar.bat: o git pull pode alterar o
REM  original enquanto ele executa, e o Windows le arquivos .bat
REM  aos poucos. O registro vai para storage\logs\atualizacao.log.
REM ==========================================================
setlocal
cd /d "%~dp0.."
set "PORTARIA_DIR=%cd%"
if not exist "storage\logs" mkdir "storage\logs"
copy /y "implantacao\atualizar.bat" "%TEMP%\portariaremota-atualizar.bat" >nul
call "%TEMP%\portariaremota-atualizar.bat" > "storage\logs\atualizacao.log" 2>&1
exit /b %errorlevel%
