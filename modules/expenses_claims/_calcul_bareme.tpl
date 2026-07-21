{{if !$distance || !$vehicule}}
	{{:error message="Fonction 'calcul_bareme' : paramètre distance ou vehicule manquant"}}
{{/if}}

{{if $vehicule === "speedbike"}}
	{{:assign vehicule="ecyclomoteur"}}
{{/if}}

{{if $vehicule|substr:0:1 === 'e'}}
	{{:assign
		bonus="1.2"
		vehicule=$vehicule|substr:1
	}}
{{else}}
	{{:assign bonus="1"}}
{{/if}}

{{:read file="./baremes.csv" assign="baremes"}}
{{:assign baremes=$baremes|trim|explode:"\n"}}

{{#foreach from=$baremes item="bareme"}}
	{{:assign bareme=$bareme|str_getcsv}}
	{{if $vehicule !== $bareme.0}}
		{{:continue}}
	{{/if}}

	{{if $bareme.0 === 'cyclomoteur' || $bareme.0|substr:4 === 'moto'}}
		{{if $distance <= 3000}}
			{{:assign calcul=$bareme.1}}
		{{elseif $distance <= 6000}}
			{{:assign calcul=$bareme.2}}
		{{else}}
			{{:assign calcul=$bareme.3}}
		{{/if}}
	{{else}}
		{{if $distance <= 5000}}
			{{:assign calcul=$bareme.1}}
		{{elseif $distance <= 20000}}
			{{:assign calcul=$bareme.2}}
		{{else}}
			{{:assign calcul=$bareme.3}}
		{{/if}}
	{{/if}}
	{{:break}}
{{/foreach}}


{{:assign calcul_maths=$calcul|replace:"x":"*"|replace:",":"."|replace:"d":$distance|regexp_replace:"/[^0-9\.*]/":""}}

{{:assign resultat="round(%s*%f, 2)"|math:$calcul_maths:$bonus}}
