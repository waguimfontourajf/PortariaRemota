# Interfone e central de atendimento

Quando um interfone toca, a chamada vai **primeiro para a central**. Em qualquer tela do sistema, os operadores veem um **pop-up** com:

- o condomínio e o portão;
- a **câmera ao vivo** do portão;
- os botões **Atender**, **ABRIR** e **Encerrar** (ou **Recusar**).

O primeiro operador que atende fica com a chamada, e o pop-up some para os outros. Tudo fica no **histórico de chamadas**: quem atendeu, quanto tempo durou, se abriu o portão e a observação digitada.

A tela **Central** (menu Operação) mostra o estado do telefone, os números do dia, os interfones e o histórico.

Há dois jeitos de usar a central:

| Modo | Precisa de | Conversa por áudio |
|---|---|---|
| **A. Campainha (sem áudio)** | só o sistema | não: o operador vê a câmera e decide |
| **B. Interfone com áudio** | um **Asterisk** (PABX gratuito) | sim, pelo navegador, com fone de ouvido |

---

## Cadastro (vale para os dois modos)

1. **Equipamentos → Dispositivos → Novo**: tipo **Interfone**, nome (ex.: *Interfone portão social*) e **ramal** (ex.: `2001`). A **senha SIP** é criada sozinha.
2. **Equipamentos → Pontos de acesso** → editar o portão → em **Interfone deste portão**, escolha o interfone. Marque também as **câmeras do portão**.
3. Quem atende: os perfis **Operador** e **Porteiro** (e o super admin). Cada um atende só os condomínios em que tem esse perfil.

> Para treinar a equipe sem o aparelho: **Central → Testar sem o aparelho → Tocar** (só super admin).

## A. Campainha (sem áudio)

Serve para um botão simples no portão. Pode ser um **Shelly i3/i4**, um interfone que chama uma URL quando alguém aperta o botão, etc.

1. Abra o interfone em **Dispositivos** → **Gerar link da campainha** e copie o link. Ele só aparece uma vez.
2. Configure o botão para chamar esse link quando for apertado. No Shelly: **Actions → Button short press → URL**.

Apertar o botão várias vezes seguidas gera uma chamada só.

## B. Interfone com áudio (Asterisk)

```
Interfone IP ──SIP──► Asterisk ──avisa (HTTP)──► Portaria Remota
                         │                              │
                         └──WebSocket/WebRTC──► navegador do operador (pop-up + áudio)
```

O Asterisk **não roda no Windows**. Escolha onde instalar:

| Opção | Quando usar |
|---|---|
| **Mini PC ou Raspberry Pi com Linux** (recomendado) | ligado na rede do condomínio ou da central; é o mais estável |
| **WSL2 (Ubuntu) no servidor Windows** | para testes na bancada; use o modo de rede *mirrored* do WSL |

> Na Etapa 7 (servidor local), o Asterisk passa a ser instalado junto com o servidor local de cada condomínio.

### 1. Instalar o Asterisk (Ubuntu/Debian)

```bash
sudo apt update && sudo apt install -y asterisk
```

### 2. Configurar pelo painel

**Administração → Central telefônica**:

- **Endereço WebSocket:** `wss://voip-portariaremota.meusistema.api.br/ws` (veja o passo 4).
- **Endereço do sistema visto pelo Asterisk:** normalmente `https://portariaremota.meusistema.api.br`.
- **Gerar arquivos**: copie cada um para `/etc/asterisk/`:

| Arquivo gerado | Onde colocar |
|---|---|
| `pjsip_portaria.conf` | substitui `/etc/asterisk/pjsip.conf` |
| `extensions_portaria.conf` | substitui `/etc/asterisk/extensions.conf` |
| bloco *http.conf* | `/etc/asterisk/http.conf` |
| bloco *rtp.conf* | `/etc/asterisk/rtp.conf` |
| linha *noload => chan_sip.so* | dentro de `[modules]` em `/etc/asterisk/modules.conf` |

Depois: `sudo systemctl restart asterisk`.

> ⚠️ **Gere os arquivos de novo** sempre que cadastrar um interfone ou um operador novo, e reinicie (ou `asterisk -rx "core reload"`). Os arquivos têm as **senhas dos ramais** e o **token**: não compartilhe.

### 3. Configurar o interfone Intelbras (ou outro SIP)

Na página de configuração do interfone, procure **SIP / Conta SIP** e preencha:

| Campo | Valor |
|---|---|
| Servidor / proxy SIP | IP do computador do Asterisk |
| Porta | `5060` (UDP) |
| Usuário / ramal / ID | o **ramal** do cadastro (ex.: `2001`) |
| Senha | a **senha SIP**. Ela aparece no `pjsip_portaria.conf` gerado, na linha `password=` logo abaixo do ramal |
| Número a discar no botão | `1000` (a central) |

Se o interfone abre a fechadura dele mesmo por um código (DTMF), informe esse código no cadastro do interfone, no campo **"Código para abrir pelo interfone"**. A central passa a mostrar o botão **Abrir pelo interfone** durante a conversa.

### 4. Publicar o WebSocket (Cloudflare Tunnel)

No túnel que já atende o sistema, adicione mais um *Public Hostname*:

- *Subdomain*: `voip-portariaremota` · *Domain*: `meusistema.api.br`
- *Type*: `HTTP` · *URL*: `IP-do-Asterisk:8088`

O Cloudflare cuida do HTTPS, e o navegador conecta em `wss://voip-portariaremota.meusistema.api.br/ws`.

### 5. Testar

1. Entre como operador e abra **Central**. O telefone deve mostrar 🟢 **"Pronto para atender"**.
2. Aperte o botão do interfone. O pop-up aparece e toca.
3. Clique em **Atender**. Na primeira vez, o navegador pede permissão para o **microfone**.

## Dicas para a equipe

- Use **fone de ouvido com microfone**.
- Clique uma vez em qualquer lugar da página depois de abrir o sistema: o navegador só libera o **som do toque** depois de um clique.
- Em **Central**, ative **"Avisar quando o navegador estiver em segundo plano"** para receber o aviso mesmo usando outro programa.
- A chamada que ninguém atende em 90 segundos vira **"não atendida"**.

## Limitações atuais

- **Áudio fora da rede:** um operador fora da rede do Asterisk (em casa, no 4G) pode não ter áudio, porque falta um servidor TURN. Isso será resolvido com a **VPN (Etapa 8)** ou com um TURN. A chamada, a câmera e o ABRIR funcionam de qualquer lugar.
- **Repasse para o morador:** transferir a chamada para o app do morador é um próximo passo.
