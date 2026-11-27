# Publicar em portariaremota.meusistema.api.br

Cenário: **servidor Windows** (o mesmo do TR Cargas) com **Apache** (Laragon ou XAMPP), publicado pelo **Cloudflare Tunnel**, na **mesma rede** dos equipamentos (bocal, Shelly).

```
Celular / navegador ──HTTPS──► Cloudflare ──Tunnel──► cloudflared (servidor) ──► Apache :80 ──► Portaria Remota
                                                                                                  │
                                                                         rede local ◄─────────────┘
                                                                         (bocal Tuya, Shelly…)
```

Os arquivos de apoio estão na pasta `implantacao/`.

---

## 0. Antes de tudo: versão do PHP ⚠️

O sistema precisa de **PHP 8.3 ou mais novo**. No terminal do Laragon (ou CMD do XAMPP):

```bash
php -v
```

- **8.3 ou 8.4:** siga em frente.
- **8.2 ou mais antigo:** **pare aqui e me avise.** Trocar a versão do PHP no Apache afeta **também o TR Cargas**. Há dois caminhos, e escolhemos juntos:
  1. Atualizar o PHP de todo o Apache. É o mais simples, mas o TR Cargas precisa ser testado depois.
  2. Usar um PHP separado só para a Portaria Remota (via *mod_fcgid*). Não mexe no TR Cargas, mas a configuração é um pouco maior.

Extensões necessárias no `php.ini`: `openssl`, `pdo_mysql`, `mbstring`, `intl`, `fileinfo`, `curl`, `zip`.
Também é preciso ter **Composer**, **Node.js 20+** e **Git** (o Laragon já traz os três).

## 1. Banco de dados

No HeidiSQL ou no phpMyAdmin, rode (troque a senha):

```sql
CREATE DATABASE portaria_remota CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'portaria'@'localhost' IDENTIFIED BY 'TROQUE-ESTA-SENHA';
GRANT ALL PRIVILEGES ON portaria_remota.* TO 'portaria'@'localhost';
FLUSH PRIVILEGES;
```

Um usuário próprio, em vez do `root`, isola o banco da Portaria do banco do TR Cargas.

## 2. Baixar o projeto

```bash
cd C:\laragon\www
git clone https://github.com/waguimfontourajf/PortariaRemota.git portariaremota
cd portariaremota
git checkout ccr-aad5b89c-y8p1k4
```

> Como o repositório é privado, o Git vai pedir login. Use seu usuário do GitHub e, como senha, um **token de acesso pessoal**: GitHub → Settings → Developer settings → Personal access tokens.

## 3. Configurar

```bash
copy implantacao\env.producao.exemplo .env
php artisan key:generate
```

Abra o `.env` e preencha `DB_PASSWORD` com a senha do passo 1.
**Nunca** deixe `APP_DEBUG=true` com o sistema publicado.

## 4. Instalar

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan db:seed --force
php artisan portaria:criar-admin
php artisan optimize
```

## 5. Apache

1. Copie `implantacao\apache-vhost.conf`:
   - **Laragon:** para `C:\laragon\etc\apache2\sites-enabled\portariaremota.conf`
   - **XAMPP:** cole o conteúdo no fim de `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
2. Ajuste o caminho do `DocumentRoot` se o projeto estiver em outra pasta. Ele **precisa** apontar para a pasta `public`.
3. Reinicie o Apache.

> Se o TR Cargas também usa um `<VirtualHost *:80>`, os dois convivem: o Apache escolhe pelo nome do site (`ServerName`).

## 6. Cloudflare Tunnel

No painel **Cloudflare Zero Trust → Networks → Tunnels**, abra o túnel que já atende o TR Cargas:

1. Aba **Public Hostname → Add a public hostname**
   - *Subdomain*: `portariaremota`
   - *Domain*: `meusistema.api.br`
   - *Type*: `HTTP`
   - *URL*: `localhost:80`
