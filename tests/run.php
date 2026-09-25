<?php
/* Rejeu hors ligne du plugin sur des réponses SNMP d'imprimantes Brother.
 *
 *   php tests/run.php
 *
 * tests/fixtures/*.hex sont des paquets de réponse tels que l'imprimante les a
 * envoyés, en hexadécimal. Toute valeur douteuse rencontrée en production doit
 * y laisser un fichier : c'est ce qui transforme un incident en test de
 * non-régression permanent. */

require_once __DIR__ . '/stub.php';
require_once __DIR__ . '/../core/class/brotherbeSnmp.class.php';

/* La classe charge le coeur de Jeedom en première ligne ; hors installation, on
 * la recopie sans ce require et on l'inclut. La copie est effacée en sortant,
 * même sur erreur fatale. */
$original = __DIR__ . '/../core/class/brotherbe.class.php';
$copy = __DIR__ . '/../core/class/.brotherbe.test.php';
$source = preg_replace('#^\s*require_once .*core\.inc\.php.*$#m', '', file_get_contents($original));
file_put_contents($copy, $source);
register_shutdown_function(function () use ($copy) {
    if (file_exists($copy)) { unlink($copy); }
});
require_once $copy;

$passed = 0;
$failed = 0;

function check($_label, $_actual, $_expected) {
    global $passed, $failed;
    if ($_actual === $_expected) {
        $passed++;
        printf("  ok    %-58s %s\n", $_label, str_replace("\n", ' ', var_export($_actual, true)));
    } else {
        $failed++;
        printf("  ECHEC %-58s obtenu %s, attendu %s\n", $_label,
            str_replace("\n", ' ', var_export($_actual, true)), str_replace("\n", ' ', var_export($_expected, true)));
    }
}

function section($_title) {
    echo "\n" . $_title . "\n" . str_repeat('-', strlen($_title)) . "\n";
}

function packet($_name) {
    return hex2bin(preg_replace('/\s+/', '', file_get_contents(__DIR__ . '/fixtures/' . $_name . '.hex')));
}

/* Construit un bloc Brother : enregistrements de sept octets, puis FF. */
function block($_records) {
    $out = '';
    foreach ($_records as $code => $value) {
        $out .= chr(hexdec($code)) . "\x01\x04" . pack('N', $value);
    }
    return $out . "\xFF";
}

/* L'imprimante de test : la classe du plugin, dont on remplace seulement
 * l'appel réseau par une réponse préparée. */
class brotherbeFake extends brotherbe {
    public static $answer = null;
    public static $clock = null;
    public static function now() {
        return self::$clock !== null ? self::$clock : time();
    }
    public static function query($_ip, $_community) {
        if (self::$answer instanceof Exception) {
            throw self::$answer;
        }
        return self::$answer;
    }
}

/* =================================================================== BER */

section('Encodage et décodage BER');

$request = brotherbeSnmp::encodeGetRequest('public', 4242, array('1.3.6.1.2.1.1.3.0'));
check('requête GET : en-tête SEQUENCE', bin2hex(substr($request, 0, 1)), '30');
check('requête GET : version 2c et communauté', bin2hex(substr($request, 2, 11)), '020101040670756' . '26c6963');
check('OID de l\'uptime encodé', bin2hex(brotherbeSnmp::encodeOid('1.3.6.1.2.1.1.3.0')), '06082b06010201010300');
check('OID Brother à grands arcs (2435)', brotherbeSnmp::decodeOid(substr(brotherbeSnmp::encodeOid('1.3.6.1.4.1.2435.2.3.9.1.1.7.0'), 2)), '1.3.6.1.4.1.2435.2.3.9.1.1.7.0');
check('entier négatif (-3, niveau inconnu RFC 3805)', brotherbeSnmp::decodeInteger("\xFD"), -3);
check('entier positif sur deux octets', brotherbeSnmp::decodeInteger("\x00\xC8"), 200);
check('Counter32 non signé', brotherbeSnmp::decodeUnsigned("\xFF\xFF\xFF\xFF"), 4294967295);

$raw = brotherbeSnmp::decodeResponse(packet('mfc-l2800dw'), 4242);
check('réponse réelle : aucune erreur', $raw['error'], 0);
check('réponse réelle : quatorze OID', count($raw['values']), 14);
check('réponse réelle : numéro de série', $raw['values'][brotherbe::OID_SERIAL], 'E00000A0N000000');
check('réponse réelle : compteur de pages (Counter32)', $raw['values'][brotherbe::OID_PAGE_COUNT], 34);
check('réponse réelle : jeu de caractères roman8', $raw['values'][brotherbe::OID_CHARSET], 2004);

