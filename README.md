# Portaria Remota

Plataforma de portaria remota para condomínios: abertura de portões e portas, central de atendimento, câmeras, interfone e servidores locais com licença.

> 📄 O planejamento completo (arquitetura, banco de dados, etapas) está em [`docs/PROJETO.md`](docs/PROJETO.md).

**Tecnologias:** PHP 8.3+, Laravel 13, Livewire 4, Flux UI, Tailwind CSS, MySQL 8.

---

## Instalação no Windows com Laragon

### 1. Pré-requisitos

- **Laragon** com **PHP 8.3 ou mais novo**, **MySQL 8** e **Node.js 20+**.
  - Para conferir, abra o **Terminal** do Laragon e rode `php -v` e `node -v`.
  - Se o PHP for mais antigo: baixe o PHP 8.3/8.4 (*Thread Safe*, x64) em windows.php.net, extraia em `C:\laragon\bin\php\` e selecione em **Menu → PHP → Versão**.
- Extensões do PHP ativas (**Menu → PHP → Extensões**): `pdo_mysql`, `mbstring`, `openssl`, `intl`, `zip`, `fileinfo`, `curl`.
- **Git** (já vem no Laragon).

### 2. Baixar o projeto

No Terminal do Laragon:

```bash
cd C:\laragon\www
git clone https://github.com/waguimfontourajf/PortariaRemota.git portariaremota
cd portariaremota
```

### 3. Instalar dependências

```bash
composer install
npm install
npm run build
```

### 4. Configurar o `.env`

```bash
copy .env.example .env
php artisan key:generate
```

Abra o `.env` e confira:

```ini
APP_URL=http://portariaremota.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=portaria_remota
DB_USERNAME=root
DB_PASSWORD=

PORTARIA_MODO=central
PORTARIA_EXIGIR_2FA=true

# Primeiro super admin (opcional: também dá para criar pelo comando do passo 6)
PORTARIA_ADMIN_NOME="Seu Nome"
PORTARIA_ADMIN_EMAIL=seu@email.com.br
PORTARIA_ADMIN_SENHA=uma-senha-forte
```

### 5. Criar o banco

No Laragon: **Menu → MySQL → Criar banco de dados** → `portaria_remota`
(ou pelo HeidiSQL, com o charset `utf8mb4_unicode_ci`).

```bash
php artisan migrate --seed
```

O `--seed` cria os perfis de acesso, o super admin (se configurado no `.env`) e um **Condomínio Demonstração**.

### 6. Criar o super admin (se não configurou no `.env`)

```bash
php artisan portaria:criar-admin
```

### 7. Acessar

Clique em **Recarregar** no Laragon e abra **http://portariaremota.test**.

No primeiro login, o super admin é levado para **Configurações → Segurança** para **ativar a verificação em duas etapas (2FA)**. Use um aplicativo autenticador no celular (Google Authenticator, Microsoft Authenticator, Authy…).

---

## Comandos úteis

| Comando | Para quê |
|---|---|
| `composer dev` | Ambiente de desenvolvimento (servidor, fila, logs e Vite juntos) |
| `npm run build` | Gera os arquivos de tela (CSS/JS) |
| `php artisan test` | Roda os testes automáticos |
| `composer lint` | Formata o código no padrão do projeto |
| `php artisan portaria:criar-admin` | Cria ou promove um super admin |
| `php artisan db:seed --class=PerfisSeeder` | Atualiza perfis e permissões |
| `php artisan portaria:dispositivo-testar` | Testa um dispositivo (use `--detalhes` para diagnóstico) |
| `php artisan portaria:tuya-procurar` | Encontra aparelhos Tuya na rede (IP, ID, versão) |
| `php artisan portaria:tuya-chaves` | Busca as chaves locais dos aparelhos Tuya (uma vez) |
| `php artisan portaria:leitoras-sincronizar` | Envia as pessoas (foto + autorização) para as leitoras faciais |
| `php artisan portaria:leitoras-importar` | Traz para o Histórico as passagens registradas pelas leitoras |

## Perfis de acesso

Os perfis valem **por condomínio**: a mesma pessoa pode ser síndica em um condomínio e moradora em outro.

| Perfil | Pode |
|---|---|
| **Super admin** | Tudo, em todos os condomínios |
| **Operador da central** | Atender chamadas, ver câmeras, abrir, registrar ocorrências (exige 2FA) |
| **Síndico / administrador** | Gerenciar cadastros e usuários do condomínio, ver histórico |
| **Porteiro local** | Operar pela portaria física |
| **Morador** | Abrir os acessos liberados, autorizar visitantes, ver o próprio histórico |

As permissões de cada perfil ficam em `app/Enums/Perfil.php`.

## Estrutura do código (principais pontos)

| Caminho | O que é |
|---|---|
| `app/Enums/` | Perfis, permissões e modos de operação |
| `app/Models/` | Condomínio, bloco, unidade, pessoa, usuário |
| `resources/views/pages/` | Telas (componentes Livewire): condomínios, cadastros, usuários |
| `app/Support/RegrasDeUsuario.php` | Quem pode alterar o quê na gestão de usuários |
| `app/Support/Documento.php` | Validação/formatação de CPF, CNPJ, telefone e CEP |
| `app/Support/CondominioAtual.php` | Controla qual condomínio o usuário está operando |
| `app/Http/Middleware/` | Condomínio atual, 2FA obrigatório e bloqueio de usuário inativo |
| `config/portaria.php` | Configurações próprias do sistema |
| `lang/pt_BR*` | Traduções |
| `app/Dispositivos/` | Drivers de relé (Shelly, Tuya, Modbus, HTTP genérico, simulador), leitoras faciais (Control iD, Intelbras, Hikvision) e protocolos Tuya/Modbus |
| `app/Acoes/AcionarPontoDeAcesso.php` | Único caminho para abrir um portão (permissão, trava, histórico) |
| `docs/PROJETO.md` | Documento do projeto |
| `docs/TUYA.md` | Passo a passo para aparelhos Tuya / Smart Life |
| `docs/MODBUS.md` | Módulos de relé Modbus com porta de rede (eletechsup e similares) |
| `docs/LEITORAS.md` | Leitoras de reconhecimento facial: foto, LGPD e sincronização |
