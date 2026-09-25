# Plugin Jeedom — Brother

Surveille en réseau local les imprimantes **Brother**, laser comme jet
d'encre, par **SNMP** : sans cloud, sans compte, sans dépendance.

## Ce qu'il remonte

- **Consommables** : toner ou encre par couleur, tambour, courroie, unité de
  fusion, unité laser, kits d'alimentation, pages restantes avant entretien.
- **Compteurs** : pages totales, noir et blanc, couleur, recto verso.
- **État** : texte de l'écran, état d'impression, erreurs en clair (papier,
  bourrage, capot ouvert…), présence en ligne.

Seules les données que l'imprimante déclare donnent une commande. Une tuile
résume le tout sur le dashboard ; chaque valeur est historisée et utilisable
dans les scénarios.

## Installation

Plugins → Gestion des plugins → Ajouter → Github, puis
`replicatorbe / jeedom-plugin-brotherbe`, branche `master` pour le canal
stable ou `beta` pour la version de développement.

Ensuite : Plugins → Monitoring → Brother → **Ajouter une imprimante**, et
saisissez son adresse IP.

La documentation complète est dans [`docs/fr_FR/index.md`](docs/fr_FR/index.md).

## Développement

```bash
php tests/run.php            # rejeu hors ligne sur des réponses SNMP réelles
php tests/check-classes.php  # contrôles par réflexion contre le coeur installé
```

Les fixtures de `tests/fixtures/` sont des paquets de réponse SNMP enregistrés
sur de vraies imprimantes, en hexadécimal. Toute valeur douteuse rencontrée en
production mérite d'y laisser un fichier.

## Licence

AGPL v3. Ce plugin n'est affilié ni à Brother, ni à Home Assistant.

Les tables de décodage des blocs SNMP propres à Brother proviennent de la
bibliothèque Python [`bieniu/brother`](https://github.com/bieniu/brother)
(licence Apache 2.0), utilisée par l'intégration Brother de Home Assistant.
