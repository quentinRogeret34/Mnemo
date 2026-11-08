# Génération de parcours

> Document de démonstration. Contenu fictif, généré pour illustrer l'outil.

## Principe

L'utilisateur choisit un point de départ, une distance et un dénivelé cible. Le générateur propose jusqu'à trois boucles qui passent par des côtes du catalogue.

## Contraintes de charge

La génération est coûteuse (calcul d'itinéraire et d'altimétrie). Pour protéger le service :

- un utilisateur n'a **qu'une génération en cours** à la fois ; une nouvelle demande pendant un calcul est refusée ;
- une génération qui dépasse 60 secondes est abandonnée et signalée comme échouée.

## Résultat

Chaque boucle proposée indique sa distance, son dénivelé positif et la liste des côtes traversées. L'utilisateur peut la publier dans la communauté (voir *Charte de la communauté*).
