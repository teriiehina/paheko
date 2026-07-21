{{#foreach from=$module.config.accounts|explode:','|map:'trim' item="a"}}
	{{:assign var="module.config.accounts.%s"|args:$a value=$a}}
{{/foreach}}
{{:save key="config"
	accounts=$module.config.accounts|arrayval
}}
