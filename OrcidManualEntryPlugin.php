<?php

/**
 * @file plugins/generic/orcidManualEntry/OrcidManualEntryPlugin.php
 *
 * @class OrcidManualEntryPlugin
 *
 * @brief Restaura o campo ORCID digitavel (manual) no formulario de
 *        autor/contribuidor, como nas versoes anteriores do OJS.
 *
 * A partir do OJS 3.4/3.5 o antigo plugin ORCID foi incorporado ao core e o
 * campo ORCID do autor passou a ser somente-leitura: so pode ser preenchido via
 * autenticacao OAuth (FieldOrcid) e, quando o OAuth nao esta configurado, o
 * campo simplesmente nao aparece. Alem disso o backend bloqueia qualquer ORCID
 * informado manualmente em Repo::author()->validate() e o endpoint de edicao de
 * contribuidor remove o ORCID dos parametros antes de salvar.
 *
 * Este plugin atua APENAS quando o ORCID OAuth NAO esta configurado no contexto,
 * neutralizando as quatro barreiras:
 *   1) Form::config::before  -> adiciona um campo 'orcid' digitavel;
 *   2) TemplateManager::display
 *                            -> publica o componente Vue 'field-orcid-manual',
 *                               sem o qual o valor gravado nao volta para o
 *                               formulario de edicao (ver addFieldComponent());
 *   3) Author::validate      -> remove o erro "cannotUpdateAuthorOrcid" (mantendo
 *                               a validacao de formato/checksum do proprio core);
 *   4) Author::add::before /
 *      Author::edit          -> injeta/normaliza o ORCID informado antes da
 *                               gravacao no banco (o endpoint de edicao o remove
 *                               dos parametros, entao reinjetamos aqui).
 */

namespace APP\plugins\generic\orcidManualEntry;

use APP\core\Application;
use PKP\components\forms\FieldText;
use PKP\components\forms\publication\ContributorForm;
use PKP\orcid\OrcidManager;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\template\PKPTemplateManager;
use PKP\validation\ValidatorORCID;

class OrcidManualEntryPlugin extends GenericPlugin
{
    /**
     * Nome do componente Vue registrado por js/orcidManualEntry.js. Precisa ser
     * diferente de 'field-text' porque o componente estende o FieldText para
     * tambem ler a prop 'orcid' (ver addFieldComponent()).
     */
    public const FIELD_COMPONENT = 'field-orcid-manual';

    /**
     * Templates que montam o ContributorsListPanel, ou seja, onde o campo pode
     * ser exibido: o painel (todos os fluxos de trabalho passam por ele) e o
     * assistente de submissao.
     */
    public const TEMPLATES_WITH_CONTRIBUTORS = [
        'dashboard/editors.tpl',
        'submission/wizard.tpl',
    ];

    /**
     * ORCID normalizado capturado em Author::validate para reaproveitar em
     * Author::add::before / Author::edit dentro da MESMA requisicao.
     * null = limpar o valor; string = URL normalizada.
     */
    private static ?string $pendingOrcid = null;

    /** Indica se a requisicao atual trouxe a chave 'orcid' no payload. */
    private static bool $hasPending = false;

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        if ($this->getEnabled($mainContextId)) {
            Hook::add('Form::config::before', [$this, 'addOrcidField']);
            Hook::add('TemplateManager::display', [$this, 'addFieldComponent']);
            Hook::add('Author::validate', [$this, 'allowManualOrcid']);
            Hook::add('Author::add::before', [$this, 'applyOrcidOnAdd']);
            Hook::add('Author::edit', [$this, 'applyOrcidOnEdit']);
        }