$refused = false;
try {
    brotherbeSnmp::decodeResponse(packet('mfc-l2800dw'), 1);
} catch (UnexpectedValueException $e) {
    $refused = true;
}
check('réponse à une autre requête écartée', $refused, true);

$refused = false;
try {
    brotherbeSnmp::decodeResponse(substr(packet('mfc-l2800dw'), 0, 40), 4242);
} catch (UnexpectedValueException $e) {
    $refused = true;
}
check('paquet tronqué écarté sans erreur fatale', $refused, true);

/* ======================================================= MFC-L2800DW RÉEL */

section('MFC-L2800DW, relevé réel (laser monochrome)');

$d = brotherbe::decode($raw['values']);
check('modèle lu dans l\'identifiant IEEE 1284', $d['info']['model'], 'MFC-L2800DW');
check('technologie détectée', $d['info']['type'], 'laser');
check('firmware', $d['info']['firmware'], '1.28');
check('adresse MAC', $d['info']['mac'], '00:11:22:33:44:55');
check('format moderne du bloc de maintenance', $d['info']['legacy'], false);

/* Les valeurs de référence sont celles que Home Assistant affiche pour la
 * même imprimante au même moment. */
check('toner noir restant (0x23F0 = 9200 → 92 %)', $d['values']['black_toner_remaining'], 92);
check('tambour restant (0x2710 = 10000 → 100 %)', $d['values']['drum_remaining_life'], 100);
check('tambour pages restantes (0x3A76)', $d['values']['drum_remaining_pages'], 14966);
check('tambour pages imprimées', $d['values']['drum_counter'], 34);
check('pages imprimées', $d['values']['page_counter'], 34);
check('pages recto verso', $d['values']['duplex_unit_pages_counter'], 10);
check('compteur noir', $d['values']['black_counter'], 33);
check('aucun toner couleur sur une monochrome', isset($d['values']['cyan_toner_remaining']), false);
check('aucune encre sur une laser', isset($d['values']['black_ink_remaining']), false);

check('texte de l\'écran', $d['state']['status'], 'Veille');
check('état d\'impression (3)', $d['state']['printer_status'], 'Au repos');
check('état de l\'appareil (2)', $d['state']['device_status'], 'En service');
check('aucune erreur (octet 00)', $d['state']['errors'], array());
check('uptime en secondes', is_int($d['state']['uptime']) && $d['state']['uptime'] > 0, true);
check('pas en cours d\'impression (code 3)', $d['state']['printing'], false);
$busy = $raw['values'];
$busy[brotherbe::OID_PRINTER_STATUS] = 4;
check('en cours d\'impression (code 4)', brotherbe::decode($busy)['state']['printing'], true);
check('tuile : impression signalée', json_decode(brotherbe::buildResume(array(), brotherbe::decode($busy)['state'], array(), true), true)['printing'], 1);

$dirty = $raw['values'];
$dirty[brotherbe::OID_FIRMWARE] = "1.28\x00\xFF";
check('firmware débarrassé des octets hors ASCII', brotherbe::decode($dirty)['info']['firmware'], '1.28');
check('identité toujours encodable en JSON', json_encode(brotherbe::decode($dirty)['info']) !== false, true);

/* ================================================================ ERREURS */

section('Erreurs RFC 3805, bit de poids fort en premier');

check('plus de papier (0x40)', brotherbe::parseErrors("\x40"), array('no_paper'));
check('bourrage et capot ouvert (0x0C)', brotherbe::parseErrors("\x0C"), array('door_open', 'jammed'));
check('toner bas, second octet vide', brotherbe::parseErrors("\x20\x00"), array('low_toner'));
check('bac de sortie plein (second octet, 0x08)', brotherbe::parseErrors("\x00\x08"), array('output_full'));
check('chaîne vide', brotherbe::parseErrors(''), array());

/* ========================================================= JEU DE CARACTÈRES */

section('Texte de l\'écran');

