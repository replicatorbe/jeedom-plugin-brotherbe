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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/brotherbeSnmp.class.php';

/*
 * Relevé local des imprimantes Brother par SNMP.
 *
 * Tout passe par une requête GET groupée, sans compte ni cloud. Les OID
 * standards (RFC 3805 et HOST-RESOURCES) donnent l'état et les erreurs ; les
 * OID propriétaires Brother rendent trois blocs binaires — compteurs,
 * maintenance, prochain entretien — faits d'enregistrements de sept octets :
 * un code, 01 04, puis une valeur sur quatre octets. Le bloc se termine par FF.
 *
 * Les tables de codes viennent de la bibliothèque Python « brother » utilisée
 * par Home Assistant : le plugin rend donc les mêmes chiffres que HA.
 *
 * Un équipement Jeedom = une imprimante. Les commandes ne sont créées que pour
 * les données que l'imprimante déclare : une monochrome n'aura jamais de toner
 * cyan, une jet d'encre jamais de tambour.
 */
class brotherbe extends eqLogic {

    /* Vrai quand une commande a été créée depuis le chargement de l'objet, et
     * jusqu'à postAjax(). Souligné initial obligatoire : sans lui, DB::save()
     * prendrait la propriété pour une colonne de la table eqLogic. */
    private $_cmdCreated = false;

    /* ============================================================= LES OID */

    const OID_CHARSET        = '1.3.6.1.2.1.43.7.1.1.4.1.1';
    const OID_COUNTERS       = '1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.10.0';
    const OID_FIRMWARE       = '1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.17.0';
    const OID_MAC            = '1.3.6.1.2.1.2.2.1.6.1';
    const OID_MAINTENANCE    = '1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.8.0';
    const OID_MODEL          = '1.3.6.1.4.1.2435.2.3.9.1.1.7.0';
    const OID_NEXTCARE       = '1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.11.0';
    const OID_PAGE_COUNT     = '1.3.6.1.2.1.43.10.2.1.4.1.1';
    const OID_SERIAL         = '1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.1.0';
    const OID_STATUS         = '1.3.6.1.4.1.2435.2.3.9.4.2.1.5.4.5.2.0';
    const OID_UPTIME         = '1.3.6.1.2.1.1.3.0';
    const OID_DEVICE_STATUS  = '1.3.6.1.2.1.25.3.2.1.5.1';
    const OID_PRINTER_STATUS = '1.3.6.1.2.1.25.3.5.1.1.1';
    const OID_PRINTER_ERRORS = '1.3.6.1.2.1.25.3.5.1.2.1';

    /* Les trois blocs binaires, qu'on affiche en hexadécimal au diagnostic. */
    const BINARY_OIDS = array(
        self::OID_COUNTERS, self::OID_MAINTENANCE, self::OID_NEXTCARE,
        self::OID_MAC, self::OID_PRINTER_ERRORS,
    );

    const TYPE_AUTO  = 'auto';
    const TYPE_LASER = 'laser';
    const TYPE_INK   = 'ink';

    /* ====================================================== LES CONSTANTES */

    const DEFAULT_COMMUNITY = 'public';
    const DEFAULT_INTERVAL = 5;
    const DEFAULT_TIMEOUT = 2;
    const RETRIES = 2;

    /* Temps que cron() s'autorise, toutes imprimantes confondues. */
    const CRON_BUDGET = 45;
    const FORCE_MIN_INTERVAL = 10;
    const FAILURES_BEFORE_BACKOFF = 3;
    const BACKOFF_MIN = 300;
    const BACKOFF_MAX = 3600;

    /* L'horloge de l'imprimante et celle de Jeedom dérivent l'une de l'autre :
     * en deçà de cet écart, la date de démarrage recalculée est la même. */
    const BOOT_TOLERANCE = 120;

    /* Pendant une erreur (papier, bourrage…), on relève chaque minute, quel
     * que soit l'intervalle choisi : l'erreur doit s'effacer du dashboard dès
     * qu'on a rechargé le bac, pas cinq minutes plus tard. */
    const ERROR_INTERVAL = 60;

    /* Seuils d'alerte posés à la création des commandes en pour cent. Le toner
     * et l'encre se commandent : on prévient tôt. Les pièces d'usure durent des
     * dizaines de milliers de pages : on ne prévient qu'à l'approche de la fin. */
    const ALERTS_SUPPLY = array('warning' => 20, 'danger' => 10);
    const ALERTS_WEAR   = array('warning' => 10, 'danger' => 5);

    /* ======================================================= LES TABLES */

    /* Bloc des compteurs, commun aux deux familles. */
    const VALUES_COUNTERS = array(
        '00' => 'page_counter',
        '01' => 'bw_counter',
        '02' => 'color_counter',
        '06' => 'duplex_unit_pages_counter',
        '12' => 'black_counter',
        '13' => 'cyan_counter',
        '14' => 'magenta_counter',
        '15' => 'yellow_counter',
        '16' => 'image_counter',
    );

    /* Les codes 31 à 34 et 63 (état du toner et du tambour) ne sont pas
     * documentés et Home Assistant ne les expose pas : ils restent visibles
     * dans la réponse brute du diagnostic, pas en commande. De même pour 81 à
     * 84, un niveau grossier que Brother double de la durée de vie en 6f. */
    const VALUES_LASER_MAINTENANCE = array(
        '11' => 'drum_counter',
        '41' => 'drum_remaining_life',
        '69' => 'belt_unit_remaining_life',
        '6a' => 'fuser_remaining_life',
        '6b' => 'laser_remaining_life',
        '6c' => 'pf_kit_mp_remaining_life',
        '6d' => 'pf_kit_1_remaining_life',
        '6f' => 'black_toner_remaining',
        '70' => 'cyan_toner_remaining',
        '71' => 'magenta_toner_remaining',
        '72' => 'yellow_toner_remaining',
        '73' => 'cyan_drum_counter',
        '74' => 'magenta_drum_counter',
        '75' => 'yellow_drum_counter',
        '7e' => 'black_drum_counter',
        '79' => 'cyan_drum_remaining_life',
        '7a' => 'magenta_drum_remaining_life',
        '7b' => 'yellow_drum_remaining_life',
        '80' => 'black_drum_remaining_life',
        'a1' => 'black_toner_remaining',
        'a2' => 'cyan_toner_remaining',
        'a3' => 'magenta_toner_remaining',
        'a4' => 'yellow_toner_remaining',
    );

