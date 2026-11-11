# 🌸 Rendez-vous parents

Petit site PHP (compatible PHP 5.6, hébergement Pages Perso Free) permettant à une
assistante maternelle / nounou de proposer des créneaux de rencontre de 15 minutes
aux parents, via des liens personnels uniques.

## Fonctionnement

### Côté admin (`admin.php`)
- Accès protégé par mot de passe (voir `config.php`).
- Ajout / suppression d'enfants (Prénom + initiale du nom).
- Chaque enfant génère automatiquement un **lien unique** (avec boutons
  « Copier » et « ✉ Email » prêts à envoyer aux parents).
- Ajout de créneaux par date + heure début + heure fin → générés par
  tranches de 15 minutes (heure fin vide = un seul créneau).
- Tableau récapitulatif : créneaux **disponibles**, **réservés** (avec le nom de
  l'enfant) et **passés** (grisés automatiquement).
- Actions : libérer un créneau réservé, supprimer un créneau libre.

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

### Si vous obtenez une erreur 500
Le fichier `.htaccess` protège `config.php`, `db.php`, `helpers.php` et le
dossier `data/`. Si votre hébergement n'accepte pas la directive `Deny from all`,
supprimez le contenu des fichiers `.htaccess` : le site fonctionne quand même
(les fichiers PHP inclus ne sont pas appelables directement).

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
| `admin.php` | Espace admin (enfants, créneaux, récapitulatif) |
| `parent.php` | Page de réservation des parents (accès par lien unique) |
| `db.php` | Connexion base + création automatique des tables |
| `helpers.php` | Fonctions utilitaires (sécurité, dates françaises…) |
| `style.css` | Design rosé |
| `data/` | Base SQLite (créée automatiquement) |
