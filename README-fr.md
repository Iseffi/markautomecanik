# Webmecanik marketing automation pour Dolibarr (MarkAutoMecanik)

*[English version](README.md)*

**MarkAutoMecanik** connecte [Dolibarr ERP & CRM](https://www.dolibarr.org) à
[Webmecanik Automation](https://www.webmecanik.com) (plateforme de marketing automation basée sur Mautic).
Les contacts devenus *mûrs* dans Webmecanik (seuil de points, segment optionnel) sont importés dans
Dolibarr en **leads marketing**, qualifiés par vos équipes, affectés aux commerciaux puis convertis en
tiers, contacts et opportunités. Le statut du lead peut être renvoyé à Webmecanik sous forme de tag
pour boucler la boucle marketing / ventes.

## Fonctionnalités

- **Import des contacts qualifiés** depuis Webmecanik Automation
  - tâche planifiée horaire, incrémentale (contacts modifiés depuis la dernière synchronisation réussie) ;
  - seuil de points et filtre optionnel par segment ;
  - reprise des champs standards (nom, email, téléphone, société, ville, pays, langue, site web),
    des tags UTM et de trois champs personnalisés (message, centres d'intérêt, secteur) ;
  - dédoublonnage par email / identifiant Webmecanik : un lead ouvert est mis à jour au lieu d'être dupliqué,
    un lead clôturé n'est recréé qu'en cas de nouvelle activité, un lead non valide n'est jamais recréé ;
  - **webhook temps réel** optionnel, signé HMAC-SHA256.
- **Qualification** : à traiter → affecté → converti / perdu / non valide / archivé, motif de perte
  obligatoire, notes privées, affectation au commercial (email et événement agenda).
- **Conversion** : liaison à un tiers existant (le commercial affecté y est ajouté), création du tiers,
  du contact et d'une opportunité (projet) préremplis depuis le lead.
- **Boucle fermée** : statut renvoyé à Webmecanik sous forme de tags (`dolibarr-converted`, `dolibarr-lost`…).
- **Relances** : email quotidien aux commerciaux pour les leads en attente au-delà du délai de traitement,
  récapitulatif des leads non affectés en retard à une adresse partagée.
- **RGPD** : anonymisation en un clic (le contact n'est ensuite plus jamais importé) et purge planifiée
  des leads non convertis après la durée de conservation.
- **Widget** de tableau de bord, **statistiques** (par mois, statut, commercial, taux de conversion)
  et **historique des synchronisations**.
- **API REST** (`/api/index.php/markautomecanik/leads`) en lecture et création.
- Compatible multi-société, traductions française et anglaise.

## Prérequis

| Composant | Version |
|-----------|---------|
| Dolibarr  | 19.0 ou supérieure (testé avec 19, 21, 22, 23 et 24) |
| PHP       | 7.4 ou supérieure (testé jusqu'à 8.5 ; 7.4 et 8.0 ne sont plus maintenues par PHP, utilisez 8.2 ou plus), extensions `curl`, `json` et `openssl` |
| Base de données | MySQL / MariaDB |
| Webmecanik Automation | API activée (Paramètres > Configuration > Paramètres de l'API) |

## Installation

```sh
cd /chemin/vers/dolibarr/htdocs/custom
git clone https://github.com/iseffi/markautomecanik.git markautomecanik
```

ou déposez l'archive `module_markautomecanik-x.y.z.zip` via *Accueil > Configuration > Modules >
Déployer/installer un module externe*. Le répertoire **doit** s'appeler `markautomecanik`.

Activez ensuite **Webmecanik marketing automation** dans *Accueil > Configuration > Modules* (section CRM).

## Configuration

1. **Dans Webmecanik** : activez l'API ; créez des identifiants OAuth2 (*Paramètres > Identifiants API*,
   recommandé) ou un utilisateur API dédié aux droits limités à *Contacts : voir* (et *modifier* si la
   boucle fermée est activée). Selon la version de Webmecanik, des identifiants OAuth2 *client credentials*
   peuvent ne pas être rattachés à un rôle restreint et donner accès à toute l'API : vérifiez-le avec
   votre administrateur Webmecanik et, à défaut, préférez l'authentification basique avec un utilisateur
   dédié aux droits restreints.
2. **Dans Dolibarr** : renseignez l'URL de l'instance (https uniquement), le mode d'authentification et
   les identifiants, le seuil de points (voir [Périmètre de l'import](#périmètre-de-limport)),
   puis cliquez sur **Tester la connexion** et **Synchroniser maintenant** (fenêtre de 30 jours par
   exemple pour l'import initial).
3. **Tâches planifiées** : activez les tâches « Webmecanik - … » (synchronisation horaire, relances et
   purge RGPD quotidiennes). Elles sont créées désactivées.
4. **Webhook (optionnel)** : cochez *Générer un nouveau secret* (ou collez un secret d'au moins
   32 caractères), activez le webhook, puis déclarez l'URL et le secret affichés dans
   Webmecanik (*Paramètres > Webhooks*), événements « Contact mis à jour » et « Points du contact modifiés ».
5. **Droits** : lecture de ses leads / de tous les leads, qualification, suppression et anonymisation,
   lancement manuel de la synchronisation.

## Périmètre de l'import

Les réglages *Points minimum* et *Alias du segment* déterminent les contacts Webmecanik importés.
Deux configurations types :

| Usage | Points minimum | Alias du segment |
|-------|----------------|------------------|
| Uniquement les leads prêts pour les ventes (par défaut) | votre seuil de lead scoring, par ex. 40 | optionnel |
| Tous les contacts modifiés dans Webmecanik | `0` | vide, ou un segment pour restreindre |

Avec `0`, tous les contacts modifiés dans Webmecanik depuis la dernière synchronisation sont importés,
quel que soit leur score. À savoir :

- le flux va de Webmecanik vers Dolibarr : chaque contact devient un **lead marketing** à traiter,
  pas directement un tiers ou un contact ; Dolibarr ne renvoie que le statut du lead, sous forme de tag ;
- les contacts sans email valide (visiteurs anonymes) et ceux dont le score est négatif ne sont
  jamais importés ;
- un lead existant n'est pas écrasé : seuls ses points et sa date de modification sont mis à jour ;
- chaque synchronisation couvre au plus la *fenêtre maximale de synchronisation* (30 jours par défaut,
  jusqu'à 365) et 20 000 contacts. Pour importer une base plus ancienne ou plus volumineuse, augmentez
  la fenêtre et faites l'import initial en plusieurs passes, ou restreignez-le avec un segment.

## Sécurité

Voir [SECURITY.md](SECURITY.md) : secrets chiffrés en base et jamais réaffichés ni journalisés,
HTTPS imposé avec vérification des certificats et refus des redirections, jeton CSRF et droits vérifiés
sur chaque action, visibilité par lead et isolation multi-société, tiers / contacts / projets liés
affichés selon les droits Dolibarr de l'utilisateur, saisies normalisées et affichage échappé, appels
sortants via le proxy Dolibarr vers des adresses publiques uniquement, webhook signé HMAC et limité en
débit, aucune donnée personnelle dans les journaux (sauf au niveau `LOG_DEBUG`, où Dolibarr journalise
toutes les requêtes SQL).

Prérequis du chiffrement des secrets : extension PHP `openssl` et `$dolibarr_main_instance_unique_id`
renseigné dans `conf.php` (fichier à laisser en lecture seule). Sinon Dolibarr stocke les secrets en
clair et la page de configuration affiche une alerte. Les secrets sont supprimés à la désactivation du
module.

## Mise à jour

Après remplacement des fichiers, désactivez puis réactivez le module pour créer les nouvelles tables
(1.1.0 : liste d'exclusion RGPD et verrous d'import). La désactivation supprime les identifiants
Webmecanik et le secret du webhook : saisissez-les à nouveau dans la configuration.

## Licence

GPL v3 ou ultérieure, voir [COPYING](COPYING).
*Webmecanik* et *Mautic* sont des marques de leurs propriétaires respectifs ; ce projet n'est ni affilié
ni approuvé par Webmecanik ou Dolibarr.
