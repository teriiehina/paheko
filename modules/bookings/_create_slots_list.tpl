{{if $event === null}}
	{{:error admin="Missing mandatory variable"}}
{{/if}}


{{**** Construction de la liste des créneaux ****}}
{{:assign repeat="%d-1"|math:$event.max_weeks}}

{{if $event.use_closings || $event.use_openings}}
	{{#module name="openings"}}
		{{* Prendre en compte les périodes de fermeture depuis le module "ouvertures" *}}
		{{if $event.use_closings}}
			{{#foreach from=$config.closed item="slot"}}
				{{:assign
					start="%s %s, 00:00:00"|args:$slot.close_day:$slot.close_month|strtotime
					end="%s %s, 23:59:59"|args:$slot.reopen_day:$slot.reopen_month|strtotime
				}}
				{{if $end < $start}}
					{{:assign
						end="%s %s, 23:59:59, +1 year"|args:$slot.reopen_day:$slot.reopen_month|strtotime
					}}
				{{/if}}
				{{:assign var="closed." start=$start end=$end}}
			{{/foreach}}
		{{/if}}

		{{* Créer les créneaux à partir du module "ouvertures" *}}
		{{if $event.use_openings && $event.openings_slots > 0}}
			{{:assign now="now"|strtotime}}
			{{#foreach from=$config.open item="slot"}}
				{{if $slot.frequency != "this"}}
					{{:assign
						start="%s %s of this month, %s:00"|args:$slot.frequency:$slot.day:$slot.open|strtotime
						end="%s %s of this month, %s:00"|args:$slot.frequency:$slot.day:$slot.close|strtotime
					}}
					{{* Try next month *}}
					{{if $end < $now}}
						{{:assign
							start="%s %s of next month, %s:00"|args:$slot.frequency:$slot.day:$slot.open|strtotime
							end="%s %s of next month, %s:00"|args:$slot.frequency:$slot.day:$slot.close|strtotime
						}}
					{{/if}}
				{{else}}
					{{:assign
						start="%s %s, %s:00"|args:$slot.frequency:$slot.day:$slot.open|strtotime
						end="%s %s, %s:00"|args:$slot.frequency:$slot.day:$slot.close|strtotime
					}}
					{{* Try next week *}}
					{{if $end < $now}}
						{{:assign
							start="next %s, %s:00"|args:$slot.day:$slot.open|strtotime
							end="next %s, %s:00"|args:$slot.day:$slot.close|strtotime
						}}
					{{/if}}
				{{/if}}

				{{#foreach count=$event.openings_slots key="i"}}
					{{if $start >= $end}}
						{{:break}}
					{{/if}}
					{{:assign var="slots.%d"|args:$start frequency=$slot.frequency day=$slot.day open=$start|date:'H:i' seats=$event.openings_seats}}
					{{:assign start="%d+(%d*60)"|math:$start:$event.openings_delay}}
				{{/foreach}}

				{{if $slot.frequency === 'this' && $repeat > 0}}
					{{#foreach count=$repeat}}
						{{:assign date=$start|date:'Y-m-d'}}
						{{:assign start="%s, next %s, %s"|args:$date:$slot.day:$slot.open|strtotime}}
						{{#foreach count=$event.openings_slots key="i"}}
							{{:assign var="slots.%d"|args:$start frequency=$slot.frequency day=$slot.day open=$start|date:'H:i' seats=$event.openings_seats}}
							{{:assign start="%d+(%d*60)"|math:$start:$event.openings_delay}}
						{{/foreach}}
					{{/foreach}}
				{{/if}}
			{{/foreach}}
		{{/if}}
	{{/module}}
{{/if}}

{{if !$event.use_openings}}
	{{#load
		type="slot"
		event=$event.key
		where="$$.seats > 0 AND ($$.date IS NULL OR ($$.date > date() OR ($$.date = date() AND $$.open >= strftime('%H:%M'))))"
		order="$$.frequency = 'only' DESC, $$.frequency = 'this' DESC, $$.date, $$.day = 'monday' DESC, $$.day = 'tuesday' DESC, $$.day = 'wednesday' DESC, $$.day = 'thursday' DESC, $$.day = 'friday' DESC, $$.open ASC"}}
		{{if $frequency == 'this'}}
			{{:assign timestamp="%s %s, %s"|args:$frequency:$day:$open|strtotime}}
		{{elseif !$date}}
			{{:assign timestamp="%s %s of this month, %s"|args:$frequency:$day:$open|strtotime}}
		{{else}}
			{{:assign timestamp="%s %s"|args:$date:$open|strtotime}}
		{{/if}}

		{{* Make sure slot is in the future, can't book for past dates *}}
		{{if $frequency !== 'only' && $timestamp < $now|date:'U'}}
			{{if $frequency === 'this'}}
				{{:assign timestamp="next %s, %s"|args:$day:$open|strtotime}}
			{{else}}
				{{:assign timestamp="%s %s of next month, %s"|args:$frequency:$day:$open|strtotime}}
			{{/if}}
		{{/if}}

		{{* This shouldn't happen, but make sure it doesn't *}}
		{{if $timestamp < $now|date:'U'}}
			{{:continue}}
		{{/if}}

		{{:assign .="slots.%d"|args:$timestamp}}

		{{if $repeat > 1 && $frequency !== 'only'}}
			{{if $frequency !== 'this'}}
				{{* Change week repeat into month repeat for monthly events *}}
				{{:assign repeat='round(%d/4.3)'|math:$repeat|intval}}
			{{/if}}
			{{#foreach count=$repeat}}
				{{if $frequency === 'this'}}
					{{:assign timestamp=$timestamp|date:'Y-m-d'|cat:', +1 day'|strtotime}}
					{{:assign date=$timestamp|date:'Y-m-d'}}
				{{else}}
					{{:assign timestamp=$timestamp|date:'Y-m-01'|cat:', +1 month'|strtotime}}
					{{:assign date=$timestamp|date:'Y-m-01'}}
				{{/if}}
				{{:assign timestamp="%s, %s %s, %s"|args:$date:$frequency:$day:$open|strtotime}}
				{{:assign ..="slots.%d"|args:$timestamp}}
			{{/foreach}}
		{{/if}}
	{{/load}}
{{/if}}

{{* take into account the closing dates *}}
{{if $event.use_closings}}
	{{#foreach from=$slots item="slot" key="timestamp"}}
		{{#foreach from=$closed item="closing"}}
			{{if $closing.start <= $timestamp && $closing.end >= $timestamp}}
				{{if $slot.frequency == 'only'}}
					{{* Don't cancel specific dates if they are in a closed time, it's probably an event during a closed time *}}
					{{:break}}
				{{elseif $slot.frequency == 'this'}}
					{{:assign end_date="%d+86400"|math:$closing.end|date:'Y-m-d'}}
					{{:assign new_timestamp="%s, %s %s, %s"|args:$end_date:$slot.frequency:$slot.day:$slot.open|strtotime}}
				{{else}}
					{{:assign end_date="%d+86400"|math:$closing.end|date:'Y-m'}}
					{{:assign new_timestamp="%s, %s %s, %s"|args:$end_date:$slot.frequency:$slot.day:$slot.open|strtotime}}
				{{/if}}
				{{:assign var="slots.%d"|args:$timestamp value=null}}
				{{:assign var="slots.%d"|args:$new_timestamp value=$slot}}
			{{/if}}
		{{/foreach}}
	{{/foreach}}
{{/if}}


{{* Make sure we sort slots by datetime *}}
{{:assign slots=$slots|ksort}}
