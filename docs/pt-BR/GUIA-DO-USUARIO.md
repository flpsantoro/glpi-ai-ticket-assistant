# Guia do usuário

*[English version](../USER-GUIDE.md)*

Para técnicos. O assistente lê o chamado e propõe; **você** decide o que é enviado, aplicado ou
publicado.

- [O botão "Assistente de IA"](#o-botão-assistente-de-ia)
- [Análise](#análise)
- [Resposta ao solicitante](#resposta-ao-solicitante)
- [Melhorar meu texto](#melhorar-meu-texto)
- [Classificação](#classificação)
- [Artigo da base de conhecimento](#artigo-da-base-de-conhecimento)
- [Boas práticas](#boas-práticas)
- [Dúvidas](#dúvidas)

## O botão "Assistente de IA"

Fica no fim da linha do tempo do chamado e abre uma janela com abas:

| Aba | Disponível em |
|---|---|
| Análise | chamados abertos |
| Resposta ao solicitante | chamados abertos |
| Classificação | chamados abertos |
| Base de conhecimento | chamados solucionados e fechados |

Quando existe uma sugestão automática, um segundo botão **Categoria sugerida (xx %)** abre direto a
aba Classificação.

## Análise

O assistente lê o chamado inteiro: descrição, acompanhamentos, tarefas, soluções (inclusive as
recusadas), ativos vinculados, aprovação e os anexos de cada item da linha do tempo (prints, PDF,
Word/Excel, logs). Ele também procura **chamados parecidos que a sua equipe já resolveu**. Leva de
alguns segundos a cerca de um minuto.

Você recebe, como **orientação interna** (nunca enviada ao solicitante):

- **Resumo**: o problema e o que já foi feito;
- **Estratégia técnica**: causa provável, o que verificar, passo a passo, quando escalar;
- **Informações que ainda faltam**: o que perguntar ao solicitante;
- **Chamados resolvidos semelhantes usados como referência**: links para eles, com a similaridade (ou
  "mesma categoria", para um chamado recente da mesma categoria).

A linha de baixo mostra o modelo, os tokens e o tempo. **Gerar de novo** pede uma nova análise; quando
o chamado não mudou, a última análise é exibida de novo, sem custo.

👍 / 👎 dizem aos administradores se a resposta ajudou; eles aparecem nas estatísticas.

## Resposta ao solicitante

Um rascunho no padrão de comunicação da sua equipe, cumprimentando o solicitante pelo primeiro nome,
com a sua assinatura.

- **Inserir como acompanhamento** / **Inserir como solução**: abre o formulário de resposta do GLPI e
  coloca o rascunho no editor. Se o editor já tiver texto, você é consultado antes de ele ser
  substituído (Ctrl+Z desfaz).
- **Copiar**: copia o texto para colar onde quiser.

**Leia e ajuste antes de enviar**: confira os fatos, os comandos, os nomes. O acompanhamento só é
enviado quando você clica em **Adicionar** no GLPI, com a visibilidade (privado ou público) que você
escolher.

## Melhorar meu texto

Nos formulários de **acompanhamento** e de **solução**, abaixo do editor: **Melhorar meu texto (IA)**,
e a seta ao lado para os outros modos:

| Modo | O que faz |
|---|---|
| Melhorar meu texto | mais claro, educado, organizado, no padrão da equipe, com os mesmos fatos |
| Mais formal | para um gestor, um parceiro externo, um caso delicado |
| Mais cordial | mais caloroso, palavras mais simples |
| Mais curto | mantém só o que importa |
| Só corrigir ortografia e gramática | não muda mais nada (não acrescenta saudação nem assinatura) |
| Traduzir para o idioma do solicitante | usa o idioma do perfil do solicitante no GLPI |

Escreva os fatos rapidamente, do seu jeito, e depois melhore: o texto do editor é substituído e o
**Ctrl+Z** traz o seu de volta. A IA mantém os seus fatos; ela não acrescenta promessas ou prazos que
você não escreveu.

## Classificação

O assistente sugere o **tipo** (incidente ou requisição), a **categoria** e a **urgência**, a partir dos
chamados resolvidos parecidos e, quando habilitado, da leitura que a própria IA faz do chamado.

- A tabela compara os valores atuais com a sugestão; a barra mostra a confiança.
- **Aplicar ao chamado** aplica tipo, categoria e urgência (com os seus direitos sobre o chamado).
- **Outras candidatas** lista as próximas categorias, cada uma com **Aplicar a categoria**.
- **Descartar** remove a sugestão.

As suas escolhas ficam registradas: elas medem quantas sugestões acertam e ajudam a decidir se os
chamados novos podem ser classificados automaticamente.

## Artigo da base de conhecimento

Num chamado solucionado ou fechado, a aba **Base de conhecimento** redige um artigo (sintomas, causa,
solução) sem os dados pessoais do chamado.

- Ajuste o título, **Copiar** o texto, ou **Criar rascunho do artigo**: o artigo é criado **não
  publicado** e vinculado ao chamado. **Abrir o artigo** para revisar e escolher o público (destinos)
  antes de publicar.

## Boas práticas

- **Confira o que você envia.** A IA pode errar ou estar desatualizada (versões, comandos, regras
  internas).
- **Notas privadas continuam privadas.** Notas internas podem ajudar na análise, mas o rascunho de
  resposta nunca as cita; mesmo assim, leia o rascunho antes de enviar.
- **Nunca escreva senhas nos chamados.** O assistente mascara valores escritos como "senha: …", mas
  nem todo segredo pode ser detectado.
- **Chamado bom, resposta boa**: descrição clara, a mensagem de erro, um print.
- **Use 👍 / 👎**: é o jeito mais simples de dizer aos administradores o que funciona.

## Dúvidas

**Não vejo o botão "Assistente de IA".** O assistente pode estar desabilitado, o seu perfil pode não
ter o direito "Assistente de IA", ou o chamado está fechado (só a aba Base de conhecimento aparece).
Fale com o administrador.

**"O orçamento diário do assistente de IA acabou" / "Você atingiu o limite de N usos do assistente
por hora".** Limites definidos pelo administrador para controlar custos; tente mais tarde.

**O solicitante vê alguma coisa?** Não. Nada é enviado ao solicitante, a menos que você insira um
rascunho e adicione o acompanhamento ou a solução.

**Ele lê prints e documentos?** Sim, quando o administrador habilitou e o modelo lê imagens: prints,
páginas digitalizadas, PDF, Word, Excel, PowerPoint, OpenDocument e arquivos de texto (logs, CSV,
JSON…), dentro dos limites configurados.
