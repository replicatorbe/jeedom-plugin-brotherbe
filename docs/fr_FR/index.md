# Plugin Brother

Surveille en réseau local les imprimantes **Brother**, laser comme jet d'encre,
par SNMP. Aucun cloud, aucun compte, aucune dépendance à installer : le plugin
parle directement à l'imprimante.

## Ce qu'il remonte

Selon ce que votre imprimante déclare :

- **Consommables** : niveau de toner ou d'encre par couleur, durée de vie
  restante du tambour, de la courroie, de l'unité de fusion, de l'unité laser
  et des kits d'alimentation, pages restantes avant leur remplacement,
  remplissage de la boîte de récupération d'encre.
- **Compteurs** : pages imprimées, pages noir et blanc, couleur, recto verso,
  compteurs par couleur, pages du tambour.
- **État** : texte affiché sur l'écran de l'imprimante (« Veille », « Prêt »,
  « Pas de papier »…), état d'impression (au repos, impression, préchauffage),
  état de l'appareil, date du dernier démarrage.
- **Erreurs** : plus de papier, bourrage, capot ouvert, toner bas ou vide, bac
  absent, bac de sortie plein… en clair dans la commande *Erreurs*, et résumées
  par la commande binaire *En erreur*.
- **En ligne** : l'imprimante répond-elle.

Les commandes ne sont créées que pour les données que l'imprimante déclare :
une laser monochrome n'aura jamais de commande « Toner cyan ». Elles
apparaissent au premier relevé réussi.

Les chiffres sont décodés avec les mêmes tables que l'intégration Brother de
Home Assistant : les deux affichent les mêmes valeurs.

## Prérequis

- Une imprimante Brother en réseau (Wi-Fi ou Ethernet), avec **SNMP activé**.
  C'est le réglage d'usine, en lecture avec la communauté `public`. Il se
  vérifie dans l'interface web de l'imprimante, rubrique *Réseau* →
  *Protocole* → *SNMP*.
- Une **adresse IP fixe** pour l'imprimante, réservée dans votre routeur.
- Le port **UDP 161** joignable depuis Jeedom.

## Configuration

Plugins → Monitoring → Brother → **Ajouter une imprimante**.

| Réglage | Rôle |
|---|---|
| Adresse IP | Celle de l'imprimante. |
| Communauté SNMP | `public` par défaut. À changer seulement si vous l'avez modifiée sur l'imprimante. |
| Technologie | *Automatique* lit le nom du modèle : `L` pour le laser (MFC-L, HL-L, DCP-L), `J` ou `T` pour le jet d'encre (MFC-J, DCP-T). Forcez-la si votre modèle échappe à cette règle. |
| Relever toutes les | De la minute à l'heure. Cinq minutes suffisent pour suivre un toner. |

Le bouton **Tester l'adresse** interroge l'imprimante sans rien enregistrer et
affiche son modèle, son numéro de série, son firmware et son adresse MAC. Une
fois l'équipement sauvegardé, les commandes sont créées et le premier relevé
part aussitôt.

Dans la configuration du plugin, le **délai d'attente SNMP** (deux secondes
par défaut, trois tentatives) peut être allongé si l'imprimante tarde à sortir
de veille.

## Le dashboard

Une tuile unique résume l'imprimante : le texte de son écran, une barre par
consommable — rouge sous 10 % —, le nombre de pages, les pages recto verso et
les pages restantes du tambour. Un bandeau rouge affiche les erreurs en cours.
Si l'imprimante ne répond plus, la tuile s'estompe et garde les dernières
valeurs connues.

Deux paramètres d'affichage, dans la configuration avancée de la commande
*Imprimante* :

- `low` : seuil en pour cent sous lequel une barre passe au rouge (10 par
  défaut) ;
- `facts` à `0` : masque les compteurs de pages.

Les autres commandes sont masquées par défaut, mais historisées et utilisables
dans les scénarios. Réaffichez-les dans l'onglet *Commandes* si vous préférez
des widgets séparés.

## Exemples de scénarios

- **Commander du toner à temps** : déclencheur `#[Bureau][Imprimante][Toner noir]#`,
  condition `#[Bureau][Imprimante][Toner noir]# < 15`, action : une notification.
- **Plus de papier** : déclencheur `#[Bureau][Imprimante][Erreurs]#`, condition
  `#[Bureau][Imprimante][Erreurs]# matches "/papier/"`.
- **Imprimante oubliée allumée** : déclencheur programmé à 23 h, condition
  `#[Bureau][Imprimante][En ligne]# == 1`.

## Imprimante éteinte

Une imprimante éteinte ne répond plus : la commande *En ligne* passe à 0, la
tuile l'indique, et **aucune valeur n'est remise à zéro** — un toner à 0 %
déclencherait toutes vos alertes de fin de cartouche. Après trois échecs, les
tentatives s'espacent (de cinq minutes jusqu'à une heure) et le journal ne note
qu'une ligne ; tout revient à la normale au premier relevé réussi.

## Diagnostic

L'onglet *Diagnostic* de l'équipement montre la dernière réponse SNMP de
l'imprimante telle qu'elle l'a envoyée — les blocs propres à Brother en
hexadécimal —, suivie des valeurs décodées. C'est la pièce à joindre à une
demande d'aide si une valeur vous semble fausse ou si votre modèle est mal
reconnu.

## Dépannage

**« L'imprimante ne répond pas en SNMP »** : vérifiez l'adresse, que SNMP est
activé sur l'imprimante et que la communauté est la bonne. Une imprimante
ignore sans rien répondre une communauté qu'elle ne connaît pas : une
communauté fausse et une imprimante éteinte se ressemblent.

**« Répond en SNMP, mais pas comme une imprimante Brother »** : l'adresse est
celle d'un autre appareil — souvent la box.

**Des consommables manquent** : l'imprimante ne les déclare pas. Regardez la
réponse brute dans l'onglet *Diagnostic*.

## Confidentialité

Tout se passe sur votre réseau local. Le plugin ne contacte aucun service
extérieur et n'écrit jamais sur l'imprimante : il ne fait que des lectures SNMP.

Ce plugin n'est pas affilié à Brother.
