{{* Insert default categories/objects *}}

{{#load where="$$.type = 'category' OR $$.type = 'object'" limit=1}}
{{else}}
	{{:read file="defaults.json" assign="defaults"}}
	{{:save key="uuid" from=$defaults|json_decode}}
	{{:redirect to=$request_url}}
{{/load}}
