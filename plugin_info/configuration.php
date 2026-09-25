<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-network-wired"></i> {{Réseau local}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai d'attente SNMP}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="snmp_timeout" min="1" max="10" placeholder="2">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Secondes d'attente par tentative ; le plugin en fait trois. Une imprimante répond en quelques dizaines de millisecondes, mais une imprimante en veille profonde peut laisser tomber le premier paquet le temps de se réveiller. Deux secondes suffisent : le cron qui relève les imprimantes est partagé avec les autres plugins.}}</span>
			</div>
		</div>
	</fieldset>
</form>
