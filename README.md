# ORCID Manual Entry — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.0.2.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/orcidManualEntry/releases/download/1.0.2.0/orcidManualEntry-1.0.2.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that restores a **typeable (manual)
ORCID field** in the author/contributor form — the behaviour from older OJS versions — for
journals where **ORCID authentication (OAuth) is not configured**.

> ⚠️ **Manual entry is NOT the recommended way to collect ORCID iDs.** The recommended
> approach remains **authenticated ORCID (OAuth)**, where the author signs in at ORCID and
> the iD is verified at the source. A manually typed iD is unverified — it can be mistyped
> or belong to someone else. Use this plugin only as a fallback while your journal cannot
> enable ORCID OAuth; once you configure OAuth, the plugin goes inert and OJS takes over.
> See [Why authenticated ORCID is recommended](#why-authenticated-orcid-is-recommended).

> **Developed and maintained by [OJSBR](https://ojsbr.com.br).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.2.0 |

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
  *Edit contributor*).
- Accepts the bare iD (`0000-0002-1825-0097`) or the URL, and **normalizes** it to the
  canonical `https://orcid.org/0000-0002-1825-0097` the core expects.
- Keeps the core's **format + checksum validation**: an invalid iD is rejected.
- Shows the stored iD again when *Edit contributor* is reopened, so re-saving a contributor
  never wipes it.

## Installation

1. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
   into `plugins/generic/` so you get `plugins/generic/orcidManualEntry/`.
2. Enable **ORCID manual (digitável)** under the *Generic* plugins list.

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

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com.br) — original plugin.
- Distributed under the **GNU GPL v3**, the same license as OJS.

## Contributing

Issues and pull requests are welcome.

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que restaura um **campo ORCID digitável
(manual)** no formulário de autor/contribuidor — como nas versões anteriores do OJS — para
revistas em que a **autenticação ORCID (OAuth) não está configurada**.

> ⚠️ **O preenchimento manual NÃO é a forma recomendada de coletar iDs ORCID.** A
> recomendação continua sendo o **ORCID autenticado (OAuth)**, em que o autor faz login no
> ORCID e o iD é verificado na fonte. Um iD digitado manualmente **não é verificado** — pode
> ser digitado errado ou pertencer a outra pessoa. Use este plugin apenas como alternativa
> temporária enquanto a revista não puder habilitar o ORCID OAuth; assim que o OAuth for
> configurado, o plugin fica inerte e o OJS assume o controle.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com.br).**

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
  submissão e *Editar contribuidor*).
- Aceita o iD nu (`0000-0002-1825-0097`) ou a URL e **normaliza** para
  `https://orcid.org/0000-0002-1825-0097`.
- Mantém a **validação de formato e dígito verificador** do núcleo: iD inválido é rejeitado.
- Reexibe o iD gravado ao reabrir *Editar contribuidor*, de modo que salvar o contribuidor
  de novo nunca apaga o ORCID.

### Instalação

Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta
em `plugins/generic/` (ficando `plugins/generic/orcidManualEntry/`). Depois ative o
**ORCID manual (digitável)** na lista de plugins *Genéricos*.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com.br) — plugin autoral.
- Distribuído sob a **GNU GPL v3**, a mesma licença do OJS.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
