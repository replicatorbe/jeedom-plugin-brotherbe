<?php
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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    /* isConnect('admin') est une égalité stricte de profil, pas une hiérarchie. */
    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    /* Depuis la 4.4, ajax::getToken() est déprécié et rend une chaîne vide :
     * l'authentification repose sur la session. */
    ajax::init();

    /* Interroge une adresse sans rien enregistrer : l'utilisateur voit le
     * modèle et le numéro de série avant de sauvegarder. */
    if (init('action') == 'probe') {
        unautorizedInDemo();
        ajax::success(brotherbe::probe(trim(init('ip')), init('community'), init('printer_type')));
    }

    /* Relevé à la demande, depuis le bouton de la page. */
    if (init('action') == 'refresh') {
        unautorizedInDemo();
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'brotherbe') {
            throw new Exception(__('Imprimante introuvable :', __FILE__) . ' ' . init('id'));
        }
        $eqLogic->forcePoll();
        ajax::success($eqLogic->toAjax());
    }

    /* Alimente le diagnostic. Lit le cache, n'interroge jamais l'imprimante :
     * ouvrir un équipement ne doit pas dépendre de son état. */
    if (init('action') == 'data') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'brotherbe') {
            throw new Exception(__('Imprimante introuvable :', __FILE__) . ' ' . init('id'));
        }
        ajax::success($eqLogic->toAjax());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

/* Throwable et non Exception : en PHP 8, une Error n'hérite pas d'Exception et
 * produirait un HTTP 500 sans corps JSON. */
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