2. Salve. O Cloudflare cria o DNS sozinho.

> Se o seu túnel é configurado por arquivo `config.yml` (e não pelo painel), adicione **antes** da regra final `- service: http_status:404`:
> ```yaml
>   - hostname: portariaremota.meusistema.api.br
>     service: http://localhost:80
> ```
> Depois rode `cloudflared tunnel route dns <nome-do-tunel> portariaremota.meusistema.api.br` e reinicie o serviço do cloudflared.

Teste: **https://portariaremota.meusistema.api.br/up** deve mostrar uma página de "Application up".

## 7. Primeiro acesso

1. Entre com o super admin criado no passo 4.
2. O sistema exige **ativar o 2FA**. Use o Google Authenticator ou o Microsoft Authenticator.
3. Cadastre o condomínio de teste e, em seguida, o bocal (veja `docs/TUYA.md`).

### Recomendado durante os testes: Cloudflare Access

Enquanto o sistema está em testes, dá para colocar uma "porta antes da porta": só quem passar pelo login do Cloudflare chega ao sistema.

**Zero Trust → Access → Applications → Add an application → Self-hosted**
- *Domain*: `portariaremota.meusistema.api.br`
- *Policy*: Allow → *Emails* → o seu e-mail (e de quem mais for testar)

Quem acessar recebe um código por e-mail antes de ver a tela de login. Quando os moradores começarem a usar, revemos essa regra.

## 8. Atualizar depois (a cada etapa nova)

### Pelo painel (recomendado)

**Configuração única.** Abra o Terminal do Laragon **normal** (não precisa de administrador), com o usuário que fica logado no Windows do servidor, e, dentro da pasta do projeto, rode:

```bash
implantacao\instalar-atualizacao-pelo-painel.bat
```

Esse script cria a tarefa agendada do Windows **PortariaRemota-Atualizar** e guarda o caminho do php, composer, node e git.

Daí em diante, para atualizar: **Administração → Atualizar sistema → Atualizar agora**.
- Só aparece para o super admin e pede a senha de novo.
- A tela mostra o andamento ao vivo. O sistema fica em manutenção por 1 a 3 minutos.
- O painel **não executa nada diretamente**: ele só pede ao Windows para rodar a tarefa, que usa o seu usuário. Por isso esse usuário precisa estar **logado no Windows** do servidor.

### Pelo terminal

```bash
implantacao\atualizar.bat
```

As duas formas executam o mesmo script: baixam o código, instalam dependências, compilam as telas, atualizam o banco e tiram o sistema da manutenção. O registro fica em `storage\logs\atualizacao.log`.

## 9. Comandos Tuya no servidor

Como o servidor está na mesma rede do bocal, os comandos de `docs/TUYA.md` rodam **nele**:

```bash
php artisan portaria:tuya-procurar
php artisan portaria:tuya-chaves
```

Se o Windows perguntar sobre o firewall no `tuya-procurar`, **permita** o PHP em redes privadas.

---

## Problemas comuns

| Sintoma | O que verificar |
|---|---|
| Página do TR Cargas aparece no lugar da Portaria | `ServerName` do VirtualHost e se o Apache foi reiniciado |
| Erro 500 sem detalhes | `storage\logs\laravel-AAAA-MM-DD.log` |
| Links abrindo em `http://` | `APP_URL` com `https://` e `php artisan optimize` |
| Tela sem estilo | Rodar `npm run build` |
| "Bad gateway" no Cloudflare | Apache parado, ou URL do túnel diferente de `localhost:80` |
| Mudou o `.env` e nada aconteceu | Rodar `php artisan optimize` (o `.env` fica em cache) |
| Botão "Atualizar agora" não faz nada | Rodar de novo `implantacao\instalar-atualizacao-pelo-painel.bat` (terminal normal, mesmo usuário); ver `storage\logs\atualizacao.log` |
| Sistema ficou em "manutenção" | No terminal: `php artisan up` |
