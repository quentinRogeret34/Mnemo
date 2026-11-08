# Spécification : catalogue des côtes

> Document de démonstration. Contenu fictif, généré pour illustrer l'outil.

## Contexte

Le catalogue recense les côtes franchissables à vélo ou en course à pied autour d'une zone. Il est reconstruit à partir des traces GPS importées par les utilisateurs, puis enrichi par la communauté (notes, commentaires).

## Détection d'une côte

Une portion de trace devient une côte candidate quand elle respecte **les trois seuils** suivants :

| Seuil | Valeur | Remarque |
|---|---|---|
| Pente moyenne | ≥ 4 % | calculée sur la portion entière |
| Dénivelé positif | ≥ 40 m | après lissage de l'altitude |
| Longueur | ≥ 300 m | les rampes plus courtes sont ignorées |

Les seuils sont centralisés pour être ajustés sans redéploiement du moteur de détection.

## Fusion des traces

Deux traces qui décrivent la même montée doivent produire **une seule côte** dans le catalogue. Elles sont fusionnées quand :

- leurs points de départ sont à moins de 50 m l'un de l'autre ;
- leurs points d'arrivée sont à moins de 50 m l'un de l'autre ;
- l'écart de dénivelé est inférieur à 10 %.

La côte fusionnée conserve la trace la plus récente comme référence.

## Hors périmètre

- Les descentes ne sont pas cataloguées.
- Les segments Strava ne sont pas importés tels quels.
