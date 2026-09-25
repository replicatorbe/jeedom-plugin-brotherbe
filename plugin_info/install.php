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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function brotherbe_install() {
}

/*
 * Une mise à jour qui ajoute des commandes ne les fait pas apparaître toute
 * seule sur les imprimantes déjà créées : createCommands() ne tourne qu'au
 * postSave. Pas de save() pour autant : le coeur exécute cette fonction dans la
 * requête HTTP, et un postSave qui interroge une imprimante éteinte ferait
 * partir la page « Gestion des plugins » en timeout. On crée seulement ce qui
 * manque ; les valeurs viendront du prochain cron.
 */
function brotherbe_update() {
    if (!class_exists('brotherbe')) {
        log::add('brotherbe', 'error', __('Classe du plugin introuvable : les commandes n\'ont pas été mises à jour.', __FILE__));
        return;
    }
    try {
        $count = brotherbe::rebuildCommands();
        log::add('brotherbe', 'info', sprintf(__('Mise à jour : %d imprimante(s) revue(s).', __FILE__), $count));
    } catch (Throwable $e) {
        log::add('brotherbe', 'error', __('Mise à jour des commandes :', __FILE__) . ' ' . $e->getMessage());
    }
}

/*
 * Attention : le coeur appelle cette fonction à la DÉSACTIVATION du plugin,
 * pas seulement à sa désinstallation. Rien à jeter ici : le cache de chaque
 * imprimante part avec elle.
 */
function brotherbe_remove() {
}
