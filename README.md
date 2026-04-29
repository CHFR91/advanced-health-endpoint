# advanced-health-endpoint
Advanced Health Endpoint (plugin for Wordpress) (French)

# Advanced Health Endpoint

# INTRODUCTION

Advanced Health Endpoint est une extension pour WordPress uniquement en français.

Advanced Health Endpoint a été réalisé avec l'aide de Claude.

J'ai plusieurs sites personnels qui tournent sur Wordpress. Je ne peux pas rester dessus 24 heures sur 24 pour voir s'ils fonctionnent bien.

J'ai donc décidé d'utiliser le service UptimeRobot ( https://uptimerobot.com/ ) qui vérifie gratuitement tous les 5 mn si un site est fonctionnel ou non. Il est possible de donner la première page de votre site à UptimeRobot ou un "Health Endpoint".

Ici, le "Health Endpoint" est un endroit sur votre site WordPress qui fait un bilan sur la santé de votre site.

Il existe plusieurs extensions sur Wordpress qui ont cette fonctionnalité là, accompagnée d'autres choses.

De mon côté, je voulais une extension simple, basique qui me produit un "Health Endpoint" (et rien de plus) et me donne aussi la possibilité de suivre sur le "tableau de bord" ("dashboard") les informations disponibles au niveau du "endpoint".


# MODE D'EMPLOI :


1/ Pour la partie concernant UptimeRobot ( https://uptimerobot.com/ ), je n'explique rien. Si ça se révèle nécessaire à l'avenir, je m'en occuperai.

2/ Allez sur le site https://github.com/CHFR91/advanced-health-endpoint et téléchargez le zip.

3/ Allez sur votre site Wordpress dans la partie "Extensions/Ajouter une extension". Puis, cliquez sur "Téléverser une extension" et choisissez le zip.
"Installer maintenant".

4/ Allez dans "Outils/Advanced Health".
Votre token s'affiche en haut. Le token est sauvé dans votre site, il a aucune raison de changer sauf si vous cliquez sur "Régénérer le token".
Pour la configuration d'UptimeRobot, vous ajoutez dans le site l'URL suivante : https://VOTRE_SITE/wp-json/ahe/v1/health?token=VOTRE_TOKEN
Cette URL est visible sur la page à côté de "URL".
Si vous utilisez la version payante d'UptimeRobot, utilisez l'URL alternative ou le token est passé dans le header plutôt que dans l'URL en GET.

5/ Allez sur votre tableau de bord pour consulter le bilan de santé de votre site sous le nom "Advanced Health Endpoint".

