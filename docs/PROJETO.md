# Portaria Remota — Documento do Projeto

> **Status:** rascunho para revisão (v0.2, 25/11/2026)
> Nada é codado antes deste documento ser aprovado.

---

## 1. Objetivo

Plataforma de **portaria remota** para condomínios, que substitui ou complementa o porteiro humano:

- **Moradores** abrem portões e portas pelo celular ou pela web.
- A **central de atendimento** (sua equipe) recebe as chamadas dos interfones, vê as câmeras, abre os acessos e registra tudo.
- O sistema é **multi-condomínio e modular**: condomínios, portões, portas e dispositivos são cadastrados e removidos pelo painel, sem mexer em código.
- Cada condomínio funciona em um de dois modos:
  - **Direto:** o condomínio não tem servidor, e tudo roda no servidor central.
  - **Com servidor local:** o servidor do condomínio **toma as decisões**, funciona sem internet e sincroniza com o servidor central. Exige licença válida, verificada diariamente.

**Caminho comercial:** bancada → demonstração no seu condomínio → protótipo pronto para apresentar e vender.

---

## 2. Tecnologias

| Item | Escolha | Observação |
|---|---|---|
| Linguagem | PHP 8.3+ orientado a objetos | |
| Framework | Laravel 13 + Fortify (login/2FA) | login, filas, agendador, API |
| Banco | MySQL 8 | |
| Telas | Blade + Livewire 4 + Flux UI + Tailwind CSS | 100% responsivo, mantém tudo em PHP |
| Tempo real | Laravel Reverb (WebSocket) | status dos dispositivos, chamadas e eventos ao vivo |
| Permissões | spatie/laravel-permission | perfis por condomínio |
| Auditoria | spatie/laravel-activitylog | quem alterou cada cadastro |
| App | PWA, depois Capacitor (app nativo) se necessário | mesmo código |
| Câmeras | go2rtc | converte RTSP para WebRTC no navegador; roda nativo no Windows e no Linux |
| Interfone | Asterisk (PBX SIP) + softphone WebRTC no navegador | no Windows, roda via WSL2, Docker ou VM |
| VPN | WireGuard | condomínio ↔ central |
| Servidor | Windows (atual) e Linux | os dois são suportados |

---

## 3. Arquitetura

```
                 ┌──────────────────────────────────────────────┐
                 │            SERVIDOR CENTRAL                  │
                 │  Laravel (painel + API + central operadores) │
 Moradores ───►  │  MySQL · Reverb · Fila · Agendador           │  ◄─── Operadores
 (PWA/app)       │  go2rtc (câmeras) · Asterisk (interfone)     │       (navegador)
   HTTPS via     │  Licenças · Sincronização                    │
   Cloudflare    └───────────┬──────────────────────┬───────────┘
                     VPN WireGuard           HTTPS (sync + licença)
                             │                      │
          ┌──────────────────▼─────┐   ┌────────────▼──────────────────┐
          │ CONDOMÍNIO — DIRETO    │   │ CONDOMÍNIO — COM SERVIDOR LOCAL│
          │ relés, câmeras e       │   │ mesmo sistema em modo "local" │
          │ interfones acessados   │   │ decide sozinho, funciona sem  │
          │ pela central via VPN   │   │ internet, envia eventos e     │
          │ (ou MQTT de saída)     │   │ recebe cadastros da central   │
          └────────────────────────┘   └───────────────────────────────┘
```

### 3.1 Um sistema, dois modos
O **mesmo código** é instalado na central e no servidor local. A diferença é uma configuração:

```
APP_MODO=central   # servidor principal
APP_MODO=local     # servidor dentro do condomínio
```

### 3.2 Sobre "HTTP" e "HTTPS"
São dois trechos diferentes:

1. **Usuário ↔ seu site:** **HTTPS** pelo Cloudflare, criptografado. ✅
2. **Servidor ↔ relé:** a maioria dos relés (Shelly, Tasmota etc.) fala **HTTP simples** dentro da rede local. Esse tráfego passa **dentro da VPN**, que já é criptografada, então fica protegido.

O "driver HTTP genérico" é um driver em que você configura a URL de abrir e fechar de qualquer placa que aceite comandos pela web, com HTTP ou HTTPS.

### 3.3 Como alcançar dispositivos atrás do roteador
| Forma | Quando usar |
|---|---|
| **VPN WireGuard** (padrão) | Roteador ou mini dispositivo no condomínio conectando à central. Exige IP válido ou porta aberta na central |
| **MQTT de saída** | O próprio relé conecta na central (Shelly Gen2+, Tasmota). Não exige VPN, bom para locais simples |
| **Nuvem do fabricante** | Último recurso (Tuya, eWeLink). Depende da internet e do serviço do fabricante |

---

## 4. Dispositivos e drivers

Todo dispositivo é controlado por um **driver**. Cada driver é uma classe PHP que segue a mesma interface:

```php
interface RelayDriver
{
    public function pulse(int $canal, int $ms): Resultado;   // abre e fecha sozinho
    public function on(int $canal): Resultado;
    public function off(int $canal): Resultado;
    public function status(int $canal): Resultado;
    public static function campos(): array;                 // campos do formulário
}
```