        return true;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.orcidManualEntry.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.orcidManualEntry.description');
    }

    /**
     * O plugin so deve agir quando o ORCID OAuth NAO esta configurado.
     * Quando o OAuth esta ativo, o core cuida do FieldOrcid e do fluxo de
     * verificacao, e este plugin fica inerte.
     */
    private function orcidOAuthActive(): bool
    {
        $context = Application::get()->getRequest()->getContext();
        return OrcidManager::isEnabled($context);
    }

    /**
     * Barreira 1: adiciona um campo ORCID digitavel ao ContributorForm.
     *
     * Form::config::before e disparado via Hook::run, entao o form chega como
     * segundo parametro do callback (nao dentro de um array).
     */
    public function addOrcidField(string $hookName, $form): bool
    {
        if (!$form instanceof ContributorForm) {
            return Hook::CONTINUE;
        }
        if ($this->orcidOAuthActive()) {
            return Hook::CONTINUE;
        }
        // Evita duplicar caso o config seja montado mais de uma vez.
        if ($form->getField('orcid')) {
            return Hook::CONTINUE;
        }

        $form->addField(new FieldText('orcid', [
            // FieldText que le tambem a prop 'orcid'; ver addFieldComponent().
            'component' => self::FIELD_COMPONENT,
            'label' => __('user.orcid'),
            'description' => __('plugins.generic.orcidManualEntry.field.description'),
            'isMultilingual' => false,
        ]), [FIELD_POSITION_AFTER, 'url']);

        return Hook::CONTINUE;
    }

    /**
     * Barreira 2: publica o componente Vue do campo.
     *
     * ContributorsListPanel.openEditModal() copia o contribuidor buscado na API
     * para os campos do formulario, mas trata o campo chamado 'orcid' como caso
     * especial: em vez de `field.value = contribuidor.orcid`, ele faz
     * `field.orcid = contribuidor.orcid`, porque o core supoe que ali esteja o
     * FieldOrcid (o widget de OAuth), que le essa prop. Um FieldText comum le
     * `value`, entao o ORCID gravado nunca chegava ao input: o formulario
     * reabria em branco e o "Salvar" seguinte regravava o branco por cima do
     * ORCID armazenado.
     *
     * O componente registrado em js/orcidManualEntry.js estende o FieldText e
     * inicializa `value` a partir da prop `orcid`, resolvendo os dois sintomas.
     *
     * O script precisa ser carregado depois do js/build.js (registrado pelo core
     * com STYLE_SEQUENCE_LATE), para que 'field-text' ja exista no registro, e
     * antes da chamada pkp.registry.init() no fim da pagina, que cria o app Vue.
     * STYLE_SEQUENCE_LAST da exatamente essa janela.
     *
     * @param array $args [$templateMgr, &$template, &$output]
     */
    public function addFieldComponent(string $hookName, array $args): bool
    {
        if ($this->orcidOAuthActive()) {
            return Hook::CONTINUE;
        }

        $templateMgr = $args[0];
        $template = $args[1];

        if (!in_array($template, self::TEMPLATES_WITH_CONTRIBUTORS, true)) {
            return Hook::CONTINUE;
        }

        $baseUrl = Application::get()->getRequest()->getBaseUrl() . '/' . $this->getPluginPath();

        $templateMgr->addJavaScript(
            'orcidManualEntry',
            "{$baseUrl}/js/orcidManualEntry.js",
            [
                'priority' => PKPTemplateManager::STYLE_SEQUENCE_LAST,
                'contexts' => ['backend'],
            ]
        );

        return Hook::CONTINUE;
    }

    /**
     * Barreira 3: remove o bloqueio "cannotUpdateAuthorOrcid" que o core adiciona
     * sempre que 'orcid' esta presente nos parametros, preservando a validacao de
     * formato/checksum do proprio core. Tambem captura o valor normalizado para as
     * etapas de gravacao.
     *
     * Author::validate e disparado via Hook::call, entao os argumentos chegam como
     * array no segundo parametro; $args[0] e o array $errors (por referencia) e
     * $args[2] e o array $props enviado.
     */
    public function allowManualOrcid(string $hookName, array $args): bool
    {
        if ($this->orcidOAuthActive()) {
            return Hook::CONTINUE;
        }

        $props = $args[2] ?? [];
        if (!array_key_exists('orcid', $props)) {
            self::$hasPending = false;
            self::$pendingOrcid = null;
            return Hook::CONTINUE;
        }

        $normalized = self::normalizeOrcid($props['orcid']);
        self::$hasPending = true;
        self::$pendingOrcid = ($normalized === '') ? null : $normalized;

        if ($normalized === '') {
            // Campo vazio: nao ha ORCID para gravar, remove qualquer erro de ORCID.
            unset($args[0]['orcid']);
        } elseif (self::isValidOrcid($normalized)) {
            // Formato valido: remove o bloqueio do core e o erro de formato do valor cru.
            unset($args[0]['orcid']);
        } else {
            // Valor invalido: mantem apenas a mensagem de ORCID invalido.
            $args[0]['orcid'] = [__('user.orcid.orcidInvalid')];
        }

        return Hook::CONTINUE;
    }

    /**
     * Barreira 4a: grava o ORCID ao ADICIONAR um contribuidor.
     */
    public function applyOrcidOnAdd(string $hookName, array $args): bool
    {
        if ($this->orcidOAuthActive() || !self::$hasPending) {
            return Hook::CONTINUE;
        }
        $author = $args[0];
        $author->setData('orcid', self::$pendingOrcid);
        self::$hasPending = false;

        return Hook::CONTINUE;
    }

    /**
     * Barreira 4b: grava o ORCID ao EDITAR um contribuidor. O endpoint remove o
     * ORCID dos parametros antes de salvar, entao reinjetamos no objeto que sera
     * persistido (o hook roda antes do UPDATE no banco).
     *
     * Campo vazio significa "remover o ORCID", e assim deve continuar. Mas como
     * essa e a operacao destrutiva do plugin -- e ja foi disparada sem querer,
     * quando o formulario reabria em branco --, ela fica registrada no log de
     * erros para que qualquer regressao futura do componente Vue seja
     * rastreavel em vez de silenciosa.
     *
     * $args[0] e o autor que sera gravado; $args[1] e o autor como esta hoje.
     */
    public function applyOrcidOnEdit(string $hookName, array $args): bool
    {
        if ($this->orcidOAuthActive() || !self::$hasPending) {
            return Hook::CONTINUE;
        }
        $newAuthor = $args[0];
        $currentOrcid = $args[1]->getData('orcid');

        if (self::$pendingOrcid === null && !empty($currentOrcid)) {
            error_log(sprintf(
                '[orcidManualEntry] Removendo o ORCID %s do contribuidor %d (campo enviado vazio).',
                $currentOrcid,
                (int) $newAuthor->getId()
            ));
        }

        $newAuthor->setData('orcid', self::$pendingOrcid);
        self::$hasPending = false;

        return Hook::CONTINUE;
    }

    /**
     * Normaliza a entrada para a URL canonica do ORCID exigida pelo core:
     * https://orcid.org/0000-0002-1825-0097 (ou o dominio sandbox).
     * Aceita o iD nu (16 digitos) ou a URL com/sem protocolo. Valores nao
     * reconhecidos sao devolvidos como estao, para falharem na validacao.
     *
     * @param mixed $raw
     */
    public static function normalizeOrcid($raw): string
    {
        $v = trim((string) $raw);
        if ($v === '') {
            return '';
        }

        // iD nu: 0000-0002-1825-0097
        if (preg_match('#^(\d{4}-\d{4}-\d{4}-\d{3}[0-9Xx])$#', $v, $m)) {
            return OrcidManager::ORCID_URL . strtoupper($m[1]);
        }

        // URL (com ou sem protocolo/www), orcid.org ou sandbox.orcid.org
        if (preg_match('#^(?:https?://)?(?:www\.)?(sandbox\.)?orcid\.org/(\d{4}-\d{4}-\d{4}-\d{3}[0-9Xx])/?$#i', $v, $m)) {
            $host = $m[1] ? 'sandbox.orcid.org' : 'orcid.org';
            return 'https://' . $host . '/' . strtoupper($m[2]);
        }

        return $v;
    }

    /**
     * Valida o ORCID (formato + digito verificador ISNI) usando o validador do core.
     */
    public static function isValidOrcid(string $orcidUrl): bool
    {
        return (new ValidatorORCID())->isValid($orcidUrl);
    }
}
