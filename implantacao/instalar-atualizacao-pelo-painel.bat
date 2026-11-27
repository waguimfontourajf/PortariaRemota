@echo off
REM ==========================================================
REM  Habilita o botao "Atualizar sistema" do painel.
REM
REM  Rode UMA VEZ, pelo Terminal do Laragon/Cmder (onde php,
REM  composer, node e git funcionam), dentro da pasta do projeto:
REM      implantacao\instalar-atualizacao-pelo-painel.bat
REM
REM  O que faz:
REM   1. Salva o PATH atual em implantacao\ambiente.local.bat, para
REM      a atualizacao encontrar php/composer/node/git.
REM   2. Cria a tarefa agendada do Windows "PortariaRemota\Atualizar",
REM      que roda implantacao\atualizar.bat com o SEU usuario.
REM      O painel apenas pede ao Windows para executar essa tarefa.
REM ==========================================================
setlocal
cd /d "%~dp0.."
set "PROJETO=%cd%"

where php >nul 2>&1 || (echo ERRO: php nao encontrado. Rode pelo Terminal do Laragon. & exit /b 1)
where git >nul 2>&1 || (echo ERRO: git nao encontrado. Rode pelo Terminal do Laragon. & exit /b 1)
where composer >nul 2>&1 || (echo ERRO: composer nao encontrado. Rode pelo Terminal do Laragon. & exit /b 1)
where npm >nul 2>&1 || (echo ERRO: npm nao encontrado. Rode pelo Terminal do Laragon. & exit /b 1)

> "%~dp0ambiente.local.bat" echo @set "PATH=%PATH%"
echo PATH salvo em implantacao\ambiente.local.bat

if not exist "storage\app\atualizacao" mkdir "storage\app\atualizacao"
git log -1 --format="%%h|%%cI|%%s" > "storage\app\atualizacao\versao.txt"

schtasks /create /f /tn "PortariaRemota\Atualizar" /sc once /sd 01/01/2000 /st 00:00 /it /rl highest ^
  /tr "cmd /c \"\"%PROJETO%\implantacao\atualizar.bat\" > \"%PROJETO%\storage\logs\atualizacao.log\" 2>&1\""
if errorlevel 1 (
    echo.
    echo ERRO ao criar a tarefa. Abra o Terminal do Laragon como ADMINISTRADOR e rode de novo.
    exit /b 1
)

echo.
echo Pronto! O botao "Atualizar sistema" ja pode ser usado no painel
echo (Administracao ^> Atualizar sistema).
echo Observacao: a tarefa roda com o seu usuario, entao este usuario
echo precisa estar logado no Windows do servidor (como hoje, via RustDesk).
exit /b 0
