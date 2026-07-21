{{* Mise à jour depuis version 2022 vers 2023 *}}
{{if $module.config.comptes_don_nature && !$module.config.comptes_don_abandon_frais}}
	{{:save key="config"
		validate_schema="./config.schema.json"
		comptes_don_abandon_frais=$module.config.comptes_don_nature
		comptes_don_nature=null
		numero_asso=$module.config.numero_asso
		champ_entreprise_numero=$module.config.champ_entreprise_numero
		champ_entreprise_forme=$module.config.champ_entreprise_forme
	}}
	{{:assign var="module.config.comptes_don_abandon_frais" value=$module.config.comptes_don_nature}}
	{{:assign var="module.config.comptes_don_nature" value=null}}
{{/if}}

{{if !$module.config}}
	{{* Valeurs par défaut *}}
	{{:assign var="module.config"
		objet_asso=""
		type_asso="defaut"
		art200=false
		art238=false
		art978=false
		email_subject="Votre reçu de don"
		email_body="Bonjour !\n\nVeuillez trouver ci-joint votre reçu de don au format PDF."
	}}
	{{:assign var="module.config.comptes_don.754" value="754 — Ressources liées à la générosité du public"}}
	{{:assign var="module.config.comptes_don_abandon_frais.75412" value="75412 — Abandons de frais par les bénévoles"}}
	{{:assign var="module.config.comptes_especes.530" value="530 — Caisse"}}
	{{:assign var="module.config.comptes_cheques.5112" value="5112 — Chèques à encaisser"}}
	{{:assign var="module.config.champs_adresse"
		adresse="adresse"
		code_postal="code_postal"
		ville="ville"
	}}
{{/if}}

{{:assign var="types_asso.defaut"
	label="Organisme d'intérêt général ou reconnu d'utilité publique"
	help="66 % du montant versé, dans la limite de 20 % du revenu imposable."
	case="7UF"
}}
{{:assign var="types_asso.personnes"
	label="Association fournissant gratuitement une aide alimentaire ou des soins médicaux à des personnes en difficultés ou favorisant leur logement"
	help="75% du montant versé pour un don d'un montant inférieur ou égal à 1000 €.\nLa fraction au-delà de 1000 € ouvre droit à une réduction d'impôt de 66 % du montant donné."
	case="7UD"
}}
{{:assign var="types_asso.cultuelle"
	label="Association cultuelle ou de bienfaisance et établissement public reconnus d’Alsace-Moselle"
	help="75 % du montant versé, dans la limite de 562 €. La fraction au-delà de 562 € ouvre droit à une réduction d'impôt de 66 %."
	case="7UJ"
}}
{{:assign var="types_asso.syndicat"
	label="Syndicat"
	help="66% des cotisations versées, dans la limite de 1% du revenu brut imposable."
	case="7AC"
}}