check('roman8 : « Prêt » (ê = 0xC1)', brotherbe::decodeStatus("Pr\xC1t", 2004), 'Prêt');
check('UTF-8 annoncé roman8 : gardé tel quel', brotherbe::decodeStatus("Pr\xC3\xAAt", 2004), 'Prêt');
check('latin2', brotherbe::decodeStatus("Tiskárna", 5), 'Tiskárna');
check('espaces de remplissage de l\'écran retirés', brotherbe::decodeStatus("Veille      ", 2004), 'Veille');
check('vide', brotherbe::decodeStatus('', 2004), null);

/* ====================================================== AUTRES FAMILLES */

section('Laser couleur (bloc construit)');

$color = array(
    brotherbe::OID_MODEL       => 'MFG:Brother;CMD:PJL;MDL:HL-L3270CDW;CLS:PRINTER;',
    brotherbe::OID_MAINTENANCE => block(array('6f' => 4500, '70' => 1200, '71' => 800, '72' => 10000, '80' => 9100, '79' => 9000, '69' => 8800, '6a' => 9700)),
    brotherbe::OID_COUNTERS    => block(array('00' => 1500, '01' => 900, '02' => 600)),
);
$d = brotherbe::decode($color);
check('technologie détectée (HL-L)', $d['info']['type'], 'laser');
check('toner noir', $d['values']['black_toner_remaining'], 45);
check('toner cyan', $d['values']['cyan_toner_remaining'], 12);
check('toner magenta', $d['values']['magenta_toner_remaining'], 8);
check('toner jaune', $d['values']['yellow_toner_remaining'], 100);
check('tambour noir', $d['values']['black_drum_remaining_life'], 91);
check('courroie', $d['values']['belt_unit_remaining_life'], 88);
check('pages couleur', $d['values']['color_counter'], 600);

section('Jet d\'encre (bloc construit)');

$ink = array(
    brotherbe::OID_MODEL       => 'MFG:Brother;MDL:MFC-J5330DW;CLS:PRINTER;',
    brotherbe::OID_MAINTENANCE => block(array('6f' => 5000, '70' => 2500, '71' => 7500, '72' => 1000, '85' => 99)),
    brotherbe::OID_NEXTCARE    => block(array('82' => 1234)),
);
$d = brotherbe::decode($ink);
check('technologie détectée (MFC-J)', $d['info']['type'], 'ink');
check('6f devient l\'encre noire, pas le toner', $d['values']['black_ink_remaining'], 50);
check('pas de toner sur une jet d\'encre', isset($d['values']['black_toner_remaining']), false);
check('boîte de récupération, déjà en pour cent (comme HA)', $d['values']['ink_capture_box_remaining_life'], 99);
check('bloc d\'entretien ignoré en jet d\'encre', isset($d['values']['drum_remaining_pages']), false);
check('DCP-T détecté jet d\'encre', brotherbe::detectType('DCP-T720DW'), 'ink');
check('forcé laser malgré le nom', brotherbe::decode($ink, 'laser')['info']['type'], 'laser');

section('Format ancien (enregistrements de cinq octets)');

$legacy = array(
    brotherbe::OID_MAINTENANCE => "\xa1\x01\x02\x04\x14" . "\xa2\x01\x02\x0c\x14" . "\xa3\x01\x02\x06\x14" . "\xa4\x01\x02\x0b\x14\xFF",
    brotherbe::OID_MODEL       => 'MDL:DCP-J132W;',
    brotherbe::OID_PAGE_COUNT  => 986,
);
$d = brotherbe::decode($legacy);
check('format ancien reconnu', $d['info']['legacy'], true);
check('encre noire 4/20', $d['values']['black_ink_remaining'], 20);
check('encre cyan 12/20', $d['values']['cyan_ink_remaining'], 60);
check('compteur standard RFC 3805 en secours', $d['values']['page_counter'], 986);
check('bloc moderne pas pris pour ancien', brotherbe::isLegacyBlock(block(array('6f' => 20))), false);

/* ================================================================ RELEVÉ */

section('Relevé complet sur une imprimante de test');

$eq = new brotherbeFake();
$eq->setConfiguration('ip', '192.168.1.50');
$eq->setConfiguration('community', 'public');
$eq->setConfiguration('printer_type', 'auto');
$eq->createCommands();
check('avant tout relevé, pas de commande toner', is_object($eq->getCmd('info', 'toner_noir')), false);
check('la tuile existe dès la création', is_object($eq->getCmd('info', 'resume')), true);

