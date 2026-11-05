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
- **RGPD** : anonymisation en un clic et purge planifiée des leads non convertis après la durée de conservation.
- **Widget** de tableau de bord, **statistiques** (par mois, statut, commercial, taux de conversion)
  et **historique des synchronisations**.
- **API REST** (`/api/index.php/markautomecanik/leads`) en lecture et création.
- Compatible multi-société, traductions française et anglaise.

## Prérequis

| Composant | Version |
|-----------|---------|
| Dolibarr  | 19.0 ou supérieure |
| PHP       | 7.4 à 8.3, extensions `curl`, `json` et `openssl` |
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
   boucle fermée est activée).
2. **Dans Dolibarr** : renseignez l'URL de l'instance (https uniquement), le mode d'authentification et
   les identifiants, le seuil de points, puis cliquez sur **Tester la connexion** et
   **Synchroniser maintenant** (fenêtre de 30 jours par exemple pour l'import initial).
3. **Tâches planifiées** : activez les tâches « Webmecanik - … » (synchronisation horaire, relances et
   purge RGPD quotidiennes). Elles sont créées désactivées.
4. **Webhook (optionnel)** : collez le secret, activez le webhook, puis déclarez l'URL affichée dans
   Webmecanik (*Paramètres > Webhooks*), événements « Contact mis à jour » et « Points du contact modifiés ».
5. **Droits** : lecture de ses leads / de tous les leads, qualification, suppression et anonymisation,
   lancement manuel de la synchronisation.

## Sécurité

Voir [SECURITY.md](SECURITY.md) : secrets chiffrés en base et jamais réaffichés ni journalisés,
HTTPS imposé avec vérification des certificats et refus des redirections, jeton CSRF et droits vérifiés
sur chaque action, visibilité par lead et isolation multi-société, webhook signé HMAC, aucune donnée
personnelle dans les journaux.

## Licence

GPL v3 ou ultérieure, voir [COPYING](COPYING).
*Webmecanik* et *Mautic* sont des marques de leurs propriétaires respectifs ; ce projet n'est ni affilié
ni approuvé par Webmecanik ou Dolibarr.
