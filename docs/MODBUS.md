# Módulos de relé Modbus pela rede (eletechsup e similares)

O driver **"Relé Modbus TCP (eletechsup e similares)"** controla módulos de relé com porta de rede (RJ45) que usam o protocolo **Modbus**, por exemplo:

- **eletechsup ETR0A01** (1 relé + 1 entrada) e **ETR0B02** (2 relés + 2 entradas);
- outros módulos eletechsup com Ethernet (ET48A08...);
- módulos de outras marcas que seguem o padrão Modbus de bobinas (coils), como os Waveshare "Modbus POE ETH Relay".

Não depende de internet nem de nuvem: o servidor fala direto com o módulo pela rede local.

> **Atenção:** este driver foi desenvolvido a partir da documentação pública da eletechsup e testado com um módulo **simulado**. No primeiro módulo real, faça os testes do item 4 antes de colocar em uso.

---

## 1. Ligação elétrica

| Borne | Ligação |
|---|---|
| **VIN / GND** | Fonte **12 V ou 24 V DC** (pode ser a mesma fonte com bateria da central do portão) |
| **COM1 + NO1** | Em **paralelo com a botoeira** da central do portão (contato seco) |
| **IN1 / IN2 + GND** | Opcional: sensor de portão aberto/fechado (contato seco) |
| **RJ45** | Cabo de rede até o switch/roteador |

O relé aceita **10 A em 250 VAC ou 30 VDC**, então serve para a entrada de botoeira (baixa tensão DC) das centrais de portão.

## 2. Chaves (DIP) do módulo

- **A0 a A5**: endereço (Slave ID). Anote o valor para usar no cadastro (campo **Slave ID**). Na dúvida, deixe o padrão de fábrica e use **1**.
- **SW2**: deixe **DESLIGADA**. Com ela ligada (junto com a SW1), o módulo só repassa dados e **não aceita comandos**.

## 3. Descobrir o IP do módulo

O IP depende do modelo e da configuração de fábrica. Para descobrir:

1. Na lista de aparelhos conectados do **roteador** (DHCP), procure o aparelho novo que apareceu ao ligar o módulo; ou
2. Use a ferramenta de configuração indicada pelo vendedor (normalmente um programa de Windows que procura o módulo na rede e permite trocar o IP).

Depois, **reserve o IP** no roteador (DHCP reservado pelo MAC) ou configure um IP fixo no módulo.

## 4. Cadastro e testes

Em **Equipamentos → Dispositivos → Novo**:

| Campo | Valor |
|---|---|
| Modelo / driver | **Relé Modbus TCP (eletechsup e similares)** |
| Endereço (IP) | IP do módulo |
| Porta | vazio (usa **502**) |
| Quantidade de canais | 1 (ETR0A01) ou 2 (ETR0B02) |
| Tipo de comando | **eletechsup** |
| Formato | **Modbus TCP** |
| Slave ID | conforme as chaves A0–A5 (na dúvida, 1) |
| Endereço do canal 1 | vazio |

Na seção **Testar**:

1. **Testar comunicação**: deve mostrar o estado de cada canal (e das entradas, se o módulo informar).
2. **Ligar** e **Desligar**: o LED do relé deve acender e apagar, e a tela deve dizer "(confirmado pelo módulo)".
3. **Pulso 1 s**: o relé liga e **o próprio módulo desliga** depois de 1 segundo. Se a rede cair no meio, ele desliga mesmo assim.

### Se der erro

Abra **"Detalhes técnicos"** abaixo do resultado: ali aparecem os comandos enviados e as respostas do módulo.

| Mensagem | O que tentar |
|---|---|
| Não foi possível conectar | Confira o IP, a porta (502) e se o servidor está na mesma rede |
| Não respondeu a tempo / encerrou a conexão | Troque o **Formato** (Modbus TCP ↔ RTU sobre TCP) e confira o **Slave ID** |
| Endereço não existe no módulo | Confira o **canal** e o **Endereço do canal 1** (eletechsup = 1) |
| Função não suportada | Troque o **Tipo de comando** para "Padrão Modbus (bobinas)" |
| Recebeu o comando, mas o canal continua... | Troque o **Tipo de comando** |

## 5. Segurança

O Modbus **não tem senha**: qualquer aparelho na mesma rede consegue acionar o relé. Recomendações:

- deixe o módulo numa rede (ou faixa de IP/VLAN) separada da rede dos moradores e do Wi-Fi de visitantes;
- não libere a porta 502 no roteador para a internet.