brotherbeFake::$answer = $raw['values'];
check('update() réussit', $eq->update(), true);
check('commande toner créée au premier relevé', is_object($eq->getCmd('info', 'toner_noir')), true);
check('pas de commande toner cyan', is_object($eq->getCmd('info', 'toner_cyan')), false);
check('toner publié', $eq->_published['toner_noir'], 92);
check('tambour publié', $eq->_published['tambour_restant'], 100);
check('pages publiées', $eq->_published['pages'], 34);
check('état publié', $eq->_published['etat'], 'Veille');
check('erreurs : aucune', $eq->_published['erreurs'], 'Aucune');
check('en erreur : 0', $eq->_published['en_erreur'], 0);
check('en ligne : 1', $eq->_published['en_ligne'], 1);
check('toner historisé', $eq->getCmd('info', 'toner_noir')->getIsHistorized(), 1);
check('toner masqué, la tuile le montre', $eq->getCmd('info', 'toner_noir')->getIsVisible(), 0);
check('unité du toner', $eq->getCmd('info', 'toner_noir')->getUnite(), '%');

/* Les commandes nées au premier relevé ne doivent pas s'intercaler entre
 * « En ligne » et « Rafraîchir » : c'est ce qu'un compteur continu faisait. */
$sorted = cmd::$registry[$eq->getId()];
uasort($sorted, function ($a, $b) { return $a->getOrder() - $b->getOrder(); });
check('ordre : État, consommables, puis En ligne et Rafraîchir',
    array_keys($sorted),
    array('resume', 'etat', 'etat_imprimante', 'etat_appareil', 'en_erreur', 'erreurs',
          'toner_noir', 'tambour_restant', 'tambour_pages_restantes', 'tambour_compteur',
          'pages', 'pages_recto_verso', 'compteur_noir', 'pages_jour', 'pages_mois',
          'dernier_demarrage', 'en_ligne', 'rafraichir'));

/* La page de l'équipement renumérote tout de 0 à n à chaque sauvegarde. Une
 * commande née ensuite doit quand même trouver sa place, avant En ligne. */
$n = 0;
foreach ($sorted as $cmd) { $cmd->setOrder($n++); }
unset(cmd::$registry[$eq->getId()]['pages_jour']);
$eq->createCommands();
$sorted = cmd::$registry[$eq->getId()];
uasort($sorted, function ($a, $b) { return $a->getOrder() - $b->getOrder(); });
$keys = array_keys($sorted);
check('après renumérotation par la page : Pages du jour avant En ligne',
    array_search('pages_jour', $keys) < array_search('en_ligne', $keys), true);
check('ordres consécutifs, sans trou', array_map(function ($c) { return $c->getOrder(); }, array_values($sorted)), range(0, count($sorted) - 1));
$sorted['rafraichir']->setOrder(0);
$sorted['resume']->setOrder(15);
$eq->createCommands();
check('ordre choisi à la main respecté sans création', array($sorted['rafraichir']->getOrder(), $sorted['resume']->getOrder()), array(0, 15));
$sorted['rafraichir']->setOrder(15);
$sorted['resume']->setOrder(0);

/* Le chemin exact de la page : postSave() crée des commandes, puis le coeur
 * renumérote de 0 à n celles du formulaire — qui ne contenait que les
 * commandes fixes —, puis appelle postAjax(). */
$page = new brotherbeFake();
$page->setConfiguration('ip', '192.168.1.50');
$page->createCommands();
$page->postAjax();
$form = array_values(cmd::$registry[$page->getId()]);
brotherbeFake::$answer = $raw['values'];
$page->update();
$n = 0;
foreach ($form as $cmd) { $cmd->setOrder($n++); }
$page->postAjax();
$sorted = cmd::$registry[$page->getId()];
uasort($sorted, function ($a, $b) { return $a->getOrder() - $b->getOrder(); });
$keys = array_keys($sorted);
check('page : aucun numéro en double après postAjax()', count(array_unique(array_map(function ($c) { return $c->getOrder(); }, $sorted))), count($sorted));
check('page : Toner noir avant En ligne', array_search('toner_noir', $keys) < array_search('en_ligne', $keys), true);
check('page : Rafraîchir en dernier', end($keys), 'rafraichir');

section('Seuils d\'alerte');

