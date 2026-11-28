# Câmeras

A imagem ao vivo aparece em dois lugares:

- **Câmeras** (menu Operação): todas as câmeras do condomínio em grade, com 1, 2 ou 3 colunas. Toque na imagem para tela cheia.
- **Abrir**: a câmera do portão aparece **logo acima do botão ABRIR**, para ver quem está lá antes de abrir.

A imagem é atualizada cerca de **duas vezes por segundo** ("por quadros"). O vídeo totalmente fluido (go2rtc) é a próxima etapa (5B).

## Cadastrar uma câmera Intelbras

**Equipamentos → Dispositivos → Novo dispositivo**

| Campo | O que colocar |
|---|---|
| Tipo | **Câmera** |
| Endereço (IP) | o IP da câmera (ou do DVR/NVR), por exemplo `192.168.1.108` |
| Porta | `80` (a porta web da câmera) |
| Marca | **Intelbras** |
| Usuário / senha | os mesmos que você usa no app **Intelbras / iSIC / Mibo** ou no navegador da câmera (geralmente `admin`) |
| Canal | `1` para câmera IP. Em **DVR/NVR**, o número da câmera no gravador |
| Moradores podem ver | ligue só para câmeras de portão/entrada. Deixe desligado em áreas internas |

Depois toque em **Buscar imagem agora**: a foto aparece na hora. Se der erro, a mensagem diz o motivo (rede, senha, canal).

> **Dica:** dê à câmera um **IP fixo** (no próprio app Intelbras ou reservando no roteador), como fizemos com o bocal.

### Ligar a câmera ao portão

**Equipamentos → Pontos de acesso** → editar o portão → marque em **Câmeras deste portão**. A primeira câmera marcada que a pessoa pode ver aparece no **Abrir**.

## Outras marcas

| Marca | Como funciona |
|---|---|
| Intelbras e base Dahua | `/cgi-bin/snapshot.cgi?channel=N` |
| Hikvision | `/ISAPI/Streaming/channels/N01/picture` |
| Outra marca | informe a **URL da imagem** (um endereço que devolve uma foto JPEG). Use `{canal}` para o número do canal |

O endereço **RTSP** é montado sozinho pela marca (ou pode ser informado) e fica guardado para o vídeo fluido.

## Quem vê o quê

| Pessoa | Vê |
|---|---|
| Super admin, operador, porteiro, síndico | todas as câmeras do condomínio |
| Morador | só as câmeras marcadas **"Moradores podem ver"** |
| Outro condomínio | nada |

## Segurança

- A imagem **sempre passa pelo sistema**, com login e permissão. O IP, o usuário e a senha da câmera **nunca chegam ao navegador**.
- A senha fica **criptografada** no banco.
- Câmera fora da tela (rolou a página, aba escondida) **para de buscar imagem**: economiza internet do celular e da câmera.
- Várias pessoas olhando a mesma câmera ao mesmo tempo usam a mesma foto: a câmera não fica sobrecarregada.

## Problemas comuns

| Mensagem | O que verificar |
|---|---|
| A câmera não respondeu | IP e porta; o servidor está na mesma rede da câmera? |
| A câmera recusou o usuário/senha | usuário e senha; algumas câmeras bloqueiam por alguns minutos após várias tentativas erradas |
| A câmera não devolveu uma imagem | marca e canal; em DVR, confira o número da câmera |
