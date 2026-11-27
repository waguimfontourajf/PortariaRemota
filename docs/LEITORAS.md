# Leitoras de reconhecimento facial

O sistema é o **dono do cadastro**: você cadastra a pessoa (com foto) só na Portaria Remota, e o sistema envia uma cópia para cada leitora facial do condomínio. A leitora reconhece o rosto **sozinha**, mesmo sem rede ou com o servidor desligado.

Marcas suportadas (drivers):

| Driver | Modelos | Comunicação |
|---|---|---|
| **Leitora facial Control iD (iDFace)** | iDFace, iDFace Max | API HTTP da Control iD (login + sessão) |
| **Leitora facial Intelbras** | linha Bio-T: SS 3530, SS 3532, SS 3540, SS 5530... | API HTTP (CGI) com Digest |
| **Leitora facial Hikvision (ISAPI)** | linha DS-K1T... | ISAPI com Digest |

> **Atenção:** os drivers foram feitos a partir da documentação dos fabricantes e testados com respostas simuladas. Na primeira leitora real de cada marca, faça os testes do item 3 e, se algo falhar, abra **"Detalhes técnicos"** no teste.

---

## 1. Quem vai para a leitora

Vai para a leitora a pessoa que tem **as três coisas**:

1. cadastro **ativo**;
2. **foto do rosto**;
3. **autorização de uso da biometria** marcada (LGPD).

Quem perde uma delas (foi inativado, excluído, teve a foto removida ou retirou a autorização) é **removido** da leitora.

O código da pessoa na leitora é o **ID dela no sistema**. Nome e foto são atualizados sempre que o cadastro é salvo.

## 2. Foto e LGPD

Em **Cadastros → Pessoas → (pessoa)**, quadro **"Foto e reconhecimento facial"**:

- **Tirar foto** abre a câmera do celular; **Escolher arquivo** usa uma foto já tirada.
- A foto é reduzida no próprio celular e gravada no servidor como JPEG de até 200 KB, numa pasta **privada** (`storage/app/private/pessoas`). Só quem gerencia os cadastros do condomínio consegue vê-la.
- Boa foto: de frente, rosto inteiro, sem óculos escuros nem boné, bem iluminada, fundo liso.
- **Autorização (LGPD):** a foto do rosto é **dado biométrico** (dado pessoal sensível). Marque a caixa só depois que a pessoa (ou o responsável legal, no caso de menores) autorizar. O sistema registra **quem marcou e quando**, e o histórico guarda quando foi marcada ou retirada.
- Ao **excluir** uma pessoa, ela sai das leitoras e a foto é apagada do servidor.

Recomendação: guarde também o termo de consentimento assinado (papel ou digital) na administração do condomínio.

## 3. Cadastrar a leitora

Em **Equipamentos → Dispositivos → Novo**:

| Campo | Valor |
|---|---|
| Modelo / driver | a marca da leitora |
| Endereço (IP) | IP da leitora (reserve no roteador) |
| Porta | vazio (80) |
| Quantidade de canais | número de portas que a leitora aciona (normalmente 1) |
| Usuário / senha | os da página web da leitora |

Campos específicos:

- **Control iD:** *Como a porta é aberta* (relé da leitora ou módulo externo **SecBox**) e *Grupo de acesso* (normalmente **1**). Sem grupo de acesso, a leitora reconhece o rosto mas **não libera** a passagem.
- **Hikvision:** *Biblioteca de faces (FDID)*, normalmente **1**.

Testes, na própria tela:

1. **Testar comunicação**: deve mostrar modelo e firmware da leitora.
2. **Pulso 1 s**: a leitora abre a porta (o tempo de abertura é o configurado **nela**).
3. Salve, e no quadro **"Pessoas na leitora facial"** clique em **Sincronizar agora**.
4. Faça uma pessoa testar o rosto na leitora.

A leitora também pode ser usada num **ponto de acesso**, como um relé: o botão ABRIR do sistema abre a porta por ela.

## 4. Sincronização

- **Automática:** ao salvar, ativar/desativar ou excluir uma pessoa, o sistema atualiza todas as leitoras do condomínio na hora. Se uma leitora estiver fora do ar, aparece um aviso e a pessoa fica marcada **com erro**.
- **Sincronizar agora** (tela da leitora): envia quem mudou ou ficou com erro e remove quem saiu. Use na primeira carga, depois de trocar/resetar a leitora ou depois que ela ficou desligada.
- **Reenviar todas**: reenvia todo mundo (por exemplo, depois de restaurar a leitora de fábrica).
- Pelo terminal do servidor:

```bash
php artisan portaria:leitoras-sincronizar            # todas as leitoras
php artisan portaria:leitoras-sincronizar "Leitora portaria" --todas
```

## 5. Erros comuns

| Mensagem | O que fazer |
|---|---|
| Recusou o usuário/senha (HTTP 401) | Confira usuário e senha no cadastro da leitora |
| Sem resposta do dispositivo | IP, porta, cabo de rede; a leitora está na mesma rede do servidor? |
| Nenhum rosto encontrado / rosto virado / sem nitidez | Tire outra foto seguindo as dicas do item 2 |
| Este rosto já está cadastrado para outra pessoa | A mesma pessoa está duplicada no cadastro (ou na leitora, cadastrada à mão) |
| Não respondeu como Intelbras/Hikvision | Driver errado para a marca/modelo, ou porta diferente de 80 |

## 6. O que ainda não faz

- **Horários e validade** por pessoa (ex.: diarista só de segunda a sexta): hoje todos os liberados têm acesso a qualquer hora.
- **Histórico vindo da leitora** (quem passou pelo rosto): os acessos por reconhecimento facial ficam só na leitora por enquanto.
- Escolher **quais pessoas** vão para **qual leitora** (hoje vão todas as liberadas do condomínio).