No cadastro do dispositivo, um **combo box** lista os drivers. Ao escolher um, o formulário mostra os campos daquele modelo.

### 4.1 Drivers de relé (ordem de desenvolvimento)
| # | Driver | Comunicação | Observação |
|---|---|---|---|
| 1 | **HTTP genérico** | URL configurável (GET/POST, auth) | cobre muitas placas |
| 2 | **Shelly** Gen1 / Gen2 / Gen3 / Gen4 | HTTP local (RPC) + MQTT | **recomendado** pelo custo-benefício. Para portão, usar modelo de **contato seco** (Shelly 1 / Plus 1 / 1 Gen3) |
| 3 | **Tuya / Smart Life** | **local** (chave local; nuvem só para obter a chave uma vez) | bocal de teste. Guia: `docs/TUYA.md` |
| 4 | **eWeLink / Sonoff** | modo LAN + nuvem | |
| 5 | **Tasmota** (Sonoff reflashado e similares) | HTTP + MQTT | |
| 6 | **MQTT genérico** | tópico e payload configuráveis | |
| 7 | **Modbus TCP** | placas de 4/8/16 canais | painéis maiores |
| 8 | **ESP32/ESP8266 próprio** (firmware nosso) | HTTP + MQTT | opção mais barata em escala |
| — | Futuro: controladoras de acesso (Control iD, Intelbras, Hikvision) | API do fabricante | facial, tag, cartão |

### 4.2 Segurança física (regra de ouro)
- Abrir é sempre um **pulso** (ex.: 1 s). Quando o aparelho suporta, o **desligamento automático é configurado no próprio relé** (*auto-off* do Shelly). Se a rede cair no meio, o portão **não fica aberto**.
- Comandos repetidos em sequência são bloqueados por trava, o que evita abrir duas vezes.
- Todo comando aguarda a resposta do relé e registra **sucesso, falha ou tempo esgotado**.

### 4.3 Câmeras
- Cadastradas como dispositivo do tipo câmera: URL RTSP e/ou ONVIF, usuário e senha (guardada criptografada).
- O **go2rtc** converte para WebRTC, e o vídeo abre no painel e no app com baixa latência.
- Cada ponto de acesso pode ter **câmeras vinculadas**, que aparecem automaticamente quando o interfone daquele ponto toca.

### 4.4 Interfones
- **IP (SIP):** registram direto no Asterisk.
- **Analógicos:** entram por um **adaptador ATA (FXS)**, que os transforma em SIP.
- O operador atende **no navegador** (softphone WebRTC), sem programa instalado.
- **Fluxo de chamada:** o interfone toca → a tela do operador mostra o **condomínio, o ponto de acesso, as câmeras ao vivo e o botão ABRIR** → o operador fala, abre ou liga para o morador → tudo fica registrado.
- Futuro: transferir a chamada para o celular do morador (requer app nativo).

---

## 5. Perfis e permissões

| Perfil | Escopo | Pode |
|---|---|---|
| **Super admin** | todos | tudo: condomínios, licenças, drivers, usuários |
| **Operador da central** | condomínios atribuídos | atender chamadas, ver câmeras, abrir, registrar ocorrências |
| **Síndico / administrador** | seu condomínio | gerir blocos, unidades, moradores e pontos; ver histórico |
| **Porteiro local** (opcional) | seu condomínio | operar pela portaria física |
| **Morador** | sua unidade | abrir os pontos liberados, autorizar visitantes, ver o próprio histórico |

**Regras de segurança:**
- Senhas com hash (padrão do Laravel).
- **2FA obrigatório** para super admin e operadores.
- Limite de tentativas de login.
- Token por aparelho no app, que o administrador pode revogar.

---

## 6. Banco de dados (visão completa)

Todas as tabelas importantes têm `uuid`, porque é isso que permite sincronizar central ↔ local sem conflito de IDs.

### Estrutura do condomínio
- **condominios:** nome, CNPJ, endereço, `modo` (direto/local), status, fuso horário
- **blocos:** condomínio, nome (Torre A, Bloco 1...)
- **unidades:** bloco, número, andar
- **pessoas:** nome, documento, telefone, foto, tipo (morador, funcionário, prestador)
- **unidade_pessoa:** vínculo pessoa ↔ unidade (titular, dependente, inquilino)

### Usuários e acesso ao sistema
- **users:** login, senha, 2FA, pessoa vinculada (opcional)
- **condominio_user:** quais condomínios cada usuário enxerga
- **roles / permissions:** perfis (pacote spatie), com escopo por condomínio
- **dispositivos_app:** tokens dos celulares (revogáveis)

### Dispositivos e pontos de acesso
- **dispositivos:** condomínio, tipo (relé, câmera, interfone, ATA, controladora), **driver**, host, porta, credenciais (criptografadas), config (JSON), online/offline, último contato
- **pontos_acesso:** condomínio, nome, tipo (portão social, garagem, porta, eclusa), relé + canal, tempo de pulso, ativo, ordem
- **ponto_acesso_camera:** câmeras de cada ponto
- **intertravamentos:** regras de eclusa (só abre B se A estiver fechado). Fase posterior

