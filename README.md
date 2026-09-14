# Scorm Maker Import (local_scorm_maker_import)

Plugin local que expõe duas funções de web service autenticadas por token,
permitindo que sistemas externos criem conteúdo em cursos do Moodle sem
intervenção manual de um professor:

- **Importar um pacote SCORM** a partir de uma URL HTTPS autorizada em
  `https://scormmaker.com.br` **ou** de um arquivo enviado diretamente
  (upload).
- **Importar um Livro** (`mod_book`) a partir de um bloco de HTML, dividido
  automaticamente em capítulos pela tag `<h1>`.

## Funcionalidades

- `local_scorm_maker_import_import_scorm_from_url` — baixa um pacote SCORM
  (.zip) somente de `https://scormmaker.com.br` (host exato), **ou** usa um
  arquivo já enviado via `/webservice/upload.php` (ver seção "Uso" abaixo);
  valida que o ZIP contém `imsmanifest.xml` na raiz e cria a atividade SCORM
  no curso/seção informados.
- `local_scorm_maker_import_import_book_from_html` — cria uma atividade
  Livro a partir de um bloco de HTML, dividindo-o automaticamente em um
  capítulo por seção `<h1>` (texto antes do primeiro `<h1>` vira o capítulo
  "Introduction"; se não houver nenhum `<h1>`, todo o conteúdo vira um único
  capítulo).
- As duas funções reconferem a capability `moodle/course:manageactivities` no
  `execute()`, independentemente do que está declarado em `db/services.php` —
  ou seja, mesmo que o token tenha acesso ao serviço, o usuário por trás dele
  precisa ser professor/gestor no curso de destino.

## Requisitos

- Moodle 4.0 ou superior (`$plugin->requires = 2022112800`).
- A versão 1.2.3 declara suporte às séries Moodle 4.5 até 5.2; essa faixa foi
  verificada com a suíte PHPUnit em Moodle 4.5.14 e Moodle 5.2.3.
- `mod_scorm` habilitado no site, para usar o endpoint de importação de SCORM.
- `mod_book` habilitado no site, para usar o endpoint de importação de Livro.
- Extensão PHP `ext-zip` (já exigida pelo próprio Moodle) para validar o ZIP.
- Para a opção `url`, o pacote precisa estar disponível por HTTPS no host exato
  `scormmaker.com.br`.

## Instalação

1. Copie este plugin para `local/scorm_maker_import`.
2. Acesse *Administração do site → Notificações* (ou rode
   `php admin/cli/upgrade.php --non-interactive`) para concluir a instalação.
3. Habilite os web services e o protocolo REST em *Administração do site →
   Servidor → Web services*, caso ainda não estejam habilitados.
4. Em *Administração do site → Servidor → Web services → Serviços externos*,
   habilite o serviço **Scorm Maker Import** (ele vem desabilitado por
   padrão) e adicione os usuários/papéis autorizados a chamá-lo.
5. Gere um token de web service para um usuário autorizado em *Administração
   do site → Servidor → Web services → Gerenciar tokens*.

Se você já tinha instalado a versão 1.0 deste plugin (antes do suporte a
upload direto), rode o upgrade novamente (passo 2) — é isso que aplica a
permissão de upload de arquivos (`uploadfiles`) ao serviço já existente.

## Configuração

Este plugin não tem `settings.php` — não há nada para configurar além da
configuração padrão de web service acima (habilitar o serviço e emitir
tokens).

## Uso

Todas as chamadas abaixo usam o protocolo REST clássico do Moodle
(`/webservice/rest/server.php`) com `moodlewsrestformat=json`. Substitua
`$WWWROOT` pela URL do seu site e `$TOKEN` pelo token gerado no passo 5 da
instalação.

### 1. Importar SCORM a partir de uma URL HTTPS autorizada

**Função:** `local_scorm_maker_import_import_scorm_from_url`

| Parâmetro | Tipo | Obrigatório | Padrão | Descrição |
|---|---|---|---|---|
| `courseid` | inteiro | Sim | — | ID do curso onde a atividade será criada. |
| `url` | URL | Ver nota¹ | `''` | URL HTTPS do arquivo `.zip` no host exato `scormmaker.com.br`. |
| `name` | texto | Não | `SCORM importado` | Nome da atividade. |
| `sectionnum` | inteiro | Não | `0` | Número da seção do curso (0 = seção geral). |
| `draftitemid` | inteiro | Ver nota¹ | `0` | Alternativa a `url` — ver "Importar SCORM via upload direto" abaixo. |

