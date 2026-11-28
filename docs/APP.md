# App do morador e visitantes

## Instalar no celular

O sistema funciona como um aplicativo: ícone na tela inicial, abre em tela cheia e tem uma página própria quando o celular está sem internet. Não passa pela Play Store nem pela App Store.

| Celular | Como instalar |
|---|---|
| **Android (Chrome)** | Ao entrar no sistema, aparece o aviso **"Instale o app no seu celular"** → **Instalar**. Ou no menu do Chrome (⋮) → **Instalar app** |
| **iPhone (Safari)** | Botão **Compartilhar** (quadrado com seta) → **Adicionar à Tela de Início** |

> A instalação só funciona pelo endereço com **https** (`https://portariaremota.meusistema.api.br`).

No celular, a navegação fica numa **barra embaixo**, com **Abrir**, **Visitantes**, **Histórico** e **Mais**. O morador entra direto na tela **Abrir**.

## Autorizar um visitante (morador)

1. **Visitantes → Autorizar visitante**.
2. Informe o **nome**. Celular e documento são opcionais.
3. Defina **quando**: hoje, próximas 24 h ou um período de até 31 dias.
4. Defina **quantas entradas**: cada abertura de portão conta uma.
5. Marque os **portões** que ele pode abrir.
6. Toque em **Gerar link do visitante** e envie pelo **WhatsApp** ou por **Compartilhar**.

O visitante abre o link no celular dele, **sem cadastro e sem senha**, e toca em **ABRIR** quando chegar.

Quando ele usa o link, o morador recebe na hora o aviso **"Fulano chegou"**. A entrada também aparece no **Histórico** do morador.

Para cancelar: **Visitantes** → toque no ✕, ou abra a autorização → **Cancelar autorização**. O link deixa de funcionar na mesma hora.

## Para a administração

- **Liberar portões para visitantes:** **Equipamentos → Pontos de acesso** → editar → **"Visitantes podem abrir (link do morador)"**. A opção vem desligada: nenhum link abre um portão até você liberar. Ela vale só para portões com acionamento por pulso.
- **Portaria, síndico e operadores** veem todas as autorizações do condomínio em **Visitantes**: quem autorizou, unidade, validade e usos. Também podem cancelar qualquer uma. O link em si só aparece para quem criou a autorização.

## Segurança do link

| Proteção | Como funciona |
|---|---|
| Link difícil de adivinhar | 32 caracteres aleatórios |
| Guardado com proteção | O banco guarda só o "resumo" (hash) para encontrar o link e uma cópia criptografada para reenvio |
| Limites | Só funciona dentro da validade e do número de entradas definidos |
| Uso contado com segurança | Dois toques ao mesmo tempo não passam do limite |
| Falha não gasta entrada | Se o portão não abrir, a entrada é devolvida para o visitante tentar de novo |
| Contra abuso | No máximo 6 tentativas de abertura por minuto |
| Histórico | Cada tentativa fica registrada como *"Visitante: Fulano (autorizado por Ciclano)"*, inclusive as negadas |
| Sem indexação | A página não aparece no Google e não repassa o endereço para outros sites |

> **Limitação:** o link funciona de qualquer lugar; ele não verifica se o visitante está mesmo em frente ao portão. Por isso a validade curta e o limite de entradas são importantes. Uma verificação de localização (GPS) pode vir depois, se você quiser.
