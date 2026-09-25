# Changelog

## 0.2

- Seuils d'alerte posés d'office sur les consommables : warning sous 20 % et
  danger sous 10 % pour le toner et l'encre, warning sous 10 % et danger sous
  5 % pour le tambour et les autres pièces d'usure. Les alertes natives de
  Jeedom préviennent sans scénario. Les commandes existantes les reçoivent une
  seule fois, et un seuil déjà réglé à la main n'est jamais écrasé.
- Nouvelles commandes « Pages du jour » et « Pages du mois », remises à zéro à
  minuit et le 1er du mois, même si l'imprimante est éteinte. La tuile les
  affiche à la place du recto verso.
- Relevé chaque minute tant qu'une erreur est signalée (papier, bourrage…) :
  elle disparaît du dashboard dès qu'on l'a réglée. Retour à l'intervalle
  choisi ensuite.

## 0.1

Première version.

- Relevé local par SNMP v2c, avec un client SNMP intégré en PHP : aucune
  dépendance, pas même l'extension php-snmp.
- Laser et jet d'encre, monochrome et couleur, format récent et ancien du bloc
  de maintenance Brother ; technologie détectée d'après le nom du modèle.
- Toner ou encre par couleur, tambour, courroie, unité de fusion, unité laser,
  kits d'alimentation, pages restantes avant entretien.
- Compteurs de pages : total, noir et blanc, couleur, recto verso, par couleur.
- Texte de l'écran, état d'impression, état de l'appareil, erreurs en clair,
  dernier démarrage, présence en ligne.
- Commandes créées seulement pour les données que l'imprimante déclare.
- Tuile de dashboard : barres de consommables, compteurs, bandeau d'erreur.
- Bouton « Tester l'adresse » et onglet Diagnostic avec la réponse brute.
- Imprimante éteinte : dernières valeurs conservées, tentatives espacées.
