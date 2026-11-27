# Aparelhos Tuya / Smart Life pela rede local

O driver **"Tuya / Smart Life (rede local)"** controla aparelhos Tuya (e marcas que usam a plataforma, como **JWCOM SMART**, Positivo Casa Inteligente e Nova Digital) **direto pela rede local, sem internet**.

Para isso, cada aparelho precisa de 3 informações:

| Informação | Como obter |
|---|---|
| **ID do aparelho** e **chave local** (`local_key`) | Uma única vez, pela nuvem da Tuya (passos 1 a 4) |
| **IP** e **versão do protocolo** | Na rede local (passo 5) |

> Depois de obter a chave, a nuvem não é mais usada. A chave **só muda** se o aparelho for removido do app e pareado de novo.

---

## 1. Usar o app Smart Life

A Tuya só libera as chaves de aparelhos cadastrados nos apps **Smart Life** ou **Tuya Smart**. Apps de marca própria, como o JWCOM SMART, normalmente **não** podem ser vinculados.

1. Instale o **Smart Life** no celular e crie uma conta.
2. No JWCOM SMART, **remova** o bocal.
3. No Smart Life, **adicione** o bocal (o mesmo processo de pareamento de sempre).
4. Confirme que você liga e desliga o bocal pelo Smart Life.

## 2. Criar o projeto na Tuya (gratuito)

1. Acesse **https://platform.tuya.com** e crie uma conta de desenvolvedor.
2. Vá em **Cloud → Development → Create Cloud Project**:
   - *Project Name*: Portaria Remota
   - *Industry*: Smart Home
   - *Development Method*: Smart Home
   - *Data Center*: **Western America Data Center** (contas do Brasil)
3. Na tela seguinte, mantenha os serviços sugeridos (**IoT Core** e **Authorization Token Management**) e confirme.
4. Na aba **Overview** do projeto, anote o **Access ID/Client ID** e o **Access Secret/Client Secret**.

## 3. Vincular o app ao projeto

1. No projeto: **Devices → Link App Account → Add App Account**. Vai aparecer um QR code.
2. No Smart Life: aba **Eu / Me**, ícone de **leitor de QR** no canto superior → leia o QR code.
3. Confirme. O bocal deve aparecer na lista de dispositivos do projeto.

## 4. Buscar as chaves

No terminal do Laragon, dentro da pasta do projeto:

```bash
php artisan portaria:tuya-chaves
```

Informe o Access ID, o Access Secret e a região (**us**). O sistema mostra uma tabela com **nome, ID e chave local** de cada aparelho. Guarde em local seguro.

> Se aparecer o erro **28841105** ou **2009**, a região está errada. Tente `ueaz` ou `eu`.
> Se aparecer o erro **1106**, o app não foi vinculado (passo 3) ou falta autorizar o *IoT Core*.

## 5. Descobrir IP e versão do protocolo

O computador precisa estar **na mesma rede** do aparelho:

```bash
php artisan portaria:tuya-procurar
```

Se o Windows perguntar sobre o firewall, **permita** o PHP em redes privadas. O comando mostra **IP, ID e versão** (3.1, 3.3, 3.4 ou 3.5).

> **Dica:** reserve o IP do aparelho no roteador (*reserva de DHCP*). Assim o IP não muda depois de uma queda de energia.

## 6. Cadastrar no sistema

**Equipamentos → Dispositivos → Novo dispositivo**:

| Campo | Valor |
|---|---|
| Modelo / driver | Tuya / Smart Life (rede local) |
| Endereço (IP) | O IP do passo 5 |
| Porta | 6668 |
| ID do aparelho | Do passo 4 |
| Chave local | Do passo 4 (16 caracteres) |
| Versão do protocolo | Do passo 5 |
| Data points dos canais | `1` (tomadas e bocais). Lâmpadas costumam usar `20` |

Clique em **Testar comunicação**. Se funcionar, aparecem os *data points*, por exemplo `{"1":false}`. Depois teste **Ligar** e **Desligar** e salve.

Em seguida, em **Equipamentos → Pontos de acesso**, crie um ponto usando esse dispositivo. Para uma lâmpada, use o acionamento **Liga / desliga**; para portão, **Pulso**.

---

## Problemas comuns

| Sintoma | Causa provável |
|---|---|
| "Não foi possível conectar" | IP errado, aparelho desligado ou em outra rede/VLAN |
| "O aparelho encerrou a conexão" | Chave ou versão erradas, **ou o app do celular está conectado ao aparelho pela rede local**: feche o app e tente de novo |
| "A chave local está incorreta" | O aparelho foi pareado de novo: busque a chave outra vez (passo 4) |
| Responde, mas não liga | *Data point* errado: veja quais aparecem no teste e ajuste o campo |

## Diagnóstico detalhado

Se algo não funcionar, rode o comando abaixo e envie o resultado. Ele mostra cada pacote trocado com o aparelho; a chave **não** aparece.

```bash
php artisan portaria:dispositivo-testar "Nome do dispositivo" --detalhes
php artisan portaria:dispositivo-testar "Nome do dispositivo" --acao=ligar --detalhes
```

## Sobre a compatibilidade

O protocolo foi implementado em PHP e conferido **byte a byte** com a biblioteca de referência **tinytuya** (Python), nas versões 3.1, 3.3, 3.4 e 3.5. Os testes estão em `tests/Unit/Tuya/ProtocoloTest.php` e `tests/Feature/Portaria/ClienteTuyaTest.php`.
