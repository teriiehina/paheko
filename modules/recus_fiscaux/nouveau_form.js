$('#f_numeraire_1, #f_nature_1').forEach((i) => {
	i.onchange = selectType;
});

var numeraire = $('#f_numeraire_1');

function selectType() {
	g.toggle('.type-numeraire', numeraire.checked, false);
}

selectType();

var e = $('#f_entreprise_1');

function selectEntreprise() {
	g.toggle('.entreprise', e ? e.checked : false);
	g.toggle('.particulier', e ? !e.checked : true);

}

if (e) {
	e.onchange = selectEntreprise;
}

selectEntreprise();

var y = $('#f_id_year');

if (y && y.tagName.toUpperCase() === 'SELECT' && typeof user_years !== 'undefined') {
	function selectYear() {
		$('#f_nature_1, #f_numeraire_1, #f_moyens_especes_1, #f_moyens_cheques_1, #f_moyens_autres_1').forEach((e) => e.checked = false);

		let id = y.value;
		let d = user_years[id] ?? null;
		$('#f_montant').value = g.formatMoney(d.total);

		$('#f_moyens_especes_1').checked = d.total_especes > 0;
		$('#f_moyens_cheques_1').checked = d.total_cheques > 0;
		$('#f_moyens_autres_1').checked = d.total_numeraire > 0 && (d.total_numeraire > d.total_especes + d.total_cheques);
		$('#f_montant').form.id_transaction.value = d.id_transaction;
		$('#f_numeraire_1').checked = d.total_numeraire > 0;

		var nature = $('#f_nature_1');

		if (!nature) {
			return;
		}

		nature.checked = d.total_nature > 0;
		$('#f_abandon_frais_1').checked = d.total_abandon_frais > 0;
		$('#f_montant_numeraire').value = g.formatMoney(d.total_numeraire);
		$('#f_montant_nature').value = g.formatMoney(d.total_nature + d.total_abandon_frais);
		$('#f_periode_annee').value = d.year;

		selectType();
	}

	y.onchange = selectYear;
	selectYear();
}

let p = $('[name=preview]')[0];

p.addEventListener('click', (e) => {
	let form = e.target.form;
	form.action = "previsualiser.html";
	form.target = "dialog";
	g.openFrameDialog('about:blank', {height: 'auto'});
	form.submit();
	form.action = "";
	form.target = "";
});