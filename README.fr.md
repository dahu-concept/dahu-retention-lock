# Dahu Retention Lock

Empêche WooCommerce de supprimer votre historique de commandes à retardement.

WooCommerce sait anonymiser des commandes et les mettre à la corbeille tout
seul, après un délai saisi dans **Réglages → Comptes et confidentialité →
Conservation des données personnelles**. Six champs, une tâche quotidienne, et
aucune trace de ce qui a été pris.

C'est une bonne fonctionnalité pour qui la choisit. C'en est une redoutable
pour qui l'ignore : un délai saisi une fois anonymise ensuite des années
d'historique, en silence — et le SAV hors garantie, la comptabilité et la
relation client partent avec. Rien ne se défait, et rien ne vous dit que c'est
arrivé.

Cette extension met ces six champs sous clé. **Elle ne supprime jamais rien
elle-même. Elle empêche les suppressions.**

Sous licence MIT.

*La version anglaise de ce document est dans `README.md`. En cas d'écart, c'est
elle qui fait foi : c'est elle qui part sur wordpress.org.*

---

## Ce qu'elle fait

Trois couches, et elles ne se valent pas.

**1. Lecture.** Les six options de conservation sont lues comme vides. Quelle
que soit la valeur en base, WooCommerce ne voit aucun délai et ne supprime
rien.

**2. Écriture.** Une tentative d'enregistrer un délai est refusée, inscrite, et
annoncée à l'écran où elle a été saisie. Un réglage bloqué en silence est un
réglage qu'on retrouve des années plus tard sans comprendre.

**3. Tâche.** La tâche quotidienne de WooCommerce est décrochée et
déprogrammée.

**C'est la couche 1 qui protège.** Les deux autres sont de la visibilité et du
rangement. Ce n'est pas une supposition, c'est mesuré : la tâche de WooCommerce
empile six sous-tâches, et chacune relit sa propre option avant d'agir — donc
les sous-tâches déjà en file au moment où le verrou se ferme lisent du vide et
s'arrêtent d'elles-mêmes.

### Les six

| Réglage | Ce qu'il supprime |
|---|---|
| Commandes en attente de paiement | Corbeille |
| Commandes échouées | Corbeille |
| Commandes annulées | Corbeille |
| **Commandes remboursées** | Anonymisées |
| Commandes terminées | Anonymisées |
| Comptes clients inactifs | Supprimés |

Six, et non cinq. Le champ des commandes remboursées est au milieu d'un écran
long et c'est celui qu'on rate — or un verrou qui le rate laisse les commandes
remboursées s'anonymiser tout en s'annonçant fermé.

La liste est confrontée à WooCommerce à l'exécution. Si une version future
ajoute un réglage de conservation que l'extension ne connaît pas, l'écran de
réglages le nomme au lieu de le laisser passer en silence.

---

## Ouvert par défaut

Poser un verrou sur une fonctionnalité du cœur est un acte conscient. Activer
l'extension ne change rien tant que le verrou n'est pas fermé.

---

## Ce que montre l'écran de réglages

**WooCommerce → Verrou de conservation.** Nécessite `manage_woocommerce`.

- l'interrupteur ;
- **ce qui est réellement stocké**, lu directement en base par-delà le verrou,
  à côté de ce que WooCommerce lit actuellement. Un verrou dont on ne peut pas
  vérifier l'effet n'inspire confiance à personne ;
- si le rappel de nettoyage est encore accroché, et si la tâche est encore
  programmée, sur chacun des deux ordonnanceurs ;
- le relevé de chaque délai refusé : quand, quoi, et par qui.

Si des délais sont stockés pendant que le verrou est fermé, l'écran dit combien,
avertit que l'ouvrir — ou désactiver l'extension — les remettra en vigueur dès
la requête suivante, et donne un bouton pour les vider.

**Ce bouton n'est pas un confort, c'est la sortie.** Tant que le verrou est
fermé, le champ de WooCommerce s'affiche vide (il lit la même valeur filtrée)
et un enregistrement là-bas est jeté comme un non-changement, parce que
`update_option()` compare la valeur soumise à une « valeur d'avant » qu'il a lue
elle aussi à travers le verrou. L'écran qui a l'air d'être l'endroit où retirer
un délai est donc le seul endroit où c'est impossible. Le bouton les retire, et
ne touche à rien d'autre.

---

## Deux ordonnanceurs

WooCommerce programme son nettoyage par **Action Scheduler** sur les versions
récentes, et par WP-Cron sur les anciennes. `wp_clear_scheduled_hook()` ne
trouve rien du tout sur une tâche Action Scheduler — et annonce une réussite.
Cette extension vérifie et nettoie les deux, et vous montre les deux.

Décrocher le rappel est ce qui arrête le travail ; déprogrammer est du
rangement, et ça écrit en base, donc ça se refuse :

```php
add_filter( 'dahu_retention_lock_unschedule', '__return_false' );
```

Le rappel est retiré par son nom, pas avec `remove_all_actions()`. Tout ce qui
est accroché là est du nettoyage de données personnelles par définition, donc
la version brutale serait probablement sans danger — « probablement sans
danger » n'est pas une raison de retirer le rappel de quelqu'un d'autre d'un
crochet qui lui appartient.

---

## Ce qu'elle ne couvre pas

**Les demandes d'effacement.** L'outil *Outils → Effacer les données
personnelles* de WordPress, et l'action groupée « Supprimer les données
personnelles » de WooCommerce, sont explicites, ponctuelles et déclenchées par
quelqu'un. Ce n'est pas l'objet de cette extension : quelqu'un les a choisies,
et la loi peut les exiger.

Ce que cette extension arrête, c'est la suppression **automatique, récurrente
et silencieuse**.

---

## Installation

Déposer le dossier dans `wp-content/plugins/`, activer, puis fermer le verrou
dans **WooCommerce → Verrou de conservation**.

Nécessite WordPress 6.0, PHP 7.4, WooCommerce 7.0.

---

## Désinstallation

Deux options partent : l'interrupteur et le relevé.

**Les délais stockés par WooCommerce ne sont pas touchés.** Ce sont les
réglages de la boutique, pas ceux de cette extension, et elle ne les a jamais
écrits. Les vider en partant serait une suppression faite par une extension
dont tout le propos est d'empêcher les suppressions que personne n'a demandées.

Ce qui veut dire que désinstaller remet tout délai stocké en vigueur. L'écran
de réglages le dit, avec ces délais sous les yeux, tant que l'extension est
encore là pour le dire.

---

## Licence

MIT. Voir `LICENSE`.

Hugo Vial-Jaime — Dahu-Concept — <contact@dahu-concept.fr>
