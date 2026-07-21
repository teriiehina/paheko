{{#load type="category" limit=1}}
{{else}}
	{{:assign var="account" value=null}}
	{{:assign var="account.6251" value="6251 — Frais de déplacement"}}
	{{:save type="category" schema="./category.schema.json"
		key=""|uuid
		label="Déplacement (forfaitaire)"
		account=$account
		expense_type="flat_rate"
		price=5000
	}}

	{{:assign var="account" value=null}}
	{{:assign var="account.6251" value="6251 — Frais de déplacement"}}
	{{:save type="category" schema="./category.schema.json"
		key=""|uuid
		label="Déplacement (au kilomètre)"
		account=$account
		expense_type="km_vehicle"
	}}

	{{:assign var="account" value=null}}
	{{:assign var="account.626" value="626 — Frais postaux ou télécommunication"}}
	{{:save type="category" schema="./category.schema.json"
		key=""|uuid
		label="Frais postaux ou télécommunication"
		account=$account
		expense_type="other"
	}}

	{{:assign var="account" value=null}}
	{{:assign var="account.6063" value="6063 — Fournitures d'entretien et petit équipement"}}
	{{:save type="category" schema="./category.schema.json"
		key=""|uuid
		label="Fournitures d'entretien et petit équipement"
		account=$account
		expense_type="other"
	}}

	{{:assign var="account" value=null}}
	{{:assign var="account.6065" value="6065 — Petits logiciels"}}
	{{:save type="category" schema="./category.schema.json"
		key=""|uuid
		label="Logiciels à faible valeur (< 500 €)"
		account=$account
		expense_type="other"
	}}

	{{:assign var="account" value=null}}
	{{:assign var="account.625" value="625 — Frais de réception"}}
	{{:save type="category" schema="./category.schema.json"
		key=""|uuid
		label="Nourriture et autres frais de réception"
		account=$account
		expense_type="other"
	}}
{{/load}}

{{:assign var="vehicles"
	speedbike="Vélo électrique rapide (speed bike, > 25 km/h)"
	ecyclomoteur="Moto ou scooter électrique <= 50 cc"
	emoto1cv="Moto ou scooter électrique > 50 cc — 1 ou 2 CV"
	emoto3cv="Moto ou scooter électrique > 50 cc — 3 à 5 CV"
	emoto6cv="Moto ou scooter électrique > 50 cc — 6 CV et plus"
	e3cv="Voiture électrique — 3CV et moins"
	e4cv="Voiture électrique — 4CV"
	e5cv="Voiture électrique — 5CV"
	e6cv="Voiture électrique — 6CV"
	e7cv="Voiture électrique — 7CV et plus"

	3cv="Voiture thermique — 3CV et moins"
	4cv="Voiture thermique — 4CV"
	5cv="Voiture thermique — 5CV"
	6cv="Voiture thermique — 6CV"
	7cv="Voiture thermique — 7CV et plus"
	cyclomoteur="Moto ou scooter thermique <= 50 cc"
	moto1cv="Moto ou scooter thermique > 50 cc — 1 ou 2 CV"
	moto3cv="Moto ou scooter thermique > 50 cc — 3 à 5 CV"
	moto6cv="Moto ou scooter thermique > 50 cc — 6 CV et plus"
}}
