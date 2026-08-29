{**
 * plugins/generic/orcidManualEntry/templates/form/orcidProfile.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Substitui o templates/form/orcidProfile.tpl do core (o widget de OAuth) quando
 * o ORCID OAuth nao esta configurado e o plugin orcidManualEntry esta ativo.
 *
 * O template do core faz duas coisas que nao servem ao preenchimento manual:
 *
 *  - no cadastro publico ($targetOp eq 'register') ele desenha um input OCULTO
 *    e um botao "Conectar ORCID" que abre a janela de OAuth. Como o
 *    userRegister.tpl nao tem campo proprio de ORCID, e aqui que o campo
 *    digitavel precisa ser desenhado;
 *  - no perfil ($targetOp eq 'profile') ele converte por JavaScript o input de
 *    texto que o identityForm.tpl acabou de desenhar em um campo oculto,
 *    colocando o botao de OAuth no lugar. Aqui basta nao fazer nada para que o
 *    campo continue digitavel.
 *
 * @uses $targetOp string 'register' no cadastro publico, 'profile' no perfil
 * @uses $orcid string ORCID gravado/informado, se houver
 * @uses $orcidManualDescription string Instrucao de preenchimento
 *}

{if $targetOp eq 'register'}
	<fieldset class="orcid">
		<legend>
			{translate key="user.orcid"}
		</legend>
		<div class="fields">
			<div class="orcid">
				<label>
					<span class="label">
						{translate key="user.orcid"}
					</span>
					<input type="text" name="orcid" id="orcid" value="{$orcid|default:""|escape}" maxlength="46" autocomplete="off" placeholder="https://orcid.org/0000-0002-1825-0097">
					<span class="description">
						{$orcidManualDescription|escape}
					</span>
				</label>
			</div>
		</div>
	</fieldset>
{else}
	<span class="sub_label" id="orcidManualEntryDescription">
		{$orcidManualDescription|escape}
	</span>
	<style>
		{* O identityForm.tpl do core poe o campo e este bloco lado a lado
		   (.orcid_container > .section { display:flex }) para acomodar o botao
		   de OAuth, e faz isso em um <style> que vem DEPOIS deste -- por isso a
		   regra abaixo precisa de especificidade maior (o seletor de elemento).
		   Sem o botao, a instrucao fica melhor abaixo do campo, como nos demais
		   campos do formulario. *}
		div.orcid_container > .section {
			display: block;
		}
		#orcidManualEntryDescription {
			display: block;
			margin-top: 0.25em;
		}
	</style>
{/if}