check('toner : warning à 20 %', $eq->getCmd('info', 'toner_noir')->getAlert('warningif'), '#value# <= 20');
check('toner : danger à 10 %', $eq->getCmd('info', 'toner_noir')->getAlert('dangerif'), '#value# <= 10');
check('tambour : warning à 10 %', $eq->getCmd('info', 'tambour_restant')->getAlert('warningif'), '#value# <= 10');
check('tambour : danger à 5 %', $eq->getCmd('info', 'tambour_restant')->getAlert('dangerif'), '#value# <= 5');
check('encre : seuils des consommables', brotherbe::alertsFor('encre_cyan', '%'), array('warning' => 20, 'danger' => 10));
check('compteur de pages : aucun seuil', $eq->getCmd('info', 'pages')->getAlert('warningif'), '');
check('pages restantes : aucun seuil', brotherbe::alertsFor('tambour_pages_restantes', 'pages'), null);

/* Une commande créée avant les seuils les reçoit une fois ; un seuil posé par
 * l'utilisateur n'est jamais écrasé ; un seuil vidé ne revient pas. */
$toner = $eq->getCmd('info', 'toner_noir');
$toner->_alert = array();
$toner->_config = array();
$drum = $eq->getCmd('info', 'tambour_restant');
$drum->_alert = array('warningif' => '#value# < 30');
$drum->_config = array();
$eq->createCommands();
check('rattrapage : seuils posés sur une commande ancienne', $toner->getAlert('dangerif'), '#value# <= 10');
check('rattrapage : seuil de l\'utilisateur conservé', $drum->getAlert('warningif'), '#value# < 30');
check('rattrapage : pas de danger ajouté à côté', $drum->getAlert('dangerif'), '');
$toner->_alert = array();
$saves = $toner->_saves;
$eq->createCommands();
check('seuil vidé par l\'utilisateur : ne revient pas', $toner->getAlert('warningif'), '');
check('aucune réécriture inutile de la commande', $toner->_saves, $saves);

$tile = json_decode($eq->_published['resume'], true);
check('tuile : en ligne', $tile['online'], 1);
check('tuile : deux consommables (toner, tambour)', count($tile['supplies']), 2);
check('tuile : toner en premier', $tile['supplies'][0], array('label' => 'Toner', 'pct' => 92, 'kind' => 'black'));
check('tuile : pages', $tile['pages'], 34);

$boot = $eq->_published['dernier_demarrage'];
$eq->_published = array();
$eq->update();
check('date de démarrage stable d\'un relevé à l\'autre', $eq->_published['dernier_demarrage'], $boot);

section('Erreur remontée par l\'imprimante');

$jam = $raw['values'];
$jam[brotherbe::OID_PRINTER_ERRORS] = "\x44";
brotherbeFake::$answer = $jam;
$eq->update();
check('en erreur : 1', $eq->_published['en_erreur'], 1);
check('erreurs en clair', $eq->_published['erreurs'], 'Plus de papier, Bourrage papier');
$tile = json_decode($eq->_published['resume'], true);
check('tuile : bandeau d\'erreur', $tile['errors'], array('Plus de papier', 'Bourrage papier'));

section('Imprimante éteinte');

$eq->_published = array();
brotherbeFake::$answer = new Exception('L\'imprimante ne répond pas.');
check('update() échoue sans lever', $eq->update(), false);
check('en ligne : 0', $eq->_published['en_ligne'], 0);
check('toner non remis à zéro', isset($eq->_published['toner_noir']), false);
$tile = json_decode($eq->_published['resume'], true);
check('tuile hors ligne', $tile['online'], 0);
check('tuile garde le dernier toner connu', $tile['supplies'][0]['pct'], 92);
$eq->update();
$eq->update();
check('espacement après trois échecs', $eq->getCache('backoff', 0), 300);
brotherbeFake::$answer = $raw['values'];
$eq->update();
check('retour en ligne : espacement effacé', $eq->getCache('backoff', 0), 0);

section('Pages du jour et du mois');