    const VALUES_INK_MAINTENANCE = array(
        '6f' => 'black_ink_remaining',
        '70' => 'cyan_ink_remaining',
        '71' => 'magenta_ink_remaining',
        '72' => 'yellow_ink_remaining',
        '85' => 'ink_capture_box_remaining_life',
        'a1' => 'black_ink_remaining',
        'a2' => 'cyan_ink_remaining',
        'a3' => 'magenta_ink_remaining',
        'a4' => 'yellow_ink_remaining',
    );

    const VALUES_LASER_NEXTCARE = array(
        '73' => 'laser_unit_remaining_pages',
        '77' => 'pf_kit_1_remaining_pages',
        '82' => 'drum_remaining_pages',
        '86' => 'pf_kit_mp_remaining_pages',
        '88' => 'belt_unit_remaining_pages',
        '89' => 'fuser_unit_remaining_pages',
        'a4' => 'black_drum_remaining_pages',
        'a5' => 'cyan_drum_remaining_pages',
        'a6' => 'magenta_drum_remaining_pages',
        'a7' => 'yellow_drum_remaining_pages',
    );

    /* Grandeurs rendues en centièmes de pour cent. */
    const PERCENT_VALUES = array(
        'belt_unit_remaining_life', 'black_drum_remaining_life', 'black_ink_remaining',
        'black_toner_remaining', 'cyan_drum_remaining_life', 'cyan_ink_remaining',
        'cyan_toner_remaining', 'drum_remaining_life', 'fuser_remaining_life',
        'laser_remaining_life', 'magenta_drum_remaining_life', 'magenta_ink_remaining',
        'magenta_toner_remaining', 'pf_kit_1_remaining_life', 'pf_kit_mp_remaining_life',
        'yellow_drum_remaining_life', 'yellow_ink_remaining', 'yellow_toner_remaining',
    );

    /* hrPrinterDetectedErrorState, RFC 3805 : un bit par erreur, numérotés à
     * partir du bit de poids fort du premier octet. */
    const PRINTER_ERRORS = array(
        'low_paper', 'no_paper', 'low_toner', 'no_toner', 'door_open', 'jammed',
        'offline', 'service_requested', 'input_tray_missing', 'output_tray_missing',
        'marker_supply_missing', 'output_near_full', 'output_full', 'input_tray_empty',
        'overdue_prevent_maint',
    );

    /* prtLocalizationCharacterSet vers iconv. roman8 est le défaut de Brother. */
    const CHARSETS = array(
        5    => 'ISO-8859-2',
        8    => 'ISO-8859-5',
        12   => 'ISO-8859-9',
        106  => 'UTF-8',
        2004 => 'HP-ROMAN8',
    );

    /*
     * Ce qu'on sait créer, dans l'ordre de l'onglet Commandes. Une ligne par
     * donnée : identifiant logique, nom, unité. Seules les lignes que
     * l'imprimante renseigne donnent une commande.
     */
    public static function catalogue() {
        return array(
            'black_toner_remaining'          => array('toner_noir', 'Toner noir', '%'),
            'cyan_toner_remaining'           => array('toner_cyan', 'Toner cyan', '%'),
            'magenta_toner_remaining'        => array('toner_magenta', 'Toner magenta', '%'),
            'yellow_toner_remaining'         => array('toner_jaune', 'Toner jaune', '%'),
            'black_ink_remaining'            => array('encre_noire', 'Encre noire', '%'),
            'cyan_ink_remaining'             => array('encre_cyan', 'Encre cyan', '%'),
            'magenta_ink_remaining'          => array('encre_magenta', 'Encre magenta', '%'),
            'yellow_ink_remaining'           => array('encre_jaune', 'Encre jaune', '%'),
            'ink_capture_box_remaining_life' => array('boite_recuperation', 'Boîte de récupération encre', '%'),

            'drum_remaining_life'            => array('tambour_restant', 'Tambour restant', '%'),
            'drum_remaining_pages'           => array('tambour_pages_restantes', 'Tambour pages restantes', 'pages'),
            'drum_counter'                   => array('tambour_compteur', 'Tambour pages imprimées', 'pages'),
            'black_drum_remaining_life'      => array('tambour_noir_restant', 'Tambour noir restant', '%'),
            'black_drum_remaining_pages'     => array('tambour_noir_pages_restantes', 'Tambour noir pages restantes', 'pages'),
            'black_drum_counter'             => array('tambour_noir_compteur', 'Tambour noir pages imprimées', 'pages'),
            'cyan_drum_remaining_life'       => array('tambour_cyan_restant', 'Tambour cyan restant', '%'),
            'cyan_drum_remaining_pages'      => array('tambour_cyan_pages_restantes', 'Tambour cyan pages restantes', 'pages'),
            'cyan_drum_counter'              => array('tambour_cyan_compteur', 'Tambour cyan pages imprimées', 'pages'),
            'magenta_drum_remaining_life'    => array('tambour_magenta_restant', 'Tambour magenta restant', '%'),
            'magenta_drum_remaining_pages'   => array('tambour_magenta_pages_restantes', 'Tambour magenta pages restantes', 'pages'),
            'magenta_drum_counter'           => array('tambour_magenta_compteur', 'Tambour magenta pages imprimées', 'pages'),
            'yellow_drum_remaining_life'     => array('tambour_jaune_restant', 'Tambour jaune restant', '%'),
            'yellow_drum_remaining_pages'    => array('tambour_jaune_pages_restantes', 'Tambour jaune pages restantes', 'pages'),
            'yellow_drum_counter'            => array('tambour_jaune_compteur', 'Tambour jaune pages imprimées', 'pages'),
            'belt_unit_remaining_life'       => array('courroie_restant', 'Courroie restant', '%'),
            'belt_unit_remaining_pages'      => array('courroie_pages_restantes', 'Courroie pages restantes', 'pages'),
            'fuser_remaining_life'           => array('fusion_restant', 'Unité de fusion restant', '%'),
            'fuser_unit_remaining_pages'     => array('fusion_pages_restantes', 'Unité de fusion pages restantes', 'pages'),
            'laser_remaining_life'           => array('laser_restant', 'Unité laser restant', '%'),
            'laser_unit_remaining_pages'     => array('laser_pages_restantes', 'Unité laser pages restantes', 'pages'),
            'pf_kit_1_remaining_life'        => array('kit_bac1_restant', 'Kit alimentation bac 1 restant', '%'),
            'pf_kit_1_remaining_pages'       => array('kit_bac1_pages_restantes', 'Kit alimentation bac 1 pages restantes', 'pages'),
            'pf_kit_mp_remaining_life'       => array('kit_bacmp_restant', 'Kit alimentation bac MP restant', '%'),
            'pf_kit_mp_remaining_pages'      => array('kit_bacmp_pages_restantes', 'Kit alimentation bac MP pages restantes', 'pages'),

            'page_counter'                   => array('pages', 'Pages imprimées', 'pages'),
            'bw_counter'                     => array('pages_nb', 'Pages noir et blanc', 'pages'),
            'color_counter'                  => array('pages_couleur', 'Pages couleur', 'pages'),
            'duplex_unit_pages_counter'      => array('pages_recto_verso', 'Pages recto verso', 'pages'),
            'black_counter'                  => array('compteur_noir', 'Compteur noir', 'pages'),
            'cyan_counter'                   => array('compteur_cyan', 'Compteur cyan', 'pages'),
            'magenta_counter'                => array('compteur_magenta', 'Compteur magenta', 'pages'),
            'yellow_counter'                 => array('compteur_jaune', 'Compteur jaune', 'pages'),
            'image_counter'                  => array('images', 'Images imprimées', 'images'),
        );
    }