¹ **Forneça exatamente um** entre `url` e `draftitemid` — nunca os dois,
nunca nenhum. Fornecer ambos ou nenhum resulta em erro (`invalidscormsource`).

**Retorno:**

```json
{
  "scormid": 12,
  "cmid": 34,
  "warnings": []
}
```

**Exemplo (via URL):**

```bash
curl -sS "$WWWROOT/webservice/rest/server.php" \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode "wsfunction=local_scorm_maker_import_import_scorm_from_url" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "courseid=2" \
  --data-urlencode "url=https://scormmaker.com.br/pacotes/curso.zip" \
  --data-urlencode "name=Curso de Integração" \
  --data-urlencode "sectionnum=1"
```

### 2. Importar SCORM via upload direto (sem URL pública)

Quando o pacote SCORM não está hospedado em uma URL HTTPS autorizada acessível
pelo servidor Moodle, envie o arquivo primeiro para a área de rascunho do
próprio usuário do token, usando o endpoint padrão de upload do Moodle,
`/webservice/upload.php`, e então chame `import_scorm_from_url` passando o
`itemid` retornado no parâmetro `draftitemid` em vez de `url`:

```bash
# Passo 1: enviar o .zip, recebendo de volta um draftitemid.
# Atenção: o nome do campo do arquivo NÃO pode usar colchetes "[]"
# (use "file_box=", nunca "file_box[]=") — o endpoint de upload do Moodle
# lê $_FILES como entradas simples e interpreta mal a forma de array.
curl -sS "$WWWROOT/webservice/upload.php" \
  -F "token=$TOKEN" \
  -F "file_box=@pacote.zip"
# -> [{"itemid": 123456, "filename": "pacote.zip", ...}]

# Passo 2: importar usando esse draftitemid em vez de url.
curl -sS "$WWWROOT/webservice/rest/server.php" \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode "wsfunction=local_scorm_maker_import_import_scorm_from_url" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "courseid=2" \
  --data-urlencode "draftitemid=123456" \
  --data-urlencode "name=Curso Enviado por Upload"
```

A área de rascunho (draft area) é sempre restrita ao próprio usuário do
token — não existe forma de um chamador referenciar o arquivo enviado por
outro usuário só adivinhando o `itemid`, a mesma garantia que qualquer
formulário de upload do próprio Moodle já depende.

Esse fluxo exige que o serviço **Scorm Maker Import** tenha a permissão de
upload de arquivos habilitada (`uploadfiles`), o que este plugin já declara
em `db/services.php` a partir da versão 1.1. Se o site foi instalado com a
versão 1.0, rode o upgrade uma vez (*Administração do site → Notificações*)
para essa permissão ser aplicada ao serviço já existente.

### 3. Importar Livro a partir de HTML

**Função:** `local_scorm_maker_import_import_book_from_html`

| Parâmetro | Tipo | Obrigatório | Padrão | Descrição |
|---|---|---|---|---|
| `courseid` | inteiro | Sim | — | ID do curso onde a atividade será criada. |
| `htmlcontent` | HTML (texto bruto) | Sim | — | Conteúdo HTML completo do livro. |
| `name` | texto | Não | `Livro importado` | Título do livro. |
| `description` | HTML (texto bruto) | Não | `''` | Introdução/descrição do livro. |
| `sectionnum` | inteiro | Não | `0` | Número da seção do curso (0 = seção geral). |

**Como o HTML é dividido em capítulos:**

- Cada tag `<h1>` inicia um novo capítulo; o título do capítulo é o texto do
  `<h1>` (tags internas como `<strong>`/`<em>` são removidas, sobrando só o
  texto puro).
- Se houver conteúdo **antes** do primeiro `<h1>`, ele vira um capítulo
  inicial chamado "Introduction".
- Se **nenhum** `<h1>` for encontrado, todo o `htmlcontent` vira um único
  capítulo, usando `name` como título.

**Retorno:**

```json
{
  "bookid": 5,
  "cmid": 21,
  "chapterids": [10, 11, 12]
}
```

`chapterids` vem na ordem de criação (capítulo de introdução, se houver,
seguido dos capítulos na ordem em que os `<h1>` aparecem no HTML).

**Exemplo:**

```bash
curl -sS "$WWWROOT/webservice/rest/server.php" \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode "wsfunction=local_scorm_maker_import_import_book_from_html" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "courseid=2" \
  --data-urlencode "name=Manual do Aluno" \
  --data-urlencode "description=<p>Importado automaticamente.</p>" \
  --data-urlencode "htmlcontent=<p>Texto de abertura.</p><h1>Capítulo 1</h1><p>Conteúdo...</p><h1>Capítulo 2</h1><p>Mais conteúdo...</p>"
```

