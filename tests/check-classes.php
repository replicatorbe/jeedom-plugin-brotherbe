<?php
/* Contrôles par réflexion contre le coeur de Jeedom installé.
 *
 *   php tests/check-classes.php
 *
 * Ces trois pièges ont ceci de commun qu'ils sont invisibles à la relecture,
 * invisibles à « php -l », et invisibles au jeu d'essai hors ligne : ils ne se
 * manifestent que dans un vrai Jeedom, et leur symptôme ne ressemble pas à leur
 * cause. Tous trois se vérifient en quelques lignes de réflexion. */

$core = '/var/www/html/core/php/core.inc.php';
if (!is_readable($core)) {
    echo "Jeedom introuvable : contrôle ignoré.\n";
    exit(0);
}
require_once $core;

$file = __DIR__ . '/../core/class/brotherbe.class.php';
$source = file_get_contents($file);
$problems = array();

/* ------------------------------------------------------------------ 1 ---
 * Toute propriété d'une classe eqLogic ou cmd doit commencer par un souligné.
 * DB::save() traite les autres comme des colonnes de la table : une propriété
 * « $refreshError » fait échouer la création d'un équipement sur « Unknown
 * column », sans que le journal du plugin en dise un mot. */
preg_match_all('/^\s*(?:private|protected|public)\s+(?!static|function)\$(\w+)/m', $source, $m);
foreach ($m[1] as $name) {
    if (strpos($name, '_') !== 0) {
        $problems[] = 'Propriété sans souligné initial : $' . $name
            . ' — DB::save() la prendra pour une colonne de la table.';
    }
}

/* ------------------------------------------------------------------ 2 ---
 * Aucune méthode ne doit s'appeler « set » suivi d'une clé du formulaire, et
 * surtout pas setCmd(). À l'enregistrement, utils::a2o() appelle « set » + clé
 * pour chaque clé reçue, et la page envoie toujours une clé « cmd » : une
 * méthode privée de ce nom tue la sauvegarde sur une erreur fatale, avant toute
 * écriture. La page se rafraîchit, la saisie disparaît, le journal reste muet. */
$forbidden = array('setId', 'setName', 'setLogicalId', 'setGeneric_type', 'setObject_id',
                   'setEqType_name', 'setIsVisible', 'setIsEnable', 'setConfiguration',
                   'setTimeout', 'setCategory', 'setDisplay', 'setOrder', 'setComment',
                   'setTags', 'setCmd');
foreach ($forbidden as $name) {
    if (preg_match('/function\s+' . $name . '\s*\(/i', $source)) {
        $problems[] = 'Méthode interdite : ' . $name . '() — utils::a2o() l\'appellera à '
            . 'chaque enregistrement et tuera la sauvegarde.';
    }
}

/* ------------------------------------------------------------------ 3 ---
 * Une méthode héritée ne peut pas voir sa visibilité réduite. eqLogic et cmd
 * exposent publiquement getCache(), setCache(), getStatus(), setStatus() et bien
 * d'autres : les redéclarer en privé est une erreur fatale AU CHARGEMENT de la
 * classe. Or le coeur charge la classe de chaque plugin actif sur chaque page —
 * toute l'interface de Jeedom tombe alors en HTTP 500, pas seulement le plugin.
 * C'est arrivé, sur getCache() et setCache(). */
preg_match_all('/^\s*(private|protected|public)\s+(?:static\s+)?function\s+(\w+)/m', $source, $m, PREG_SET_ORDER);
$rank = array('private' => 0, 'protected' => 1, 'public' => 2);
foreach (array('eqLogic', 'cmd') as $parent) {
    $ref = new ReflectionClass($parent);
    foreach ($m as $declaration) {
        $visibility = $declaration[1];
        $name = $declaration[2];
        if (!$ref->hasMethod($name)) {
            continue;
        }
        $inherited = $ref->getMethod($name);
        $parentVisibility = $inherited->isPrivate() ? 'private'
            : ($inherited->isProtected() ? 'protected' : 'public');
        if ($rank[$visibility] < $rank[$parentVisibility]) {
            $problems[] = 'Visibilité réduite sur une méthode héritée : ' . $name . '() est '
                . $visibility . ' ici et ' . $parentVisibility . ' dans ' . $parent
                . ' — erreur fatale au chargement, Jeedom entier en HTTP 500.';
        }
    }
}

/* ------------------------------------------------------------------ 5 ---
 * Même punition si une méthode héritée change de nature : déclarée statique
 * ici et d'instance dans eqLogic, ou l'inverse. Erreur fatale au chargement,
 * donc Jeedom entier en HTTP 500. Des noms aussi banals que update(), query()
 * ou decode() rendent le risque bien réel. */
preg_match_all('/^\s*(?:private|protected|public)\s+(static\s+)?function\s+(\w+)/m', $source, $m, PREG_SET_ORDER);
foreach (array('eqLogic', 'cmd') as $parent) {
    $ref = new ReflectionClass($parent);
    foreach ($m as $declaration) {
        $name = $declaration[2];
        if (!$ref->hasMethod($name)) {
            continue;
        }
        $isStatic = trim($declaration[1]) !== '';
        if ($isStatic !== $ref->getMethod($name)->isStatic()) {
            $problems[] = $name . '() est ' . ($isStatic ? 'statique' : 'd\'instance') . ' ici et '
                . ($isStatic ? 'd\'instance' : 'statique') . ' dans ' . $parent
                . ' — erreur fatale au chargement, Jeedom entier en HTTP 500.';
        }
    }
}

/* ------------------------------------------------------------------ 4 ---
 * La classe de commande est obligatoire, même vide : core/ajax/eqLogic.ajax.php
 * refuse de créer ou d'ouvrir un équipement si elle manque. */
if (!preg_match('/class\s+brotherbeCmd\s+extends\s+cmd/', $source)) {
    $problems[] = 'Classe brotherbeCmd absente — impossible de créer un équipement.';
}

/* ---------------------------------------------------------------- BILAN --- */
if (empty($problems)) {
    echo "Contrôles du coeur : aucun problème.\n";
    exit(0);
}
echo "Contrôles du coeur : " . count($problems) . " problème(s)\n";
foreach ($problems as $problem) {
    echo '  - ' . $problem . "\n";
}
exit(1);
