# Monitoramento e mapa da rede

O sistema testa cada equipamento **a cada minuto** e mostra tudo em um mapa, no estilo do The Dude. Quando um equipamento cai ou volta, avisa pelo **Telegram**.

## 1. Ligar o agendador (uma vez, no servidor)

No **Terminal do Laragon normal**, sem "Executar como administrador", dentro da pasta do projeto:

```bash
implantacao\instalar-agendador.bat
```

O script cria a tarefa do Windows **PortariaRemota-Agendador**. Ela roda escondida, sem abrir janela, a cada minuto.

> A tarefa roda com o seu usuário, então ele precisa ficar **logado** no Windows do servidor, como já acontece hoje via RustDesk.
> O registro da última execução fica em `storage\logs\agendador.log`.

## 2. Configurar os alertas do Telegram

**Administração → Alertas (Telegram)**. O passo a passo também aparece na própria tela:

1. No Telegram, fale com o **@BotFather**, envie `/newbot` e copie o **token**.
2. Crie um **grupo** para a equipe, **adicione o bot** e mande qualquer mensagem no grupo.
3. Na tela, cole o token, clique em **Procurar conversas**, escolha o grupo, clique em **Enviar teste** e depois em **Salvar**.

Cada condomínio pode ter também o **seu próprio grupo**, por exemplo com o síndico. Para isso: **Todos os condomínios → editar → "Grupo do Telegram"**. Os alertas daquele condomínio vão para os dois grupos.

Exemplo de alerta:

```
🔴 Roteador principal fora do ar
🏢 Residencial Jardim das Flores
📍 Roteador (192.168.0.1)
🕒 desde 28/11 10:00

🟢 Roteador principal voltou
⏱ ficou fora por 7 min
```

## 3. Montar o mapa

**Equipamentos → Mapa da rede**

- **Adicionar:** escolha o tipo (roteador, switch, access point, câmera, servidor…) e informe nome e IP.
- **Arrastar:** posicione os ícones como quiser. A posição fica salva.
- **Ligar equipamentos:** clique no primeiro, depois no segundo, e escolha o tipo de ligação:
  - **Cabo** — linha contínua
  - **Wireless** — linha tracejada
  - **Fibra** — linha azul grossa
  - **VPN** — linha pontilhada roxa

  Dá para informar uma descrição, como "porta 3" ou "SSID Portaria".
- **Clique em uma linha** para mudar o tipo ou remover a ligação.
- **Clique em um equipamento** para ver: status, IP, tempo de resposta, disponibilidade (24 h, 7 e 30 dias) e as últimas quedas. Ali também ficam os botões **Testar agora** e **Editar**.

A tela se atualiza sozinha a cada 15 segundos.

### Cores

| Cor | Significado |
|---|---|
| 🟢 Verde | Online |
| 🟡 Amarelo | Lento: respondeu, mas acima de 500 ms |
| 🔴 Vermelho (piscando) | Offline: 2 testes seguidos sem resposta |
| ⚪ Cinza | Não monitorado ou aguardando o primeiro teste |

### Mapa geral

**Operação → Mapa geral** (super admin e operadores da central) mostra a **Central** ligada a todos os condomínios:

| Cor do condomínio | Significado |
|---|---|
| 🟢 Verde | Tudo online |
| 🟡 Amarelo | Algum equipamento fora ou lento |
| 🔴 Vermelho | Tudo fora (o condomínio perdeu a comunicação) |

Clique em um condomínio para ver o que está fora e abrir o mapa dele.

## 4. Como cada equipamento é testado

No cadastro do dispositivo, em **Monitoramento**:

| Método | Quando usar |
|---|---|
| **Ping** (padrão) | Quase tudo: roteadores, switches, câmeras, computadores |
| **Porta TCP** | Equipamentos que bloqueiam ping. Ex.: 80 (web), 554 (câmera), 6668 (Tuya), 8291 (MikroTik) |
| **Pelo driver** | Relés e leitoras: faz o mesmo "Testar comunicação" da tela do dispositivo |

Ajustes no `.env`, se precisar:

```ini
PORTARIA_MONITOR_TIMEOUT_MS=1500   # espera de cada teste
PORTARIA_MONITOR_LENTO_MS=500      # acima disso: amarelo
PORTARIA_MONITOR_FALHAS=2          # falhas seguidas para virar offline
```

## Limitações (por enquanto)

- **Cabo ou wireless é informado por você.** A detecção automática exige SNMP ou a API da MikroTik; pode vir depois.
- **O servidor só monitora o que ele alcança na rede.** Hoje é a rede onde ele está. Os outros condomínios entram com a VPN (Etapa 8) ou com um servidor local em cada um.