    public static function errorLabels() {
        return array(
            'low_paper'             => __('Papier bientôt épuisé', __FILE__),
            'no_paper'              => __('Plus de papier', __FILE__),
            'low_toner'             => __('Toner bas', __FILE__),
            'no_toner'              => __('Toner vide', __FILE__),
            'door_open'             => __('Capot ouvert', __FILE__),
            'jammed'                => __('Bourrage papier', __FILE__),
            'offline'               => __('Imprimante hors ligne', __FILE__),
            'service_requested'     => __('Intervention requise', __FILE__),
            'input_tray_missing'    => __('Bac papier absent', __FILE__),
            'output_tray_missing'   => __('Bac de sortie absent', __FILE__),
            'marker_supply_missing' => __('Consommable absent', __FILE__),
            'output_near_full'      => __('Bac de sortie presque plein', __FILE__),
            'output_full'           => __('Bac de sortie plein', __FILE__),
            'input_tray_empty'      => __('Bac papier vide', __FILE__),
            'overdue_prevent_maint' => __('Entretien préventif en retard', __FILE__),
        );
    }

    public static function printerStatusLabel($_code) {
        $labels = array(
            1 => __('Autre', __FILE__),
            2 => __('Inconnu', __FILE__),
            3 => __('Au repos', __FILE__),
            4 => __('Impression', __FILE__),
            5 => __('Préchauffage', __FILE__),
        );
        return isset($labels[(int) $_code]) ? $labels[(int) $_code] : null;
    }

    public static function deviceStatusLabel($_code) {
        $labels = array(
            1 => __('Inconnu', __FILE__),
            2 => __('En service', __FILE__),
            3 => __('Avertissement', __FILE__),
            4 => __('En test', __FILE__),
            5 => __('En panne', __FILE__),
        );
        return isset($labels[(int) $_code]) ? $labels[(int) $_code] : null;
    }

    /* =============================================================== WIDGET */

    public static function templateWidget() {
        return array('info' => array('binary' => array(
            'connexion' => array('template' => 'tmplicon', 'replace' => array(
                '#_icon_on_#'  => "<i class='icon_green fas fa-plug'></i>",
                '#_icon_off_#' => "<i class='icon_red fas fa-plug'></i>",
            )),
        )));
    }

    /* ================================================================= CRON */

    /*
     * Toutes les minutes, parce que c'est le plus petit pas de Jeedom ; chaque
     * imprimante décide ensuite si son intervalle est écoulé. Le budget protège
     * d'un réseau qui ne répond plus, pas du nombre d'imprimantes.
     */
    public static function cron() {
        $deadline = microtime(true) + self::CRON_BUDGET;

        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                if (microtime(true) > $deadline) {
                    log::add(__CLASS__, 'debug', __('Budget du cron épuisé, les imprimantes restantes attendront la minute suivante.', __FILE__));
                    break;
                }
                if (!$eqLogic->shouldPoll()) {
                    continue;
                }
                $eqLogic->update();
            } catch (Throwable $e) {
                /* Une imprimante en échec ne doit pas priver les autres de leur tour. */
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* ================================================ CYCLE DE VIE eqLogic */

    /*
     * Aucune exception ici : le coeur crée l'équipement avec son seul nom, et
     * une validation stricte rendrait le bouton « Ajouter » inopérant.
     */
    public function preSave() {
        if ($this->getId() == '') {
            $this->setIsEnable(1);
            $this->setIsVisible(1);
        }
        if (trim((string) $this->getConfiguration('community', '')) === '') {
            $this->setConfiguration('community', self::DEFAULT_COMMUNITY);
        }
        if (!in_array($this->getConfiguration('printer_type', ''), array(self::TYPE_AUTO, self::TYPE_LASER, self::TYPE_INK), true)) {
            $this->setConfiguration('printer_type', self::TYPE_AUTO);
        }
        if ((int) $this->getConfiguration('interval', 0) <= 0) {
            $this->setConfiguration('interval', self::DEFAULT_INTERVAL);
        }
        $this->setConfiguration('ip', trim((string) $this->getConfiguration('ip', '')));
    }

    public function postSave() {
        $this->createCommands();

        if (!$this->isConfigured()) {
            return;
        }

        /*
         * Relevé immédiat, pour que les valeurs apparaissent à l'enregistrement
         * et non au prochain cron. Une imprimante éteinte ne doit pas faire
         * échouer l'enregistrement pour autant.
         */
        try {
            if ($this->getCache('signature', '') !== $this->signature()) {
                $this->setCache('signature', $this->signature());
                $this->clearFailure();
            }
            $this->setCache('polled_at', time());
            $this->update(true);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    /*
     * Appelé par le coeur après une sauvegarde depuis la page, une fois qu'il
     * a renuméroté de 0 à n les commandes du formulaire. Celles que postSave()
     * vient de créer n'étaient pas dans le formulaire : elles gardent leur
     * numéro, qui se retrouve en double avec une commande renumérotée. On
     * rétablit l'ordre après coup.
     */
    public function postAjax() {
        if ($this->_cmdCreated) {
            $this->reorderCommands();
            $this->_cmdCreated = false;
        }
    }

    public function isConfigured() {
        return trim((string) $this->getConfiguration('ip', '')) !== '';
    }

    /* Ce qui, en changeant, oblige à repartir de zéro. */
    public function signature() {
        return $this->getConfiguration('ip') . '|' . $this->getConfiguration('community')
            . '|' . $this->getConfiguration('printer_type');
    }

    /* ============================================================ COMMANDES */

    /*
     * Création idempotente. On ne récrit jamais une commande existante : son
     * nom, sa visibilité et son historisation appartiennent à l'utilisateur.
     */
    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }

        $this->_cmdCreated = true;
        $cmd = new brotherbeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);

        /* Unicité (eqLogic_id, name) en base : un nom déjà pris ferait échouer
         * tout l'enregistrement de l'équipement, pas seulement cette commande. */
        $name = __($_name, __FILE__);
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);