### Quem pode abrir o quê
- **permissoes_acesso:** pessoa ou unidade ↔ ponto de acesso, horários permitidos, validade
- **visitas:** morador autoriza visitante (nome, documento, foto, período, QR code/token)

### Interfone e central
- **ramais:** número SIP, senha (criptografada), tipo (interfone, operador, morador), ponto de acesso vinculado
- **chamadas:** origem, atendente, início, atendimento, fim, status, gravação (opcional)
- **ocorrencias:** registro do operador (texto, anexos, categoria)

### Histórico (o coração da auditoria)
- **eventos_acesso:** condomínio, ponto, usuário/pessoa, **origem** (web, app, central, local, interfone, api), ação, **resultado** (sucesso, falha, tempo esgotado), detalhe, IP, data/hora, sincronizado
- **activity_log:** alterações de cadastro (quem mudou o quê)

### Servidor local, licença e sincronização
- **servidores_locais:** condomínio, nome, chave de API (hash), versão, IP na VPN, última sincronização
- **licencas:** condomínio, plano, válida até, última validação, status, assinatura digital
- **sync_outbox:** fila de alterações a enviar (tabela, uuid, operação, dados, enviado em)
- **configuracoes:** chave/valor por condomínio

---

## 7. Servidor local, sincronização e licença

### Sincronização
| Dado | Direção |
|---|---|
| Cadastros (blocos, unidades, pessoas, permissões, pontos, dispositivos) | central → local |
| Eventos de acesso, chamadas e ocorrências | local → central |
| Status dos dispositivos | local → central (tempo real quando houver internet) |

- O **servidor local sempre inicia a conexão** (HTTPS de saída), então o condomínio não precisa abrir porta.
- Sem internet, o local continua operando normalmente, acumula na fila e envia quando a conexão voltar.

### Licença
- O servidor local consulta a central **uma vez por dia**.
- A central devolve uma licença **assinada digitalmente** (não dá para falsificar editando o banco).
- **Tolerância offline:** 7 dias (configurável).
- **Licença vencida:** bloqueia as funções de gestão e mostra aviso. A **abertura de portões continua funcionando** (decidido).

---

## 8. Telas principais

**Painel web (responsivo):**
- Dashboard: condomínios, dispositivos online/offline, últimos eventos ao vivo
- Cadastros: condomínios, blocos, unidades, pessoas, usuários
- Dispositivos: cadastro com combo de driver, botão **"Testar comunicação"**
- Pontos de acesso: botão **ABRIR**, câmeras vinculadas
- Histórico de acessos com filtros e exportação
- Licenças e servidores locais (super admin)

**Central do operador:**
- Fila de chamadas, atendimento no navegador
- Pop-up com câmeras e botão abrir
- Ocorrências

**PWA do morador:**
- Botões grandes dos pontos liberados
- Autorizar visitante (gera QR/link)
- Meu histórico
- Ver câmeras liberadas

---

## 9. Etapas de desenvolvimento

| Etapa | Entrega | Testável em |
|---|---|---|
| **0. Fundação** ✅ | Projeto Laravel, login, 2FA, perfis, layout responsivo, multi-condomínio | navegador |
| **1. Cadastros** ✅ | condomínios, blocos, unidades, pessoas, usuários | navegador |
| **2. Relés + pontos de acesso** ✅ | drivers HTTP genérico, Shelly e Tuya; botão ABRIR; histórico | **bancada (bocal + Shelly)** |
| **3. Tempo real** | Reverb, status online/offline, eventos ao vivo, demais drivers de relé | bancada |
| **4. PWA do morador** | app instalável, abrir, visitantes, histórico | celular |
| **5. Câmeras** | go2rtc, câmeras no painel e no app | câmera IP de teste |
| **6. Interfone e central** | Asterisk, softphone no navegador, pop-up de chamada | ATA ou interfone IP de teste |
| 🎯 **Marco: demonstração** | apresentação no seu condomínio | |
| **7. Servidor local** | modo local, sincronização, licença, instaladores Windows e Linux | 2 máquinas |
| **8. VPN** | WireGuard gerenciado pelo painel | condomínio real |
| **9. Polimento comercial** | relatórios, backups, intertravamento (eclusa), marca | — |

Cada etapa termina com algo **funcionando de verdade** que você pode testar antes de seguir.

---

## 10. Decisões

| # | Assunto | Decisão |
|---|---|---|
| 1 | Bocal de teste | ✅ App **JWCOM SMART** (marca própria sobre a plataforma **Tuya**, também funciona no Smart Life) → **driver Tuya**. Testes no bocal da **Cozinha** |
| 2 | Licença vencida | ✅ abertura de portões **continua liberada**; bloqueia só a gestão |
| 3 | Nome comercial | ⏳ a definir (o sistema usa "Portaria Remota" provisoriamente, fácil de trocar) |
| 4 | Subdomínio | ✅ `portariaremota.meusistema.api.br` (a criar) |
| 5 | Ambiente de desenvolvimento | ✅ Laragon (Windows) |
