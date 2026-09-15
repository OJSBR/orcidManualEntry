# ORCID Manual Entry — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.1.2.1-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/orcidManualEntry/releases/download/1.1.2.1/orcidManualEntry-1.1.2.1.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that restores a **typeable (manual)
ORCID field** — the behaviour from older OJS versions — for journals where **ORCID
authentication (OAuth) is not configured**. It covers the three places where the core hides
the field: the **author/contributor form**, the **public user registration page** and the
**user profile**.

> ⚠️ **Manual entry is NOT the recommended way to collect ORCID iDs.** The recommended
> approach remains **authenticated ORCID (OAuth)**, where the author signs in at ORCID and
> the iD is verified at the source. A manually typed iD is unverified — it can be mistyped
> or belong to someone else. Use this plugin only as a fallback while your journal cannot
> enable ORCID OAuth; once you configure OAuth, the plugin goes inert and OJS takes over.
> See [Why authenticated ORCID is recommended](#why-authenticated-orcid-is-recommended).

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.1.2.1 |

Also applies to OJS 3.4.x, where the same core restriction was introduced.

## Why authenticated ORCID is recommended

Since OJS 3.4/3.5 the old ORCID plugin was merged into the core and the author ORCID field
became **read-only**: it can only be filled by **OAuth authentication** (the author signs in
at ORCID and authorises the journal). This is deliberate — an **authenticated iD is verified
at the source**, so you know it really belongs to that person. A **manually typed iD is not
verified**: it may be mistyped, invented, or belong to someone else, and it will not carry
ORCID's "verified" status.

**So the recommendation is: enable ORCID OAuth.** See PKP's
[ORCID in OJS/OMP/OPS guide](https://docs.pkp.sfu.ca/orcid/). Use this plugin **only** while
you genuinely cannot enable OAuth (e.g. no ORCID member/public API credentials yet) and still
need to record iDs. It is a pragmatic fallback, not a replacement for authentication.

## What it does

- Acts **only** when ORCID OAuth is **not** enabled for the context. If you configure OAuth
  later, the plugin becomes inert and the core takes over the verified flow.
- Adds a plain **ORCID** text field to the contributor form (submission wizard and
  *Edit contributor*), to the **public registration page** and to **Profile → Identity**.
- An iD recorded on the user account is copied by the core into the author metadata of the
  user's next submission (`Repo::author()->newAuthorFromUser()`), so authors no longer have
  to type it again at submission time.
- Accepts the bare iD (`0000-0002-1825-0097`) or the URL, and **normalizes** it to the
  canonical `https://orcid.org/0000-0002-1825-0097` the core expects.
- Keeps the core's **format + checksum validation**: an invalid iD is rejected.
- **Refuses an iD that already belongs to another contributor of the same publication**, naming
  the contributor who holds it. The core does not check this, and two authors sharing one iD
  are only noticed at the Crossref deposit, where they become the same researcher. The
  comparison is made on the 16 digits, so the same iD stored once as `orcid.org` and once as
  `sandbox.orcid.org` is still caught.
- Shows the stored iD again when *Edit contributor* is reopened, so re-saving a contributor
  never wipes it.

## Installation

1. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
   into `plugins/generic/` so you get `plugins/generic/orcidManualEntry/`.
2. Enable **ORCID manual (digitável)** under the *Generic* plugins list.

## Configuration

There is nothing to configure — enabling the plugin is the whole setup. What matters is the
**guard**: the plugin only acts while ORCID OAuth is **off** for the context.

`OrcidManager::isEnabled()` reads `orcidEnabled` from the journal (or the site), and it does
**not** check whether the credentials are usable. A journal left with ORCID enabled and
placeholder client id/secret ends up in limbo: a field nobody can fill and a plugin that
stays silent. If the ORCID field does not show up, check
**Settings → Distribution → ORCID** first, before suspecting a plugin conflict.

Site-wide registration (the site index, with no journal selected) is out of scope: the core
disables ORCID there, and a context-enabled plugin is not loaded on that page.

## How it works (technical)

The core blocks manual ORCID in four places; the plugin neutralizes each **only when OAuth
is off**:

1. `Form::config::before` → adds a typeable `orcid` field to the `ContributorForm`.
2. `TemplateManager::display` → publishes `js/orcidManualEntry.js`, which registers the
   `field-orcid-manual` Vue component. This one is not obvious: `ContributorsListPanel`
   `.openEditModal()` fills every field with `field.value = contributor[name]`, **except** a
   field named `orcid`, which instead receives `field.orcid`, because the core assumes the
   `FieldOrcid` (OAuth) component there. A plain `FieldText` reads `value`, so the stored iD
   never reached the input: the form always reopened blank, and the next *Save* wrote that
   blank over the stored iD. The component extends `FieldText` and seeds `value` from the
   `orcid` prop on `mounted()`.
3. `Author::validate` → removes the `cannotUpdateAuthorOrcid` block while keeping the core's
   format/checksum validation.
4. `Author::add::before` / `Author::edit` → normalizes and (re)injects the iD before it is
   written, since the edit endpoint strips `orcid` from the parameters by default. Clearing
   an existing iD is logged to the PHP error log, so that a future regression of the Vue
   component is traceable instead of silent.

For the **registration page** and the **user profile**, the core already reads the `orcid`
request variable — it just hides the field and drops the value when OAuth is off. Three more
hooks close that gap, **without replacing any core template**:

5. `registrationform::display` / `identityform::display` → register a Smarty output filter
   that adds the field to the rendered form (neither template has a hook): at the top of
   `form#register`, where the core would put its ORCID widget, and after the last field of
   `form#identityForm`. The core's `$orcidEnabled` switch stays off, so the OAuth widget is
   never drawn. The filter leaves any other output alone and never adds a second `orcid`
   input.
6. `registrationform::Constructor` / `identityform::Constructor` → add an optional
   `FormValidatorCustom` on `orcid`, so a typed iD must pass format + checksum.
7. `registrationform::execute` / `identityform::execute` → write the normalized iD to the
   user (`RegistrationForm::execute()` only applies it when OAuth is on, and
   `IdentityForm::execute()` never applies it at all).

Site-wide registration (no journal context) is out of scope: ORCID is disabled there by the
core, and a context-enabled plugin is not loaded on that page.

## Tests

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`): the plugin class against the
  installed PKP and PKP's plugin registry, ORCID normalization, duplicate comparison by iD, where
  the field goes in the registration and profile forms, that other output is left alone, that the
  submitted value is escaped, that the output filter is named (Smarty names every closure filter
  "closure", so an unnamed one replaced another plugin's), that no core template is replaced and no
  iD reaches the server log, and the 38 translations. From the OJS root:

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/orcidManualEntry/tests"
  ```

- **Cypress** (`cypress/tests/functional/OrcidManualEntry.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on every push): enables the
  plugin, checks the registration page, the profile (a bare iD saved as its canonical URL, a wrong
  check digit refused, the iD cleared and the original put back) and contributors of a submission
  in progress through the REST endpoints the contributor form uses (iD stored, the same iD refused
  for a second contributor even as a sandbox URL, a wrong check digit refused, the iD removed),
  deleting the contributors it adds.
- Verified on OJS 3.5.0.3 with ORCID OAuth off, also with the WhatsApp Contributor plugin adding
  its own field to the registration form. Earlier manual checks (1.1.x) also covered a new
  submission inheriting the account's iD, the contributor modal reopening with the stored iD, and
  the plugin going inert once OAuth is on.

Tests are kept in the repository and are not part of the release package.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**, the same license as OJS.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

## Contributing

Issues and pull requests are welcome.

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que restaura um **campo ORCID digitável
(manual)** — como nas versões anteriores do OJS — para revistas em que a **autenticação ORCID
(OAuth) não está configurada**. Cobre os três lugares em que o núcleo esconde o campo: o
**formulário de autor/contribuidor**, a **tela pública de cadastro de usuário** e o **perfil
do usuário**.

> ⚠️ **O preenchimento manual NÃO é a forma recomendada de coletar iDs ORCID.** A
> recomendação continua sendo o **ORCID autenticado (OAuth)**, em que o autor faz login no
> ORCID e o iD é verificado na fonte. Um iD digitado manualmente **não é verificado** — pode
> ser digitado errado ou pertencer a outra pessoa. Use este plugin apenas como alternativa
> temporária enquanto a revista não puder habilitar o ORCID OAuth; assim que o OAuth for
> configurado, o plugin fica inerte e o OJS assume o controle.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).**

### Compatibilidade e branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.1.2.1 |

Vale também para o OJS 3.4.x, onde a mesma restrição do núcleo foi introduzida.

### Por que o ORCID autenticado é o recomendado

Desde o OJS 3.4/3.5 o antigo plugin ORCID foi incorporado ao núcleo e o campo ORCID do autor
passou a ser **somente-leitura**: só pode ser preenchido via **autenticação OAuth** (o autor
faz login no ORCID e autoriza a revista). Isso é proposital — um **iD autenticado é
verificado na origem**, então você sabe que ele realmente pertence àquela pessoa. Um **iD
digitado manualmente não é verificado**: pode conter erro de digitação, ser inventado ou ser
de outra pessoa, e não recebe o status de "verificado" do ORCID.

**Portanto, a recomendação é: habilitar o ORCID OAuth** (veja o
[guia de ORCID da PKP](https://docs.pkp.sfu.ca/orcid/)). Use este plugin **apenas** enquanto
realmente não for possível habilitar o OAuth e ainda assim for preciso registrar iDs. É uma
alternativa pragmática, não um substituto da autenticação.

### O que faz

- Age **somente** quando o ORCID OAuth **não** está habilitado no contexto; se você
  configurar o OAuth depois, o plugin fica inerte.
- Adiciona um campo de texto **ORCID** ao formulário de contribuidor (assistente de
  submissão e *Editar contribuidor*), à **tela pública de cadastro** e ao
  **Perfil → Identificação**.
- O iD gravado na conta do usuário é copiado pelo próprio núcleo para os metadados de autoria
  da submissão seguinte (`Repo::author()->newAuthorFromUser()`), ou seja, o autor não precisa
  digitá-lo de novo na hora de submeter.
- Aceita o iD nu (`0000-0002-1825-0097`) ou a URL e **normaliza** para
  `https://orcid.org/0000-0002-1825-0097`.
- Mantém a **validação de formato e dígito verificador** do núcleo: iD inválido é rejeitado.
- **Recusa o iD que já pertence a outro contribuidor da mesma publicação**, dizendo de quem ele
  é. O núcleo não faz essa conferência, e dois autores com o mesmo iD só aparecem no depósito
  do Crossref, onde viram o mesmo pesquisador. A comparação é feita pelos 16 dígitos, então o
  mesmo iD gravado uma vez como `orcid.org` e outra como `sandbox.orcid.org` também é pego.
- Reexibe o iD gravado ao reabrir *Editar contribuidor*, de modo que salvar o contribuidor
  de novo nunca apaga o ORCID.

### Instalação

Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta
em `plugins/generic/` (ficando `plugins/generic/orcidManualEntry/`). Depois ative o
**ORCID manual (digitável)** na lista de plugins *Genéricos*.

### Configuração

Não há o que configurar — ativar o plugin é a configuração inteira. O que importa é a
**guarda**: o plugin só age enquanto o ORCID OAuth estiver **desligado** no contexto.

O `OrcidManager::isEnabled()` lê o `orcidEnabled` da revista (ou do site) e **não** verifica
se as credenciais prestam. Uma revista com o ORCID ligado e client id/secret de teste fica no
limbo: campo que ninguém consegue preencher e plugin calado. Se o campo ORCID não aparecer,
confira **Configurações → Distribuição → ORCID** antes de suspeitar de conflito entre plugins.

O cadastro em nível de site (o índice do portal, sem revista selecionada) está fora do
escopo: o núcleo desliga o ORCID ali, e um plugin habilitado por contexto não é carregado
nessa página.

### Testes

PHPUnit em `tests/` (sobre `PKP\tests\PKPTestCase`) e Cypress em `cypress/tests/functional/`
(rodado pelo [pkp-github-actions](https://github.com/pkp/pkp-github-actions) a cada push), com os
comandos da seção em inglês. O Cypress confere a tela de cadastro, o perfil (iD digitado só com os
16 dígitos gravado como URL canônica, dígito verificador errado recusado, iD apagado e o original
devolvido) e os contribuidores de uma submissão em andamento pelos mesmos endpoints REST do
formulário (iD gravado, o mesmo iD recusado para um segundo contribuidor mesmo como URL de sandbox,
dígito errado recusado, iD removido), apagando os contribuidores que cria.

Desde a 1.1.2.0 o campo do cadastro e do perfil entra por um filtro de saída do Smarty, sem
substituir nenhum template do núcleo; a partir da 1.1.2.1 o filtro tem nome próprio, porque o Smarty
chama todo filtro closure de "closure" e um apagava o de outro plugin (o campo do WhatsApp
Contributor, por exemplo). Verificado no OJS 3.5.0.3 com o ORCID OAuth desligado.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**, a mesma licença do OJS.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
