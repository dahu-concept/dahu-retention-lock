# Dahu Retention Lock — résumé technique

Version 1.0.0. Document interne : ce que l'extension fait, ce qui a été mesuré
dans WooCommerce avant de l'écrire, et ce qui reste à vérifier en boutique.

---

## 1. Ce que c'est

Trois classes, une feuille de style, aucun JavaScript, deux options.

| | |
|---|---|
| Classes | 3 |
| Tables créées | aucune |
| Options | deux (l'interrupteur, le relevé) |
| Écrans | un, plus un bandeau sur celui de WooCommerce |
| Écritures sur les données de la boutique | **aucune** |
| Chaînes traduites | 50 / 50 en français |
| **Assertions au banc** | **58** |
| **Injections délibérées** | **24** |

| Classe | Rôle |
|---|---|
| `Dahu_Retention_Lock_Lock` | Les trois couches, et l'état lisible du verrou. |
| `Dahu_Retention_Lock_Settings` | L'interrupteur, l'écran, le bandeau. |
| `Dahu_Retention_Lock_Log` | Le relevé des refus, borné, dans une option. |

---

## 2. Trois défauts de la version Annad, trouvés en mesurant

La source (`dahu-purge` 1.2.0, troisième module, 219 lignes) a été mesurée
contre WooCommerce 11.1.2 avant d'être portée, et pas recopiée. Trois écarts,
dont un qui laisse une porte ouverte.

### 2.1 Cinq options au lieu de six — une porte ouverte

WooCommerce lit **six** options de conservation. La source en nommait cinq :
`woocommerce_anonymize_refunded_orders` manquait.

Conséquence directe : un verrou qui s'annonce fermé pendant que les commandes
remboursées continuent de s'anonymiser. Le banc le prouve des deux côtés —
verrou ouvert la commande remboursée perd son nom, verrou fermé elle le garde.

La liste n'est plus écrite à la main et laissée à elle-même :
`woocommerce_options()` relit la source de `WC_Privacy` **au tokenizer** et en
extrait les options passées à `get_option()`. `unknown()` en fait la différence
avec la liste de l'extension, et l'écran affiche nommément tout réglage de
conservation qu'une future version ajouterait. Une liste figée qui se périme en
silence est exactement la panne que cette extension existe pour empêcher, un
cran au-dessus.

### 2.2 La déprogrammation ne déprogrammait rien

La source appelle `wp_clear_scheduled_hook( 'woocommerce_cleanup_personal_data' )`.

Mesuré : WooCommerce programme cette tâche par **`as_schedule_recurring_action()`**,
c'est-à-dire **Action Scheduler**. `wp_next_scheduled()` ne trouve rien,
`wp_clear_scheduled_hook()` ne déprogramme rien, et aucun des deux ne le dit.

La version publique traite les deux ordonnanceurs, et l'écran montre l'état des
deux.

### 2.3 Un crochet qui n'en est pas un

La source neutralise `woocommerce_delete_inactive_accounts` comme une tâche
planifiée. Mesuré : zéro rappel accroché. C'est un **nom d'option**, pas un
crochet. Retiré de la liste.

### 2.4 Et deux choix refaits

- **`remove_all_actions()` devient `remove_action()` sur le rappel nommé.** Tout
  ce qui est accroché là est du nettoyage de données personnelles par
  définition, donc la version brutale serait probablement sans danger —
  « probablement sans danger » n'est pas une raison de retirer le rappel de
  quelqu'un d'autre d'un crochet qui lui appartient. Le rappel est trouvé par
  son instance (`$function[0] instanceof WC_Privacy`), parce qu'il a été
  accroché en `array( $this, … )` et qu'un nom de classe ne correspondrait pas.
- **Le refus s'affiche.** La source journalisait et laissait WordPress répondre
  « Réglages enregistrés » à quelqu'un qu'on venait de contredire. Le message
  passe maintenant par `WC_Admin_Settings::add_error()` — mesuré : les écrans de
  réglages de WooCommerce n'emploient pas l'API de réglages de WordPress, donc
  `add_settings_error()` serait mis en file et jamais affiché **sur l'écran
  même où le délai se saisit**.

---

## 3. Pourquoi trois couches, et laquelle protège

La source présentait les trois couches comme « volontairement redondantes ».
En mesurant, elles ne le sont pas, et le dire compte : un lecteur qui croit
qu'une seule suffirait en retirera une.

`WC_Privacy::queue_cleanup_personal_data()` empile **six sous-tâches** dans une
file de traitement en arrière-plan, et chaque sous-tâche relit sa propre option
par `get_option()` avant d'agir. Donc :

- **la couche 1 (lecture) protège**, y compris les sous-tâches déjà en file au
  moment où le verrou se ferme ;
- la couche 2 (écriture) existe pour qu'on sache qu'on a été contredit ;
- la couche 3 (tâche) évite de faire tourner tous les jours un travail dont on
  sait qu'il ne trouvera rien.

Le banc le vérifie : verrou fermé, `WC_Privacy::anonymize_completed_orders()`
appelée directement rend 0 et la commande garde son nom.

### La forme rendue par la couche 1

`array( 'number' => '', 'unit' => 'days' )`, et pas `false` ni `array()`.

Mesuré : les quatre désactivent la fonctionnalité, parce que
`wc_parse_relative_date_option()` fait `(array)` puis remplit les défauts, et
que chaque site d'appel teste ensuite `empty( $option['number'] )`. Mais
l'écran de réglages de WooCommerce et du code tiers peuvent lire
`$option['number']` directement, et la forme explicite est celle qui traverse
ça sans alerte.

---

## 4. Ce qui n'est pas couvert, et pourquoi

Les **demandes d'effacement** : l'outil de WordPress et l'action groupée
« Supprimer les données personnelles » de WooCommerce. Elles sont explicites,
ponctuelles et déclenchées par quelqu'un ; la loi peut les exiger. Les
verrouiller serait transformer un outil de conformité en piège.

Ce qui est arrêté est la suppression **automatique, récurrente et
silencieuse** — la seule des trois dont personne ne se souvient l'avoir
demandée.

---

## 5. Le banc

`_bench/t-retention-lock.php` — **58 assertions**, idempotentes.

L'assertion qui compte n'est pas « le filtre rend un délai vide ». C'est
**« une vraie commande est encore là après que le nettoyage de WooCommerce a
tourné »** : la suite stocke un délai, fabrique des commandes datées, appelle
`WC_Privacy` directement et compte les survivantes — **avec un témoin verrou
ouvert**, sans quoi on ne distingue pas un verrou qui marche d'un nettoyage qui
n'avait de toute façon rien à prendre.

Elle détruit donc des données exprès. Uniquement les siennes : chaque commande
de banc porte un marqueur, et rien sans ce marqueur n'est créé, anonymisé ni
supprimé.

`_bench/prove-retention-lock.py` — **24 injections**, chacune devant faire
tomber l'assertion **nommée**.

### Ce que les injections ont trouvé dans le banc

Quatre assertions creuses, toutes de la même famille — une mesure qui vaut
pareil que la faute soit là ou non :

1. **« aucune option inconnue » se vérifiait sur un diff vide**, qui vaut aussi
   bien « rien ne manque » que « le scan n'a rien lu ». Deux injections sont
   passées au vert là-dessus. Le scan et la différence sont désormais deux
   méthodes, et c'est sur **ce que le scan trouve** que porte l'assertion.
2. **Le filtre `default_option_` se vérifiait sur une option absente**, qui rend
   `false` de toute façon. Mesuré maintenant contre un **défaut dangereux**
   posé exprès, avec un témoin qui montre qu'il arriverait bien jusqu'à
   WooCommerce sans le verrou.
3. **Le garde-fou de `unhook_cleanup()` n'était pas atteignable** par les
   assertions existantes. Il l'est par un appel direct, verrou ouvert — et
   l'ordre des lignes est ce qui le rend possible, parce que décrocher un
   rappel est définitif pour la requête.

Ce dernier point est aussi une leçon de banc : **le témoin de la couche 3 se
prend avant tout verrouillage.** Pris après, il aurait toujours dit « retiré »,
et l'assertion la plus importante de la section n'aurait rien prouvé.

---

## 6. Harnais statiques

Conformité wordpress.org, préfixage, cohérence d'interface, i18n du PHP et des
assets, et le `.mo` chargé par le lecteur de WordPress.

**Faux positif connu** : le contrôle SQL de `test-conformite.php` cherche
`prepare` sur la même ligne que `$wpdb->get_var()`. La seule requête de cette
extension est écrite sur deux lignes et ressort en NOTE. Elle est préparée.

---

## 7. À vérifier en boutique

1. **Fermer le verrou et regarder l'écran de WooCommerce** : le bandeau doit
   apparaître sur Réglages → Comptes et confidentialité.
2. **Saisir un délai et enregistrer** : rien ne doit être stocké, une erreur
   doit s'afficher à côté du formulaire, et la tentative doit apparaître sur
   l'écran de l'extension avec la bonne personne.
3. **L'état des deux ordonnanceurs**, sur une boutique où la tâche tourne
   vraiment. Le banc n'a pas d'Action Scheduler en service.
4. **Une boutique qui a déjà des délais stockés** : le panneau doit les montrer,
   et l'avertissement sur l'ouverture du verrou doit apparaître.
5. **Une version de WooCommerce plus récente** : si elle ajoute un réglage de
   conservation, l'écran doit le nommer dans l'encadré des réglages inconnus.
   C'est le contrôle qui empêche cette extension de se périmer en silence.
6. **WP-CLI** : `wp option update woocommerce_anonymize_completed_orders` doit
   être refusé de la même façon, et inscrit avec « le site » comme auteur.
7. **Désactiver puis réactiver** : rien ne doit avoir bougé côté WooCommerce.
8. **La capture** de la fiche wordpress.org : le `README.txt` en annonce une,
   `.wordpress-org/screenshot-1.png`.

---

## 8. La revue adverse — 9 octobre

Relecture par un agent qui n'avait pas écrit le code, consigne : trouver ce qui
fait perdre des données irrécupérables, ou croire qu'on est protégé sans
l'être. Sept défauts, dont un qui transformait l'extension en piège. Le banc
passait 58/58 pendant ce temps.

### 8.1 Le verrou rendait un délai stocké impossible à retirer

**Le pire défaut de l'extension, et il était de sa propre fabrication.**

`update_option()` lit sa « valeur d'avant » par `get_option()`, donc à travers
le filtre de lecture du verrou : cette valeur est toujours la forme vide. Une
écriture qui **vide** un champ vaut donc exactement cette valeur, et
`update_option()` la jette comme un non-changement. La ligne survit.

Et comme WooCommerce affiche ce champ depuis `get_option()` lui aussi, il
s'affiche **vide** pendant que 36 mois dorment dans la table. La boutique ne
voit donc même pas ce qu'elle n'arrive pas à retirer. Pire : WooCommerce
renvoie les six champs à chaque enregistrement de son onglet, donc le verrou
**préservait** activement un délai qu'un enregistrement ordinaire aurait effacé.

Et l'écran de l'extension disait : « videz-les dans l'écran de WooCommerce
d'abord ». La procédure documentée était impossible.

Corrigé : un bouton **« Vider les délais stockés »** sur l'écran de l'extension,
et le texte dit maintenant où est la sortie. Mesuré des deux côtés par le banc :
le vidage par WooCommerce ne retire rien, le bouton retire.

### 8.2 `add_option()` écrasait la ligne, à cause du verrou

`add_option()` n'a pas de filtre `pre_`. Sa seule garde est de comparer le
défaut filtré à `get_option()` — et un verrou qui filtre **les deux côtés** les
rend égaux, donc la garde passe sur une ligne qui existe et
`INSERT … ON DUPLICATE KEY UPDATE` l'écrase. Mesuré : 3 jours devenus 999 mois,
sans trace.

Corrigé en deux temps : le filtre de défaut ne rend la forme verrouillée que
lorsque la ligne n'existe pas — ce qui rend sa garde à WordPress — et un
`added_option` défait et inscrit ce qui passe encore. Les deux sont nécessaires :
WordPress saute entièrement sa propre garde quand le cache `notoptions` affirme
que l'option n'existe pas.

### 8.3 Les autres

| | Défaut | Correctif |
|---|---|---|
| 3 | Une action Action Scheduler restée « en cours » ne peut pas être annulée (`as_unschedule_all_actions()` ne traite que les actions en attente), donc la boucle écrivait une ligne au relevé **à chaque chargement de page** et dévorait les cinquante emplacements de vrais refus en cinquante requêtes. | Le relevé ne contient plus que des refus. L'état de la tâche se lit sur l'écran, et « en cours » y est distingué de « programmée ». |
| 4 | `cleanup_hooked()` posait la même question que le retrait, donc ne pouvait jamais le contredire : un rappel que l'extension ne sait pas reconnaître restait accroché et l'écran affichait « retiré » en vert. | `has_action()` dit ce qui est accroché ; une seconde méthode dit si c'est celui de WooCommerce. |
| 5 | Le détecteur de dérive cherchait une seule écriture de `get_option` : trois des quatre façons raisonnables de l'écrire étaient ratées, en silence. Et il ne distinguait pas « rien ne manque » de « je n'ai rien lu ». | Lecture au tokenizer, et l'écran dit quand le code de WooCommerce n'a pas pu être lu. |
| 6 | Désactiver l'extension réarmait tout délai stocké, et rien ne le disait là où on désactive. | Une ligne d'avertissement sous l'extension, sur l'écran des extensions. |
| 7 | `options.php` exige `manage_options` : un `shop_manager` voyait tout l'écran et se faisait refuser l'enregistrement. | `option_page_capability_` sur le groupe. |
| — | `wp_clear_scheduled_hook()` ne correspond qu'à l'événement sans argument ; `get_sites()` s'arrêtait aux cent premiers sites. | `wp_unschedule_hook()`, `number => 0`. |

### 8.4 Et ce que le banc a appris

**Le banc testait une transcription du scanner, pas le scanner.** L'assertion
sur les quatre écritures de `get_option` recopiait la boucle dans le fichier de
test : elle serait restée verte pendant que le vrai scanner se faisait réduire
à une seule écriture. La boucle est désormais une méthode publique que le banc
appelle, pointée sur un fichier d'essai.

Et deux pièges de banc, consignés parce qu'ils reviendront : une insertion faite
au `$wpdb` doit vider le cache `notoptions`, sans quoi `add_option()` saute sa
garde et le banc mesure un trou qui n'est que le sien ; et un fichier de test
qui laisse un interrupteur en base fait mesurer à la passe suivante l'état
laissé par la précédente — l'interrupteur se rend donc dans un
`register_shutdown_function`, pas dans la section de nettoyage.

**58 → 79 assertions, 24 → 31 injections.**