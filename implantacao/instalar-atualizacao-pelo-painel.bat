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
REM   2. Cria a tarefa agendada do Windows "PortariaRemota-Atualizar",
REM      que roda implantacao\executar-atualizacao.bat com o SEU usuario.
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

REM Sem "/rl highest": a atualizacao nao precisa de administrador, e assim
REM o Apache (mesmo usuario, sem elevacao) consegue consultar e iniciar a tarefa.
REM O aviso "/ST e anterior a hora atual" e normal: a tarefa so roda pelo painel.
REM O registro (log) e feito pelo executar-atualizacao.bat: assim o /tr
REM nao tem ">" (o cmd interpretaria como redirecionamento DESTE script).
REM Na raiz do Agendador (sem pasta): criar pastas exige administrador.
REM Remove a tarefa da versao anterior, se existir (ignora erro).
schtasks /delete /f /tn "PortariaRemota\Atualizar" >nul 2>&1
schtasks /create /f /tn "PortariaRemota-Atualizar" /sc once /sd 01/01/2000 /st 00:00 /it /tr "\"%PROJETO%\implantacao\executar-atualizacao.bat\""
schtasks /query /tn "PortariaRemota-Atualizar" >nul 2>&1
if errorlevel 1 (
    echo.
    echo ERRO ao criar a tarefa. Rode pelo Terminal do Laragon NORMAL, sem "Executar como administrador",
    echo com o mesmo usuario que fica logado no Windows do servidor.
    exit /b 1
)

echo.
echo Pronto! O botao "Atualizar sistema" ja pode ser usado no painel
echo (Administracao ^> Atualizar sistema).
echo Observacao: a tarefa roda com o seu usuario, entao este usuario
echo precisa estar logado no Windows do servidor (como hoje, via RustDesk).
exit /b 0
