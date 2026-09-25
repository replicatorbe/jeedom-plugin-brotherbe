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
          'pages', 'pages_recto_verso', 'compteur_noir',
          'dernier_demarrage', 'en_ligne', 'rafraichir'));

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