        /* Masquées par défaut : la tuile répond seule à la question qu'on pose
         * à une imprimante, « reste-t-il du toner, et va-t-elle bien ? ». Le
         * détail attend dans l'onglet Commandes. */
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 0);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);

        if (isset($_options['order']))    { $cmd->setOrder($_options['order']); }
        if (!empty($_options['unite']))   { $cmd->setUnite($_options['unite']); }
        if (!empty($_options['template'])) {
            $cmd->setTemplate('dashboard', $_options['template']);
            $cmd->setTemplate('mobile', $_options['template']);
        }
        if (!empty($_options['alerts'])) {
            self::applyAlerts($cmd, $_options['alerts']);
        }
        $cmd->save();
        return $cmd;
    }

    /*
     * Les seuils sont ceux du coeur : la commande passe en « warning » puis en
     * « danger », ce qui colore son widget, alimente les alertes de Jeedom et
     * se règle ensuite dans la configuration avancée de la commande.
     */
    private static function applyAlerts($_cmd, $_levels) {
        $_cmd->setAlert('warningif', '#value# <= ' . (int) $_levels['warning']);
        $_cmd->setAlert('dangerif', '#value# <= ' . (int) $_levels['danger']);
        /* Posés une fois : un seuil que l'utilisateur aura vidé ne doit pas
         * revenir au prochain enregistrement. */
        $_cmd->setConfiguration('brotherbe_alerts', 1);
    }

    /* Les seuils qui conviennent à une commande, ou null. */
    public static function alertsFor($_logicalId, $_unit) {
        if ($_unit !== '%') {
            return null;
        }
        if (strpos($_logicalId, 'toner_') === 0 || strpos($_logicalId, 'encre_') === 0) {
            return self::ALERTS_SUPPLY;
        }
        return self::ALERTS_WEAR;
    }

    /*
     * Les commandes fixes, présentes sur toute imprimante, puis celles que la
     * dernière réponse a fait connaître. Les secondes sont mémorisées dans le
     * cache : sans cela, un enregistrement fait pendant que l'imprimante est
     * éteinte ne saurait plus lesquelles créer.
     *
     * Les numéros d'ordre posés ici ne servent qu'à la création ; l'ordre
     * final est rétabli par reorderCommands().
     */
    public function createCommands() {
        /* On distingue « créée pendant cet appel », qui réordonne tout de
         * suite, de « créée depuis le chargement de l'objet », que postAjax()
         * consulte : sans cela, chaque appel suivant réordonnerait et
         * défairait un ordre choisi à la main. */
        $createdBefore = $this->_cmdCreated;
        $this->_cmdCreated = false;
        $order = 0;

        $this->addCmdIfMissing('resume', 'Imprimante', 'info', 'string', array(
            'order' => $order++, 'isVisible' => 1,
            'template' => __CLASS__ . '::' . __CLASS__,
        ));
        $this->addCmdIfMissing('etat', 'État', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('etat_imprimante', 'État impression', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('etat_appareil', 'État appareil', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('en_erreur', 'En erreur', 'info', 'binary', array('order' => $order++, 'isHistorized' => 1));
        $this->addCmdIfMissing('erreurs', 'Erreurs', 'info', 'string', array('order' => $order++));

        $known = $this->getCache('known', array());
        $order = 10;
        foreach (self::catalogue() as $key => $definition) {
            $order++;
            if (!in_array($key, $known, true)) {
                continue;
            }
            $alerts = self::alertsFor($definition[0], $definition[2]);
            $cmd = $this->addCmdIfMissing($definition[0], $definition[1], 'info', 'numeric', array(
                'order' => $order, 'isHistorized' => 1, 'unite' => $definition[2], 'alerts' => $alerts,
            ));
            /* Rattrapage des commandes créées avant les seuils : une seule
             * fois, et seulement si l'utilisateur n'en a posé aucun. */
            if ($alerts !== null && (int) $cmd->getConfiguration('brotherbe_alerts', 0) !== 1) {
                if ($cmd->getAlert('warningif') == '' && $cmd->getAlert('dangerif') == '') {
                    self::applyAlerts($cmd, $alerts);
                } else {
                    $cmd->setConfiguration('brotherbe_alerts', 1);
                }
                $cmd->save();
            }
        }

        /* Déduits du compteur de pages, donc créés avec lui. */
        if (in_array('page_counter', $known, true)) {
            $this->addCmdIfMissing('pages_jour', 'Pages du jour', 'info', 'numeric', array(
                'order' => 90, 'isHistorized' => 1, 'unite' => 'pages',
            ));
            $this->addCmdIfMissing('pages_mois', 'Pages du mois', 'info', 'numeric', array(
                'order' => 91, 'isHistorized' => 1, 'unite' => 'pages',
            ));
        }

        $order = 100;
        $this->addCmdIfMissing('dernier_demarrage', 'Dernier démarrage', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('en_ligne', 'En ligne', 'info', 'binary', array(
            'order' => $order++, 'isHistorized' => 1, 'template' => __CLASS__ . '::connexion',
        ));
        $this->addCmdIfMissing('rafraichir', 'Rafraîchir', 'action', 'other', array(
            'order' => $order++, 'isVisible' => 1,
        ));

        if ($this->_cmdCreated) {
            $this->reorderCommands();
        }
        $this->_cmdCreated = $this->_cmdCreated || $createdBefore;
    }

    /*
     * Remet les commandes dans l'ordre du plugin. Nécessaire parce que la page
     * de l'équipement renumérote toutes les commandes de 0 à n à chaque
     * sauvegarde, selon leur rang dans le tableau : les plages fixes n'y
     * survivent pas, et une commande née ensuite — « Pages du jour » au
     * premier relevé, un toner cyan le jour d'un changement de modèle —
     * atterrirait après « Rafraîchir ». On ne le fait qu'à la création d'une
     * commande, pour ne pas défaire à chaque relevé un ordre choisi à la main.
     */
    public function reorderCommands() {
        $rank = array('resume' => 0, 'etat' => 1, 'etat_imprimante' => 2, 'etat_appareil' => 3,
                      'en_erreur' => 4, 'erreurs' => 5);
        $i = 10;
        foreach (self::catalogue() as $definition) {
            $rank[$definition[0]] = ++$i;
        }
        $rank['pages_jour'] = 90;
        $rank['pages_mois'] = 91;
        $rank['dernier_demarrage'] = 100;
        $rank['en_ligne'] = 101;
        $rank['rafraichir'] = 102;

        /* Les commandes inconnues du plugin, ajoutées à la main, gardent leur
         * place relative, en fin de liste. */
        $list = array();
        foreach (cmd::byEqLogicId($this->getId()) as $cmd) {
            $key = isset($rank[$cmd->getLogicalId()]) ? $rank[$cmd->getLogicalId()] : 1000 + (int) $cmd->getOrder();
            $list[] = array($key, $cmd);
        }
        usort($list, function ($a, $b) { return $a[0] - $b[0]; });

        $order = 0;
        foreach ($list as $item) {
            if ((int) $item[1]->getOrder() !== $order) {
                $item[1]->setOrder($order);
                $item[1]->save();
            }
            $order++;
        }
    }

    /* Rattrape les imprimantes déjà créées quand une mise à jour du plugin
     * ajoute des commandes. Hors ligne : voir plugin_info/install.php. */
    public static function rebuildCommands() {
        $count = 0;
        foreach (self::byType(__CLASS__) as $eqLogic) {
            try {
                $eqLogic->createCommands();
                $count++;
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
        return $count;
    }

    /*
     * Écriture d'une valeur. Ce nom, et pas setCmd() : à l'enregistrement d'un
     * équipement, utils::a2o() appelle « set » suivi de chaque clé du
     * formulaire, et la page envoie toujours une clé « cmd ».
     */
    private function publishCmd($_logicalId, $_value) {
        if ($_value === null) {
            return;
        }
        $cmd = $this->getCmd(null, $_logicalId);
        if (!is_object($cmd)) {
            return;
        }
        $this->checkAndUpdateCmd($cmd, $_value);
    }

    /* ============================================================= DÉCODAGE */

    public static function oids() {
        return array(
            self::OID_CHARSET, self::OID_COUNTERS, self::OID_FIRMWARE, self::OID_MAC,
            self::OID_MAINTENANCE, self::OID_MODEL, self::OID_NEXTCARE, self::OID_PAGE_COUNT,
            self::OID_SERIAL, self::OID_STATUS, self::OID_UPTIME, self::OID_DEVICE_STATUS,
            self::OID_PRINTER_STATUS, self::OID_PRINTER_ERRORS,
        );
    }

    /*
     * Découpe un bloc Brother en enregistrements de sept octets et rend
     * array(code hexadécimal => valeur). Le dernier octet, FF, marque la fin.
     */
    public static function splitBlock($_bytes) {
        $records = array();
        $bytes = (string) $_bytes;
        if (strlen($bytes) > 0 && ord($bytes[strlen($bytes) - 1]) === 0xFF) {
            $bytes = substr($bytes, 0, -1);
        }
        for ($i = 0; $i + 7 <= strlen($bytes); $i += 7) {
            $code = bin2hex($bytes[$i]);
            $value = unpack('N', substr($bytes, $i + 3, 4));
            $records[$code] = (int) $value[1];
        }
        return $records;
    }

    /*
     * Les modèles anciens rendent le bloc de maintenance en enregistrements de
     * cinq octets : un code, 01 02, un niveau, puis 14 (vingt), l'échelle. On
     * les reconnaît à ce 14 qui revient en fin de chaque enregistrement.
     */
    public static function isLegacyBlock($_bytes) {
        $bytes = (string) $_bytes;
        if (strlen($bytes) > 0 && ord($bytes[strlen($bytes) - 1]) === 0xFF) {
            $bytes = substr($bytes, 0, -1);
        }
        if (strlen($bytes) < 5 || strlen($bytes) % 5 !== 0) {
            return false;
        }
        for ($i = 4; $i < strlen($bytes); $i += 5) {
            if (ord($bytes[$i]) !== 0x14) {
                return false;
            }
        }
        return true;
    }

    public static function splitLegacyBlock($_bytes) {
        $records = array();
        $bytes = (string) $_bytes;
        if (strlen($bytes) > 0 && ord($bytes[strlen($bytes) - 1]) === 0xFF) {
            $bytes = substr($bytes, 0, -1);
        }
        for ($i = 0; $i + 5 <= strlen($bytes); $i += 5) {
            $scale = ord($bytes[$i + 4]);
            if ($scale === 0) {
                continue;
            }
            $records[bin2hex($bytes[$i])] = (int) round(ord($bytes[$i + 3]) / $scale * 100);
        }
        return $records;
    }

    private static function mapBlock($_records, $_map, $_percent = true) {
        $values = array();
        foreach ($_records as $code => $value) {
            if (!isset($_map[$code])) {
                continue;
            }
            $key = $_map[$code];
            $values[$key] = ($_percent && in_array($key, self::PERCENT_VALUES, true))
                ? (int) round($value / 100) : $value;
        }
        return $values;
    }

    /* Le modèle se lit dans l'identifiant IEEE 1284 : « …;MDL:MFC-L2800DW;… ». */
    public static function modelOf($_raw) {
        $id = isset($_raw[self::OID_MODEL]) ? (string) $_raw[self::OID_MODEL] : '';
        if (preg_match('/MDL:([\w\-]+)/', $id, $m)) {
            return $m[1];
        }
        return null;
    }

    /*
     * Laser ou jet d'encre. Les deux familles partagent les codes du bloc de
     * maintenance mais pas leur sens : 6f est le toner noir d'une laser et
     * l'encre noire d'une jet d'encre. Brother l'écrit dans le nom du modèle —
     * J et T pour le jet d'encre (MFC-J, DCP-T), L pour le laser.
     */
    public static function detectType($_model) {
        if ($_model !== null && preg_match('/^(MFC|DCP|HL)-?(J|T)/i', $_model)) {
            return self::TYPE_INK;
        }
        return self::TYPE_LASER;
    }

    /*
     * Le texte de l'écran, dans le jeu de caractères que l'imprimante annonce.
     * UTF-8 d'abord : certains firmwares envoient de l'UTF-8 valide tout en
     * annonçant roman8.
     */
    public static function decodeStatus($_bytes, $_charset) {
        $bytes = (string) $_bytes;
        if ($bytes === '') {
            return null;
        }
        if (preg_match('//u', $bytes)) {
            $text = $bytes;
        } else {
            $encoding = isset(self::CHARSETS[(int) $_charset]) ? self::CHARSETS[(int) $_charset] : 'HP-ROMAN8';
            $text = @iconv($encoding, 'UTF-8//IGNORE', $bytes);
            if ($text === false || $text === '') {
                $text = preg_replace('/[^\x20-\x7E]/', '', $bytes);
            }
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return $text === '' ? null : $text;
    }

    /*
     * Une chaîne d'identité (série, firmware) réduite à l'ASCII imprimable.
     * Elle part dans le cache et dans les réponses ajax, deux passages par
     * json_encode : un seul octet hors UTF-8 y ferait tout disparaître en
     * silence, json_encode rendant false.
     */
    public static function text($_bytes) {
        $text = trim(preg_replace('/[^\x20-\x7E]/', '', (string) $_bytes));
        return $text === '' ? null : $text;
    }

    public static function parseErrors($_bytes) {
        $bytes = (string) $_bytes;
        $errors = array();
        foreach (self::PRINTER_ERRORS as $index => $name) {
            $byte = intdiv($index, 8);
            if ($byte >= strlen($bytes)) {
                break;
            }
            if (ord($bytes[$byte]) & (0x80 >> ($index % 8))) {
                $errors[] = $name;
            }
        }
        return $errors;
    }

    /*
     * Tout ce que l'on tire d'une réponse. Fonction pure, sans état : c'est
     * elle que les tests rejouent sur les réponses enregistrées.
     *
     * $_type est laser, jet d'encre ou automatique.
     */
    public static function decode($_raw, $_type = self::TYPE_AUTO) {
        $model = self::modelOf($_raw);
        $type = ($_type === self::TYPE_LASER || $_type === self::TYPE_INK) ? $_type : self::detectType($model);

        $info = array(
            'model'    => $model,
            'type'     => $type,
            'serial'   => isset($_raw[self::OID_SERIAL]) ? self::text($_raw[self::OID_SERIAL]) : null,
            'firmware' => isset($_raw[self::OID_FIRMWARE]) ? self::text($_raw[self::OID_FIRMWARE]) : null,
            'mac'      => (isset($_raw[self::OID_MAC]) && strlen($_raw[self::OID_MAC]) === 6)
                ? implode(':', str_split(bin2hex($_raw[self::OID_MAC]), 2)) : null,
            'legacy'   => false,
        );

        $values = array();
        if (isset($_raw[self::OID_MAINTENANCE]) && self::isLegacyBlock($_raw[self::OID_MAINTENANCE])) {
            $info['legacy'] = true;
            $map = ($type === self::TYPE_INK) ? self::VALUES_INK_MAINTENANCE : self::VALUES_LASER_MAINTENANCE;
            /* Le niveau est déjà en pour cent : pas de division par cent. */
            $values += self::mapBlock(self::splitLegacyBlock($_raw[self::OID_MAINTENANCE]), $map, false);
        } else {
            if (isset($_raw[self::OID_COUNTERS])) {
                $values += self::mapBlock(self::splitBlock($_raw[self::OID_COUNTERS]), self::VALUES_COUNTERS);
            }
            if (isset($_raw[self::OID_MAINTENANCE])) {
                $map = ($type === self::TYPE_INK) ? self::VALUES_INK_MAINTENANCE : self::VALUES_LASER_MAINTENANCE;
                $values += self::mapBlock(self::splitBlock($_raw[self::OID_MAINTENANCE]), $map);
            }
            if ($type === self::TYPE_LASER && isset($_raw[self::OID_NEXTCARE])) {
                $values += self::mapBlock(self::splitBlock($_raw[self::OID_NEXTCARE]), self::VALUES_LASER_NEXTCARE);
            }
        }

        /* Les modèles anciens n'ont pas de bloc de compteurs : le compteur
         * standard de la RFC 3805 prend le relais. */
        if (!isset($values['page_counter']) && isset($_raw[self::OID_PAGE_COUNT]) && is_int($_raw[self::OID_PAGE_COUNT])) {
            $values['page_counter'] = $_raw[self::OID_PAGE_COUNT];
        }

        $state = array(
            'status'         => isset($_raw[self::OID_STATUS])
                ? self::decodeStatus($_raw[self::OID_STATUS], isset($_raw[self::OID_CHARSET]) ? $_raw[self::OID_CHARSET] : 0) : null,
            'printer_status' => isset($_raw[self::OID_PRINTER_STATUS]) ? self::printerStatusLabel($_raw[self::OID_PRINTER_STATUS]) : null,
            /* Le code, pas le libellé : comparer un texte traduit casserait le
             * jour où la traduction change. */
            'printing'       => (isset($_raw[self::OID_PRINTER_STATUS]) && (int) $_raw[self::OID_PRINTER_STATUS] === 4),
            'device_status'  => isset($_raw[self::OID_DEVICE_STATUS]) ? self::deviceStatusLabel($_raw[self::OID_DEVICE_STATUS]) : null,
            'errors'         => isset($_raw[self::OID_PRINTER_ERRORS]) ? self::parseErrors($_raw[self::OID_PRINTER_ERRORS]) : null,
            'uptime'         => (isset($_raw[self::OID_UPTIME]) && is_int($_raw[self::OID_UPTIME])) ? intdiv($_raw[self::OID_UPTIME], 100) : null,
        );

        return array('info' => $info, 'values' => $values, 'state' => $state);
    }

    /* La réponse telle qu'on peut l'afficher : texte lisible, ou hexadécimal
     * pour les blocs binaires — le cache de Jeedom est du JSON et refuserait
     * des octets qui ne sont pas de l'UTF-8. */
    public static function printable($_raw) {
        $out = array();
        foreach ($_raw as $oid => $value) {
            if (is_string($value) && (in_array($oid, self::BINARY_OIDS, true) || !preg_match('//u', $value))) {
                $out[$oid] = 'hex:' . bin2hex($value);
            } else {
                $out[$oid] = $value;
            }
        }
        return $out;
    }

    /* ======================================================= COUCHE RÉSEAU */

    public static function timeout() {
        $timeout = (int) config::byKey('snmp_timeout', __CLASS__, self::DEFAULT_TIMEOUT);
        return ($timeout > 0 && $timeout <= 10) ? $timeout : self::DEFAULT_TIMEOUT;
    }

    public static function query($_ip, $_community) {
        $raw = brotherbeSnmp::get($_ip, $_community, self::oids(), self::timeout(), self::RETRIES);
        if (!isset($raw[self::OID_SERIAL]) && !isset($raw[self::OID_MAINTENANCE])) {
            throw new Exception(sprintf(__('%s répond en SNMP, mais pas comme une imprimante Brother.', __FILE__), $_ip));
        }
        return $raw;
    }

    /* ================================================================ RELEVÉ */

    public function update($_force = false) {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $raw = static::query($this->getConfiguration('ip'), $this->getConfiguration('community', self::DEFAULT_COMMUNITY));
        } catch (Throwable $e) {
            $this->noteFailure($e->getMessage());
            /* Une erreur papier ne se lit plus sur une imprimante injoignable :
             * pas de raison de la relever chaque minute. */
            $this->setCache('in_error', 0);
            /* Minuit passe aussi pour une imprimante éteinte : ses compteurs du
             * jour retombent à zéro, et c'est vrai, elle n'imprime pas. */
            $this->publishPeriods($this->periods(null));
            $this->applyOffline();
            if ($_force) {
                throw $e;
            }
            return false;
        }

        $decoded = self::decode($raw, $this->getConfiguration('printer_type', self::TYPE_AUTO));
        $this->setCache('raw', self::printable($raw));
        $this->setCache('raw_at', date('Y-m-d H:i:s'));
        $this->setCache('info', $decoded['info']);
        $this->clearFailure();

        /* Une donnée jamais vue jusqu'ici — première réponse, ou nouvelle
         * cartouche d'une autre famille — fait naître sa commande. */
        $known = $this->getCache('known', array());
        $seen = array_keys($decoded['values']);
        if (count(array_diff($seen, $known)) > 0) {
            $this->setCache('known', array_values(array_unique(array_merge($known, $seen))));
            $this->createCommands();
        }

        $catalogue = self::catalogue();
        foreach ($decoded['values'] as $key => $value) {
            if (isset($catalogue[$key])) {
                $this->publishCmd($catalogue[$key][0], $value);
            }
        }

        $state = $decoded['state'];
        $this->publishCmd('etat', $state['status']);
        $this->publishCmd('etat_imprimante', $state['printer_status']);
        $this->publishCmd('etat_appareil', $state['device_status']);
        if ($state['errors'] !== null) {
            $labels = self::errorLabels();
            $texts = array();
            foreach ($state['errors'] as $error) {
                $texts[] = isset($labels[$error]) ? $labels[$error] : $error;
            }
            $this->publishCmd('en_erreur', count($texts) > 0 ? 1 : 0);
            $this->setCache('in_error', count($texts) > 0 ? 1 : 0);
            $this->publishCmd('erreurs', count($texts) > 0 ? implode(', ', $texts) : __('Aucune', __FILE__));
            $state['error_labels'] = $texts;
        }
        $this->publishCmd('dernier_demarrage', $this->bootDate($state['uptime']));
        $this->publishCmd('en_ligne', 1);

        $periods = $this->periods(isset($decoded['values']['page_counter']) ? $decoded['values']['page_counter'] : null);
        $this->publishPeriods($periods);
        $this->setCache('periods', $periods);

        $this->setCache('values', $decoded['values']);
        $this->setCache('state', $state);
        $this->publishTile(true);
        $this->refreshWidget();
        return true;
    }

    /*
     * Date du dernier démarrage, déduite de l'uptime. Recalculée à chaque
     * relevé, elle bougerait de quelques secondes et réécrirait la commande :
     * on garde la précédente tant que l'écart reste dans la tolérance.
     */
    private function bootDate($_uptime) {
        if ($_uptime === null) {
            return null;
        }
        $boot = time() - (int) $_uptime;
        $previous = (int) $this->getCache('boot_at', 0);
        if ($previous > 0 && abs($boot - $previous) <= self::BOOT_TOLERANCE) {
            $boot = $previous;
        }
        $this->setCache('boot_at', $boot);
        return date('Y-m-d H:i', $boot);
    }

    /* ======================================================= PAGES DU JOUR */

    /* L'horloge, isolée pour que les tests puissent faire passer minuit. */
    public static function now() {
        return time();
    }

    /*
     * Pages imprimées depuis minuit et depuis le 1er du mois, par différence
     * avec le compteur de l'imprimante.
     *
     * La référence d'une nouvelle période est le dernier compteur connu, pas
     * le premier de la période : une imprimante éteinte toute la nuit et
     * rallumée à 9 h n'a imprimé que ce qui dépasse le compteur de la veille,
     * et une impression faite à 23 h 58, entre deux relevés, doit compter.
     *
     * Un compteur qui recule — carte mère remplacée, remise à zéro par le
     * service — redémarre la période au lieu de rendre un nombre négatif.
     *
     * Sans compteur ($_counter à null, imprimante injoignable), on ne fait que
     * changer de période si minuit est passé.
     */
    public function periods($_counter) {
        $now = static::now();
        $last = $this->getCache('last_pages', null);
        $stamps = array('day' => date('Y-m-d', $now), 'month' => date('Y-m', $now));
        $refs = $this->getCache('period_refs', array());
        $result = array();

        foreach ($stamps as $period => $stamp) {
            $ref = isset($refs[$period]) ? $refs[$period] : null;
            if ($ref === null || $ref['stamp'] !== $stamp) {
                $ref = array('stamp' => $stamp, 'pages' => $last !== null ? (int) $last : ($_counter !== null ? (int) $_counter : null));
            }
            if ($_counter !== null && ($ref['pages'] === null || (int) $_counter < $ref['pages'])) {
                $ref['pages'] = (int) $_counter;
            }
            $refs[$period] = $ref;

            $current = $_counter !== null ? (int) $_counter : ($last !== null ? (int) $last : null);
            $result[$period] = ($current !== null && $ref['pages'] !== null) ? $current - $ref['pages'] : null;
        }

        $this->setCache('period_refs', $refs);
        if ($_counter !== null) {
            $this->setCache('last_pages', (int) $_counter);
        }
        return $result;
    }

    private function publishPeriods($_periods) {
        $this->publishCmd('pages_jour', $_periods['day']);
        $this->publishCmd('pages_mois', $_periods['month']);
    }

    /* ================================================================ TUILE */

    private function publishTile($_online) {
        $this->publishCmd('resume', self::buildResume(
            $this->getCache('values', array()),
            $this->getCache('state', array()),
            $this->getCache('info', array()),
            $_online,
            $this->getCache('periods', array())
        ));
    }

    /*
     * Tout ce que la tuile affiche tient dans un seul objet. Les clés absentes
     * restent absentes : un zéro inventé deviendrait un toner vide affirmé.
     *
     * Pas d'horodatage dans l'objet : il changerait la valeur à chaque relevé,
     * donc un événement et un rafraîchissement du dashboard toutes les cinq
     * minutes pour une imprimante qui dort. La durée affichée sous la tuile
     * dit alors depuis quand rien n'a changé, ce qui est l'information utile.
     */
    public static function buildResume($_values, $_state, $_info, $_online, $_periods = array()) {
        $v = is_array($_values) ? $_values : array();
        $s = is_array($_state) ? $_state : array();
        $lire = function ($_key) use ($v) {
            return array_key_exists($_key, $v) && $v[$_key] !== null ? $v[$_key] : null;
        };

        /* Les consommables dans l'ordre où on les regarde : ce qu'on remplace
         * le plus souvent d'abord. */
        $supplies = array();
        $list = array(
            array('black_toner_remaining', 'Toner', 'black'),
            array('cyan_toner_remaining', 'Cyan', 'cyan'),
            array('magenta_toner_remaining', 'Magenta', 'magenta'),
            array('yellow_toner_remaining', 'Jaune', 'yellow'),
            array('black_ink_remaining', 'Noir', 'black'),
            array('cyan_ink_remaining', 'Cyan', 'cyan'),
            array('magenta_ink_remaining', 'Magenta', 'magenta'),
            array('yellow_ink_remaining', 'Jaune', 'yellow'),
            array('drum_remaining_life', 'Tambour', 'drum'),
            array('black_drum_remaining_life', 'Tambour N', 'drum'),
            array('cyan_drum_remaining_life', 'Tambour C', 'drum'),
            array('magenta_drum_remaining_life', 'Tambour M', 'drum'),
            array('yellow_drum_remaining_life', 'Tambour J', 'drum'),
            array('belt_unit_remaining_life', 'Courroie', 'other'),
            array('fuser_remaining_life', 'Fusion', 'other'),
            array('ink_capture_box_remaining_life', 'Récupération', 'other'),
        );
        foreach ($list as $item) {
            if ($lire($item[0]) !== null) {
                $supplies[] = array('label' => __($item[1], __FILE__), 'pct' => (int) $lire($item[0]), 'kind' => $item[2]);
            }
        }

        $payload = array(
            'online'   => $_online ? 1 : 0,
            'model'    => isset($_info['model']) ? $_info['model'] : null,
            'status'   => isset($s['status']) ? $s['status'] : null,
            'printing' => !empty($s['printing']) ? 1 : 0,
            'errors'   => isset($s['error_labels']) ? $s['error_labels'] : array(),
            'supplies' => $supplies,
            'pages'    => $lire('page_counter'),
            'today'    => isset($_periods['day']) ? $_periods['day'] : null,
            'month'    => isset($_periods['month']) ? $_periods['month'] : null,
            'drum_pages' => $lire('drum_remaining_pages'),
        );

        /* La valeur atterrit entre apostrophes dans un bloc de script du
         * gabarit : les drapeaux hexadécimaux y neutralisent apostrophes,
         * guillemets et chevrons. */
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /* =========================================================== HORS LIGNE */

    /*
     * Une imprimante éteinte n'a plus de toner ni de pages : elle ne répond
     * plus, c'est tout. On le dit, et on garde les dernières valeurs — écrire
     * zéro dans « Toner noir » déclencherait toutes les alertes de fin de
     * cartouche.
     */
    private function applyOffline() {
        $this->publishCmd('en_ligne', 0);
        $this->publishTile(false);
        $this->refreshWidget();
    }

    /* ==================================================== RYTHME ET ÉCHECS */

    public function shouldPoll() {
        if (!$this->isConfigured()) {
            return false;
        }
        $now = time();
        $last = (int) $this->getCache('polled_at', 0);
        $interval = max(1, (int) $this->getConfiguration('interval', self::DEFAULT_INTERVAL)) * 60;
        if ((int) $this->getCache('in_error', 0) === 1) {
            $interval = min($interval, self::ERROR_INTERVAL);
        }
        $wait = max($interval, (int) $this->getCache('backoff', 0));
        /* Quelques secondes de marge : le cron ne passe jamais exactement à la
         * même seconde, et un intervalle de cinq minutes en ferait six. */
        if ($now - $last < $wait - 10) {
            return false;
        }
        $this->setCache('polled_at', $now);
        return true;
    }

    public function forcePoll() {
        $now = time();
        if ($now - (int) $this->getCache('forced_at', 0) < self::FORCE_MIN_INTERVAL) {
            throw new Exception(sprintf(__('Patientez %s secondes entre deux relevés manuels.', __FILE__), self::FORCE_MIN_INTERVAL));
        }
        $this->setCache('forced_at', $now);
        $this->setCache('polled_at', $now);
        return $this->update(true);
    }

    private function noteFailure($_message) {
        $failures = (int) $this->getCache('failures', 0) + 1;
        $this->setCache('failures', $failures);
        $this->setCache('problem', $_message);

        if ($failures < self::FAILURES_BEFORE_BACKOFF) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $_message);
            return;
        }

        $backoff = min(self::BACKOFF_MAX, self::BACKOFF_MIN * (1 << min(4, $failures - self::FAILURES_BEFORE_BACKOFF)));
        $this->setCache('backoff', $backoff);

        /* « info » et non « error » : une imprimante qu'on éteint le soir ne
         * doit pas remplir le journal d'erreurs tous les jours. */
        if ($failures === self::FAILURES_BEFORE_BACKOFF) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $_message);
        }
    }

    private function clearFailure() {
        if ((int) $this->getCache('failures', 0) > 0) {
            $this->setCache('failures', 0);
            $this->setCache('backoff', 0);
        }
        $this->setCache('problem', '');
    }

    /* =============================================== DONNÉES POUR LA PAGE */

    public function toAjax() {
        return array(
            'id'        => $this->getId(),
            'online'    => (int) $this->getCache('failures', 0) === 0,
            'failures'  => (int) $this->getCache('failures', 0),
            'problem'   => (string) $this->getCache('problem', ''),
            'fetchedAt' => (string) $this->getCache('raw_at', ''),
            'info'      => $this->getCache('info', array()),
            'values'    => $this->getCache('values', array()),
            'raw'       => $this->getCache('raw', array()),
        );
    }

    /*
     * Test d'une adresse depuis la page, sans rien enregistrer : l'utilisateur
     * voit le modèle et le numéro de série avant de valider.
     */
    public static function probe($_ip, $_community, $_type) {
        $raw = self::query($_ip, trim((string) $_community) === '' ? self::DEFAULT_COMMUNITY : $_community);
        $decoded = self::decode($raw, $_type);
        return array('info' => $decoded['info'], 'values' => $decoded['values'], 'state' => $decoded['state']);
    }
}

/*
 * Obligatoire même réduite au minimum : sans elle, le coeur refuse de créer ou
 * d'ouvrir un équipement du plugin.
 */
class brotherbeCmd extends cmd {

    public function execute($_options = array()) {
        if ($this->getType() !== 'action') {
            return;
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            return;
        }
        if ($this->getLogicalId() === 'rafraichir') {
            $eqLogic->setCache('polled_at', time());
            $eqLogic->update(true);
        }
    }
}
