{**
 * templates/controllers/grid/gridActionsAbove.tpl
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2000-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Actions markup for upper grid actions
 *}

<ul class="actions">
	{foreach from=$actions item=action}
		<li>
			{include file="../plugins/generic/cspSubmission/templates/linkAction.tpl" action=$action contextId=$gridId}
		</li>
	{/foreach}
</ul>