$p = new brotherbeFake();
$p->setConfiguration('ip', '192.168.1.50');
$p->createCommands();
$counter = $raw['values'];
$at = function ($_date, $_pages) use ($p, $counter) {
    brotherbeFake::$clock = strtotime($_date);
    if ($_pages === null) {
        brotherbeFake::$answer = new Exception('éteinte');
    } else {
        $counter[brotherbe::OID_COUNTERS] = block(array('00' => $_pages));
        brotherbeFake::$answer = $counter;
    }
    $p->_published = array();
    $p->update();
    return array(
        isset($p->_published['pages_jour']) ? $p->_published['pages_jour'] : '-',
        isset($p->_published['pages_mois']) ? $p->_published['pages_mois'] : '-',
    );
};
check('premier relevé : zéro, pas le compteur entier', $at('2026-09-25 10:00', 34), array(0, 0));
check('six pages dans la matinée', $at('2026-09-25 11:00', 40), array(6, 6));
check('commandes créées avec le compteur', is_object($p->getCmd('info', 'pages_jour')), true);
check('éteinte le lendemain : jour à zéro, mois gardé', $at('2026-09-26 09:00', null), array(0, 6));
check('rallumée : ce qui dépasse le compteur de la veille', $at('2026-09-26 10:00', 45), array(5, 11));
check('impression à 23 h 58 comptée le jour même', $at('2026-09-26 23:58', 47), array(7, 13));
check('premier relevé après minuit : ce qui a suivi 23 h 58', $at('2026-09-27 00:03', 48), array(1, 14));
check('nouveau mois', $at('2026-10-01 08:00', 50), array(2, 2));
check('compteur qui recule : période redémarrée, pas de négatif', $at('2026-10-01 09:00', 3), array(0, 0));
check('et repart de là', $at('2026-10-01 10:00', 8), array(5, 5));
$tile = json_decode($p->_published['resume'], true);
check('tuile : pages du jour et du mois', array($tile['today'], $tile['month']), array(5, 5));
brotherbeFake::$clock = null;

section('Relevé accéléré pendant une erreur');

$e = new brotherbeFake();
$e->setConfiguration('ip', '192.168.1.50');
$e->setConfiguration('interval', 5);
$e->createCommands();
brotherbeFake::$answer = $raw['values'];
$e->update();
$e->setCache('polled_at', time() - 70);
check('sans erreur : on attend les cinq minutes', $e->shouldPoll(), false);
$jam = $raw['values'];
$jam[brotherbe::OID_PRINTER_ERRORS] = "\x04";
brotherbeFake::$answer = $jam;
$e->update();
$e->setCache('polled_at', time() - 70);
check('bourrage : relevé dès la minute suivante', $e->shouldPoll(), true);
$e->setCache('polled_at', time() - 20);
check('bourrage : pas deux fois dans la même minute', $e->shouldPoll(), false);
brotherbeFake::$answer = $raw['values'];
$e->update();
$e->setCache('polled_at', time() - 70);
check('bourrage résolu : retour aux cinq minutes', $e->shouldPoll(), false);
brotherbeFake::$answer = $jam;
$e->update();
brotherbeFake::$answer = new Exception('éteinte');
$e->update();
$e->setCache('polled_at', time() - 70);
check('injoignable : plus de relevé à la minute', $e->shouldPoll(), false);

section('Charge utile de la tuile');

$payload = brotherbe::buildResume(array(), array('status' => "l'</script><b>"), array(), true);
check('apostrophe neutralisée', strpos($payload, "'"), false);
check('balise neutralisée', strpos($payload, '<'), false);
check('aucun zéro inventé sans relevé', json_decode($payload, true)['supplies'], array());
check('valeur de tuile stable d\'un relevé à l\'autre', brotherbe::buildResume(array('page_counter' => 3), array(), array(), true), brotherbe::buildResume(array('page_counter' => 3), array(), array(), true));

/* ============================================================== CATALOGUE */

section('Catalogue des commandes');

$ids = array();
$names = array();
$problems = array();
foreach (brotherbe::catalogue() as $key => $definition) {
    if (isset($ids[$definition[0]])) { $problems[] = 'identifiant en double : ' . $definition[0]; }
    if (isset($names[$definition[1]])) { $problems[] = 'nom en double : ' . $definition[1]; }
    if (strpos($definition[1], "'") !== false) { $problems[] = 'apostrophe : ' . $definition[1]; }
    $ids[$definition[0]] = true;
    $names[$definition[1]] = true;
}
$mapped = array_unique(array_merge(array_values(brotherbe::VALUES_COUNTERS), array_values(brotherbe::VALUES_LASER_MAINTENANCE),
    array_values(brotherbe::VALUES_INK_MAINTENANCE), array_values(brotherbe::VALUES_LASER_NEXTCARE)));
foreach ($mapped as $key) {
    if (!isset(brotherbe::catalogue()[$key])) { $problems[] = 'donnée décodée sans commande : ' . $key; }
}
check('catalogue cohérent', $problems, array());

/* ================================================================== BILAN */
echo "\n" . str_repeat('=', 70) . "\n";
printf("%d réussite(s), %d échec(s)\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