Um arquivo de exemplo pronto para testar está em
[`tests/fixtures/sample_book.html`](tests/fixtures/sample_book.html), com um
script auxiliar em
[`tests/fixtures/call_import_book_from_html.sh`](tests/fixtures/call_import_book_from_html.sh).

### Erros possíveis

Todos os erros são retornados como uma exceção estruturada do Moodle
(`moodle_exception` ou uma subclasse), nunca como um erro solto/HTML. Nas
respostas REST em JSON, o campo `errorcode` identifica a causa:

| `errorcode` | Endpoint(s) | Quando ocorre |
|---|---|---|
| `invalidcourse` | ambos | `courseid` não corresponde a um curso existente. |
| `noscormmodule` | SCORM | `mod_scorm` está desinstalado ou desabilitado no site. |
| `nobookmodule` | Livro | `mod_book` está desinstalado ou desabilitado no site. |
| `invalidscormsource` | SCORM | Nem `url` nem `draftitemid` foram informados, ou os dois foram informados juntos. |
| `invaliddraftfile` | SCORM | O `draftitemid` informado não aponta para uma área de rascunho com exatamente um arquivo. |
| `invalidscormurl` | SCORM | A `url` não usa HTTPS com o host exato `scormmaker.com.br`. |
| `scormdownloaderror` | SCORM | Falha ao baixar o ZIP da `url` autorizada (rede, HTTP diferente de 200, redirecionamento ou bloqueio pela segurança do Moodle — ver "Notas de segurança"). |
| `invalidzip` | SCORM | O arquivo baixado/enviado não é um ZIP válido. |
| `nomanifest` | SCORM | O ZIP não contém `imsmanifest.xml` na raiz (manifestos dentro de subpastas não contam). |
| `chapterimporterror` | Livro | Falha ao gravar algum capítulo no banco de dados. |
| `required_capability_exception` (core) | ambos | O usuário do token não tem `moodle/course:manageactivities` no curso informado. |
| `invalid_parameter_exception` (core) | ambos | Algum parâmetro obrigatório está ausente ou tem tipo inválido. |

## Capabilities

| Capability | O que permite | Papéis padrão |
|---|---|---|
| `moodle/course:manageactivities` (do núcleo do Moodle, reaproveitada — não definida por este plugin) | Necessária, no contexto do curso de destino, para chamar qualquer uma das duas funções | Professor, Gestor |

Este plugin não define nenhuma capability própria.

## Notas de segurança

- O endpoint de importação de SCORM aceita uma URL remota somente quando ela
  usa HTTPS e tem exatamente o host `scormmaker.com.br`. HTTP, subdomínios,
  hosts parecidos, credenciais embutidas e portas diferentes da porta HTTPS
  padrão são rejeitados antes de qualquer requisição de rede.
- O download usa o cURL do Moodle com redirecionamentos desabilitados. Assim,
  uma resposta de `scormmaker.com.br` não pode fazer o servidor Moodle buscar
  um arquivo em outro host. A proteção de segurança do cURL do Moodle contra
  endereços bloqueados continua ativa.
- O parâmetro `draftitemid` é uma alternativa local: ele usa um arquivo enviado
  para a área de rascunho do próprio usuário e não realiza download remoto.
- A descrição do Livro e o HTML dos capítulos passam por `clean_text` do Moodle
  no formato `FORMAT_HTML` antes de serem armazenados.
- As duas funções exigem um token de web service de um usuário autenticado
  (`loginrequired => true`) e são declaradas com `restrictedusers => 1`, ou
  seja, um administrador precisa autorizar explicitamente cada usuário que
  poderá chamá-las. Sites que expõem este endpoint devem restringir quais
  usuários/papéis podem ser autorizados no serviço **Scorm Maker Import**.
- O upload direto (`draftitemid`) usa a área de rascunho padrão do Moodle,
  sempre restrita ao usuário do token — não há como acessar arquivos de
  outro usuário adivinhando o `itemid`.

## Privacidade

Este plugin não armazena nenhum dado pessoal próprio (`null_provider`). As
atividades que ele cria (instâncias de SCORM, instâncias e capítulos de
Livro) são conteúdo comum de `mod_scorm`/`mod_book`, já cobertas pelos
provedores de privacidade desses módulos.

## Suporte / Licença

Relate problemas ao mantenedor do plugin no seu site. Licenciado sob a GNU
GPL v3 ou posterior.
