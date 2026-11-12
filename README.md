# 🌸 Rendez-vous parents

Petit site PHP (compatible PHP 5.6, hébergement Pages Perso Free) permettant à une
assistante maternelle / nounou de proposer des créneaux de rencontre de 15 minutes
aux parents, via des liens personnels uniques.

## Fonctionnement

### Côté admin (`admin.php`)
- Accès protégé par mot de passe (voir `config.php`).
- Ajout / suppression d'enfants (Prénom + initiale du nom).
- Chaque enfant génère automatiquement un **lien unique** (avec boutons
  « Copier » et « Brouillon »).
- Ajout de créneaux par date + heure début + heure fin → générés par
  tranches de 15 minutes (heure fin vide = un seul créneau).
- Tableau récapitulatif : créneaux **disponibles**, **réservés** (avec le nom de
  l'enfant) et **passés** (grisés automatiquement).
- Actions : libérer un créneau réservé, supprimer un créneau libre.

### Emails des parents
- **Carnet d'adresses** : ajout d'adresses une par une, ou **import d'une liste
  complète en collant** un texte (une adresse par ligne, formats acceptés :
  `Marie <marie@exemple.fr>`, `Marie; marie@exemple.fr`, `marie@exemple.fr`).
- Association d'une adresse à chaque enfant via un **menu déroulant**.
- Bouton **« ✉ Envoyer »** par enfant : envoie le lien personnel par email
  (fonction `mail()` de PHP, disponible sur les Pages Perso Free).
- Bouton **« ✉ Envoyer les liens à tous »** : envoie individuellement un email
  personnalisé (avec le lien unique de chaque enfant) à toutes les familles
  qui ont une adresse enregistrée.
- Indiquez votre adresse @free.fr comme **expéditeur** (From) dans Réglages
  pour une meilleure délivrabilité, et votre adresse professionnelle (ex.
  académique) comme **adresse de réponse** (Reply-To) : les réponses des
  parents arriveront dans votre boîte académique.

### Réglages
- **Titre du site** personnalisable.
- **Thème de couleurs** (rose, bleu, vert, violet, orange, turquoise) ou
  **couleur principale personnalisée** — appliqué aussi à la page parents.
- **Adresse expéditrice** des emails.
- **Nouvelle session** : boutons pour effacer tous les créneaux (les enfants et
  leurs liens sont conservés) ou effacer aussi tous les enfants.

### Côté parents (`parent.php?token=xxxx`)
- Le parent ouvre son lien personnel : il voit les créneaux disponibles à venir.
- Il réserve un créneau → il devient **bloqué pour tous les autres parents**.
- En revisitant le même lien, il retrouve **la date et l'heure de son rendez-vous**.
- Un bouton « J'ai un empêchement » libère le créneau (il peut ensuite en réserver
  un autre).
- Un seul rendez-vous possible par lien/famille.

## Installation sur Pages Perso Free

1. **Activez PHP** sur votre compte si ce n'est pas déjà fait (console de gestion
   Free → « Pages Perso »). Le site fonctionne avec PHP 5.6 comme avec les
   versions plus récentes.
2. Copiez **tous les fichiers** du dossier (y compris `.htaccess` et le dossier
   `data/`) à la racine de votre site ou dans un sous-dossier (ex. `/rendezvous`).
3. Éditez `config.php` :
   - changez `ADMIN_PASSWORD` (impératif) ;
   - choisissez le stockage :
     - **SQLite (par défaut)** : rien à faire, la base se crée toute seule dans
       `data/rendezvous.sqlite`. C'est le mode le plus simple.
     - **MySQL** : créez une base dans votre console Free, puis mettez
       `DB_DRIVER` à `'mysql'` et renseignez `MYSQL_DB`, `MYSQL_USER`,
       `MYSQL_PASS` (l'hôte est généralement `sql.free.fr`, le nom de la base
       correspond à votre login Free). Les tables sont créées automatiquement
       au premier chargement.
4. Ouvrez `https://votre-site.free.fr/` (ou `/rendezvous/`) et connectez-vous
   avec votre mot de passe.

### Important : choisir la version de PHP sur Free
Le fichier `.htaccess` fourni active **PHP 8.5** (recommandé par Free pour un
site neuf) via la directive officielle :

    <IfDefine Free>
    php85 1
    </IfDefine>

Sans `.htaccess` (ou sans cette directive), Free utilise PHP 4.4 et le site
affiche une erreur du type « Parse error: unexpected T_OBJECT_OPERATOR ».

Le code est également compatible PHP 5.6 : s'il vous faut cette version,
remplacez simplement `php85 1` par `php56 1` dans le `.htaccess` (une seule
directive `phpXX` doit être présente).

### Protection du dossier data/
Le dossier `data/` contient un `.htaccess` qui interdit l'accès direct à la base
SQLite depuis le navigateur. Directement accéder à `config.php`, `db.php` ou
`helpers.php` n'est pas un problème : ces fichiers n'affichent rien.

## Utilisation type

1. Ajoutez un enfant → copiez le lien généré.
2. Envoyez le lien aux parents par email (bouton « ✉ Email »).
3. Ajoutez vos créneaux (ex. un mercredi de 14h00 à 16h30 → 10 créneaux).
4. Suivez les réservations dans le tableau récapitulatif ; les créneaux passés
   sont grisés automatiquement.

## Fichiers

| Fichier | Rôle |
|---|---|
| `config.php` | Mot de passe admin, base de données, adresse du site |
| `admin.php` | Espace admin (enfants, créneaux, récapitulatif, emails, réglages) |
| `parent.php` | Page de réservation des parents (accès par lien unique) |
| `db.php` | Connexion base + création automatique des tables |
| `helpers.php` | Fonctions utilitaires (sécurité, dates françaises, emails…) |
| `style.css` | Design (thème de couleurs paramétrable) |
| `data/` | Base SQLite (créée automatiquement, accès web bloqué) |
| `sessions/` | Sessions PHP (créées automatiquement, accès web bloqué) |

## Données personnelles (RGPD) et sécurité

- Les emails des parents sont stockés **uniquement dans la base de données de
  l'hébergement** (chez Free), jamais chez un service tiers.
- Le dossier `data/` (base SQLite) est **bloqué au web** par un `.htaccess` :
  la base n'est pas téléchargeable depuis un navigateur.
- Les adresses email **ne sont jamais affichées sur la page parents** : elles
  ne sont visibles que dans l'espace admin, protégé par mot de passe.
- Les pages parents ne montrent que le prénom + initiale de l'enfant.
- Recommandations :
  - choisir un **mot de passe admin long et unique** ;
  - accéder à l'admin en **HTTPS** (`https://…free.fr`) ;
  - utiliser les boutons « Effacer » (section Réglages) pour **purger les
    données en fin d'année scolaire** (les emails, enfants et créneaux) ;
  - ne stocker que le nécessaire : prénom + initiale et adresse email.
- Alternative « zéro stockage » : si vous préférez ne stocker aucune adresse,
  n'utilisez pas le carnet d'adresses et envoyez les liens avec les boutons
  « Copier » / « Brouillon » depuis votre propre logiciel de messagerie.
