<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('brotherbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter une imprimante}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<legend><i class="fas fa-print"></i> {{Mes imprimantes}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucune imprimante pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Relevez l\'adresse IP de votre imprimante, sur son écran (menu Réseau) ou dans votre box, et réservez-la dans votre routeur : si elle change, le plugin ne la trouvera plus.}}</li>';
			echo '<li>{{Vérifiez que SNMP est activé : c\'est le cas par défaut sur les imprimantes Brother, en lecture avec la communauté « public ».}}</li>';
			echo '<li>{{Cliquez sur « Ajouter une imprimante », saisissez l\'adresse, puis « Tester l\'adresse » pour voir le modèle et le numéro de série avant d\'enregistrer.}}</li>';
			echo '</ol>';
			echo '<span class="help-block" style="margin:8px 0 0 0;">{{Tout se passe sur votre réseau local : aucune donnée ne sort de chez vous, aucun compte n\'est nécessaire. Ce plugin n\'est pas affilié à Brother.}}</span>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<img src="' . $plugin->getPathImgIcon() . '">';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo '<span class="label label-info">' . htmlspecialchars((string) $eqLogic->getConfiguration('ip'), ENT_QUOTES) . '</span> ';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-print"></i><span class="hidden-xs"> {{Imprimante}}</span></a></li>
			<li role="presentation"><a href="#diagtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-stethoscope"></i><span class="hidden-xs"> {{Diagnostic}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= IMPRIMANTE ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Imprimante bureau}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-network-wired"></i> {{Connexion}}</legend>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse IP}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.1.50">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Celle de l'imprimante. Réservez-la dans votre routeur.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Communauté SNMP}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="community" placeholder="public">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{« public » par défaut chez Brother. À changer seulement si vous l'avez modifiée dans l'interface web de l'imprimante.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Technologie}}</label>
								<div class="col-sm-5">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="printer_type">
										<option value="auto">{{Automatique, d'après le modèle}}</option>
										<option value="laser">{{Laser}}</option>
										<option value="ink">{{Jet d'encre}}</option>
									</select>
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Laser et jet d'encre partagent les mêmes codes sans leur donner le même sens. Brother l'écrit dans le nom du modèle : L pour le laser, J ou T pour le jet d'encre.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label">{{Relever toutes les}}</label>
								<div class="col-sm-5">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="interval">
										<option value="1">{{minute}}</option>
										<option value="5">{{5 minutes}}</option>
										<option value="15">{{15 minutes}}</option>
										<option value="30">{{30 minutes}}</option>
										<option value="60">{{heure}}</option>
									</select>
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Cinq minutes suffisent : un toner ne se vide pas en une minute. La minute sert surtout à suivre une erreur papier de près.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<a class="btn btn-default btn-sm" id="bt_brotherbeProbe"><i class="fas fa-search"></i> {{Tester l'adresse}}</a>
									<a class="btn btn-default btn-sm" id="bt_brotherbeRefresh"><i class="fas fa-sync"></i> {{Relever maintenant}}</a>
									<span id="span_brotherbeStatus" style="margin-left:10px;"></span>
								</div>
							</div>
						</fieldset>
					</form>

					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-info-circle"></i> {{Identité}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Modèle}}</label>
								<div class="col-sm-9"><span class="form-control-static" id="span_brotherbeModel">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Numéro de série}}</label>
								<div class="col-sm-9"><span class="form-control-static" id="span_brotherbeSerial">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Firmware}}</label>
								<div class="col-sm-9"><span class="form-control-static" id="span_brotherbeFirmware">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse MAC}}</label>
								<div class="col-sm-9"><span class="form-control-static" id="span_brotherbeMac">-</span></div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================== DIAGNOSTIC ========================= -->
			<div role="tabpanel" class="tab-pane" id="diagtab">
				<br>
				<div class="col-xs-12">
					<div class="alert alert-info" id="div_brotherbeState">{{Chargement…}}</div>
					<legend><i class="fas fa-code"></i> {{Dernière réponse de l'imprimante}}</legend>
					<span class="help-block">{{Les valeurs SNMP telles que l'imprimante les a rendues, avant décodage ; les blocs Brother sont en hexadécimal. C'est la pièce à joindre en cas de valeur douteuse ou de modèle mal reconnu.}}</span>
					<pre id="pre_brotherbeRaw" style="max-height:420px;overflow:auto;"></pre>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<span class="help-block">{{Seules les données que votre imprimante déclare ont une commande : une monochrome n'a pas de toner cyan. Les commandes de détail sont masquées, la tuile les résume ; elles restent historisées et utilisables dans les scénarios.}}</span>
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:280px;">{{Nom}}</th>
								<th style="width:120px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:160px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'brotherbe', 'js', 'brotherbe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
