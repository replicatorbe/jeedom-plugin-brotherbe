<?php
/* Remplaçants minimaux du coeur de Jeedom, pour rejouer la classe du plugin
 * hors d'une installation. Ils ne simulent que ce dont le décodage et le relevé
 * ont besoin : traduction, configuration, journal, cache, et un registre de
 * commandes pour vérifier ce que le plugin crée et publie.
 *
 * Le but n'est pas de tester Jeedom, mais de rejouer une réponse SNMP réelle
 * et de vérifier que le plugin en tire les chiffres que l'imprimante affiche. */

date_default_timezone_set('Europe/Brussels');

function __($_text, $_file = null) { return $_text; }

class config {
    public static $values = array();
    public static function byKey($_key, $_plugin = 'core', $_default = '') {
        $k = $_plugin . '::' . $_key;
        return isset(self::$values[$k]) ? self::$values[$k] : $_default;
    }
}

class log {
    public static $lines = array();
    public static function add($_plugin, $_level, $_message, $_logicalId = '') {
        self::$lines[] = $_level . ' : ' . $_message;
    }
}

class cmd {
    /* Toutes les commandes créées, par équipement puis par identifiant
     * logique : c'est le registre que getCmd() consulte. */
    public static $registry = array();

    public $_eqLogicId = 0;
    public $_logicalId = '';
    public $_name = '';
    public $_type = 'info';
    public $_subType = '';
    public $_isVisible = 1;
    public $_isHistorized = 0;
    public $_unite = '';
    public $_template = array();

    public function setEqLogic_id($_v) { $this->_eqLogicId = $_v; }
    public function setLogicalId($_v) { $this->_logicalId = $_v; }
    public function getLogicalId() { return $this->_logicalId; }
    public function setName($_v) { $this->_name = $_v; }
    public function getName() { return $this->_name; }
    public function setType($_v) { $this->_type = $_v; }
    public function getType() { return $this->_type; }
    public function setSubType($_v) { $this->_subType = $_v; }
    public function getSubType() { return $this->_subType; }
    public function setIsVisible($_v) { $this->_isVisible = $_v; }
    public function getIsVisible() { return $this->_isVisible; }
    public function setIsHistorized($_v) { $this->_isHistorized = $_v; }
    public function getIsHistorized() { return $this->_isHistorized; }
    public $_order = 0;
    public function setOrder($_v) { $this->_order = $_v; }
    public function getOrder() { return $this->_order; }
    public function setUnite($_v) { $this->_unite = $_v; }
    public function getUnite() { return $this->_unite; }
    public function setTemplate($_k, $_v) { $this->_template[$_k] = $_v; }
    public function getEqLogic() { return null; }
    public function save() {
        self::$registry[$this->_eqLogicId][$this->_logicalId] = $this;
    }
    public static function byEqLogicIdCmdName($_eqLogicId, $_name) {
        if (!isset(self::$registry[$_eqLogicId])) {
            return null;
        }
        foreach (self::$registry[$_eqLogicId] as $cmd) {
            if ($cmd->_name === $_name) {
                return $cmd;
            }
        }
        return null;
    }
}

class eqLogic {
    public static $nextId = 1;

    public $_id = 0;
    public $_published = array();
    public $_configuration = array();
    public $_store = array();

    public function __construct() { $this->_id = self::$nextId++; }
    public function getId() { return $this->_id; }
    public function getHumanName() { return '[Test][Imprimante]'; }
    public function getConfiguration($_key, $_default = '') {
        return array_key_exists($_key, $this->_configuration) ? $this->_configuration[$_key] : $_default;
    }
    public function setConfiguration($_key, $_value) {
        $this->_configuration[$_key] = $_value;
        return $this;
    }
    public function getCmd($_type, $_logicalId) {
        return isset(cmd::$registry[$this->_id][$_logicalId]) ? cmd::$registry[$this->_id][$_logicalId] : null;
    }
    public function checkAndUpdateCmd($_cmd, $_value, $_when = null) {
        $id = is_object($_cmd) ? $_cmd->getLogicalId() : $_cmd;
        $this->_published[$id] = $_value;
    }

    /* Le vrai cache de Jeedom passe par du JSON : le stub fait de même, pour
     * qu'une valeur binaire glissée par erreur dans le cache fasse échouer le
     * test au lieu de passer inaperçue. */
    public function getCache($_key = '', $_default = '') {
        return isset($this->_store[$_key]) ? json_decode($this->_store[$_key], true) : $_default;
    }
    public function setCache($_key, $_value = null) {
        $json = json_encode($_value);
        if ($json === false) {
            throw new Exception('Valeur impossible à mettre en cache pour « ' . $_key . ' » : ' . json_last_error_msg());
        }
        $this->_store[$_key] = $json;
    }

    public function refreshWidget() {}
    public function setIsEnable($_v) {}
    public function setIsVisible($_v) {}
    public function save($_direct = false) {}
    public static function byType($_type, $_onlyEnable = false) { return array(); }
}
