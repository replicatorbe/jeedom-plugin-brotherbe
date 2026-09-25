/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ================================================================== OUTILS */

function brotherbeEl(_id) {
  return document.getElementById(_id)
}

function brotherbeAjax(_action, _data, _success) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/brotherbe/core/ajax/brotherbe.ajax.php',
    data: payload,
    dataType: 'json',
    /* Le callback d'erreur de domUtils.ajax ne reçoit qu'un seul argument. */
    error: function (error) {
      brotherbeStatus('', '')
      domUtils.handleAjaxError(error)
    },
    success: _success
  })
}

function brotherbeStatus(_text, _level) {
  var span = brotherbeEl('span_brotherbeStatus')
  if (span === null) { return }
  span.textContent = _text
  span.className = _level ? 'label label-' + _level : ''
}

function brotherbeConfigValue(_key) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="' + _key + '"]')
  return input === null ? '' : String(input.value).trim()
}

/* ================================================================ IDENTITÉ */

function brotherbeShowInfo(_info) {
  var info = _info || {}
  var type = info.type === 'ink' ? "{{jet d'encre}}" : (info.type === 'laser' ? '{{laser}}' : '')
  brotherbeEl('span_brotherbeModel').textContent = info.model ? info.model + (type ? ' (' + type + ')' : '') : '-'
  brotherbeEl('span_brotherbeSerial').textContent = info.serial || '-'
  brotherbeEl('span_brotherbeFirmware').textContent = info.firmware || '-'
  brotherbeEl('span_brotherbeMac').textContent = info.mac || '-'
}

/* Interroge l'adresse saisie sans rien enregistrer. */
function brotherbeProbe() {
  var ip = brotherbeConfigValue('ip')
  if (ip === '') {
    jeedomUtils.showAlert({ message: "{{Saisissez d'abord l'adresse IP de l'imprimante.}}", level: 'warning' })
    return
  }
  brotherbeStatus('{{Interrogation…}}', 'default')
  brotherbeAjax('probe', {
    ip: ip,
    community: brotherbeConfigValue('community'),
    printer_type: brotherbeConfigValue('printer_type')
  }, function (result) {
    var data = result.result || {}
    brotherbeShowInfo(data.info)
    var state = data.state || {}
    brotherbeStatus((data.info && data.info.model ? data.info.model : '{{Imprimante}}') + ' {{trouvée}}' + (state.status ? ' · ' + state.status : ''), 'success')
    jeedomUtils.showAlert({ message: '{{Imprimante trouvée. Sauvegardez pour créer ses commandes.}}', level: 'success' })
  })
}

/* ============================================================== DIAGNOSTIC */

function brotherbeClear() {
  brotherbeShowInfo({})
  var raw = brotherbeEl('pre_brotherbeRaw')
  if (raw !== null) { raw.textContent = '' }
  var state = brotherbeEl('div_brotherbeState')
  if (state !== null) {
    state.className = 'alert alert-info'
    state.textContent = '{{Chargement…}}'
  }
}

function brotherbeRender(_data) {
  if (isset(_data) && _data.info) { brotherbeShowInfo(_data.info) }

  var state = brotherbeEl('div_brotherbeState')
  if (state !== null) {
    if (!isset(_data) || _data.fetchedAt === '') {
      state.className = 'alert alert-warning'
      state.textContent = "{{Aucun relevé pour le moment. Enregistrez l'imprimante, puis utilisez « Relever maintenant ».}}"
    } else if (_data.online) {
      state.className = 'alert alert-success'
      state.textContent = '{{Dernier relevé :}} ' + _data.fetchedAt
        + (_data.info && _data.info.legacy ? ' · {{format ancien du bloc de maintenance}}' : '')
    } else {
      state.className = 'alert alert-warning'
      state.textContent = '{{Injoignable depuis}} ' + _data.failures + ' {{tentative(s)}}'
        + (_data.problem ? ' — ' + _data.problem : '')
        + ' · {{dernier relevé réussi :}} ' + (_data.fetchedAt || '{{jamais}}')
    }
  }

  var raw = brotherbeEl('pre_brotherbeRaw')
  if (raw !== null) {
    var text = ''
    if (isset(_data) && _data.raw) { text += JSON.stringify(_data.raw, null, 2) }
    if (isset(_data) && _data.values && Object.keys(_data.values).length > 0) {
      text += '\n\n// {{Valeurs décodées}}\n' + JSON.stringify(_data.values, null, 2)
    }
    raw.textContent = text
  }
}

function brotherbeLoad(_id) {
  if (!isset(_id) || _id === '') { return }
  brotherbeAjax('data', { id: _id }, function (result) {
    brotherbeRender(result.result)
  })
}

function brotherbeRefresh() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  if (id === null || id.value === '') {
    jeedomUtils.showAlert({ message: "{{Enregistrez l'imprimante avant de la relever.}}", level: 'warning' })
    return
  }
  brotherbeStatus('{{Lecture en cours…}}', 'default')
  brotherbeAjax('refresh', { id: id.value }, function (result) {
    brotherbeStatus('{{Relevé effectué.}}', 'success')
    brotherbeRender(result.result)
  })
}

/* ==================================================== APPELÉES PAR LE COEUR */

function printEqLogic(_eqLogic) {
  /* Le coeur ne réinitialise que les .eqLogicAttr : sans cela, l'identité et
     le diagnostic garderaient ceux de l'imprimante ouverte précédemment. */
  brotherbeClear()
  brotherbeStatus('', '')
  if (isset(_eqLogic.id) && _eqLogic.id !== '') {
    brotherbeLoad(_eqLogic.id)
  }
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes :
     l'historique est perdu et les scénarios pointent dans le vide. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (init(_cmd.type) === 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  /* Ligne créée en DOM : insertAdjacentHTML sur une table génère un <tbody>
     par insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  /* L'ordre compte : changeType après setJeeValues, jamais l'inverse. */
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== ÉCOUTEURS */

/* Les pages de plugin sont chargées en ajax : DOMContentLoaded a déjà eu lieu.
   Écouteurs posés par délégation sur un conteneur qui existe déjà. */
var brotherbeContainer = document.getElementById('div_pageContainer') || document.body

brotherbeContainer.addEventListener('click', function (_event) {
  var target = _event.target
  if (target === null) { return }

  if (target.closest('#bt_brotherbeProbe') !== null) {
    _event.preventDefault()
    brotherbeProbe()
    return
  }
  if (target.closest('#bt_brotherbeRefresh') !== null) {
    _event.preventDefault()
    brotherbeRefresh()
  }
})
