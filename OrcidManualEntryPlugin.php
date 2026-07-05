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
 * neutralizando as tres barreiras:
 *   1) Form::config::before  -> adiciona um FieldText 'orcid' digitavel;
 *   2) Author::validate      -> remove o erro "cannotUpdateAuthorOrcid" (mantendo
 *                               a validacao de formato/checksum do proprio core);
 *   3) Author::add::before /
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
use PKP\validation\ValidatorORCID;

class OrcidManualEntryPlugin extends GenericPlugin
{
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
        return 'ORCID manual (digitavel)';
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return 'Restaura o campo ORCID digitavel no formulario de autor/contribuidor '
            . 'quando a autenticacao ORCID (OAuth) nao esta configurada, como nas versoes '
            . 'anteriores do OJS. Sem efeito quando o ORCID OAuth esta ativo.';
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
            'label' => __('user.orcid'),
            'description' => 'Informe o iD ORCID completo, no formato '
                . 'https://orcid.org/0000-0002-1825-0097 (ou apenas os 16 digitos). '
                . 'Preenchimento manual, sem autenticacao.',
            'isMultilingual' => false,
        ]), [FIELD_POSITION_AFTER, 'url']);

        return Hook::CONTINUE;
    }

    /**
     * Barreira 2: remove o bloqueio "cannotUpdateAuthorOrcid" que o core adiciona
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
     * Barreira 3a: grava o ORCID ao ADICIONAR um contribuidor.
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
     * Barreira 3b: grava o ORCID ao EDITAR um contribuidor. O endpoint remove o
     * ORCID dos parametros antes de salvar, entao reinjetamos no objeto que sera
     * persistido (o hook roda antes do UPDATE no banco).
     */
    public function applyOrcidOnEdit(string $hookName, array $args): bool
    {
        if ($this->orcidOAuthActive() || !self::$hasPending) {
            return Hook::CONTINUE;
        }
        $newAuthor = $args[0];
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
